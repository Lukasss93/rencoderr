<?php

use App\Jobs\ProbeMediaFile;
use App\Library\Actions\RequestLibraryScan;
use App\Models\LibraryPath;
use App\Models\MediaFile;
use App\Models\MediaProbe;
use App\Models\MediaStream;
use App\Probe\Actions\RequestProbe;
use App\Tasks\Enums\TaskStatus;
use FFMpeg\Driver\FFProbeDriver;
use FFMpeg\Exception\ExecutableNotFoundException;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;

function pendingProbeFile(): MediaFile
{
    Queue::fake([ProbeMediaFile::class]);
    $root = LibraryPath::factory()->atPath(mediaDirectory(['movie.mkv' => 'video']))->create();
    scanMediaPath($root);

    return $root->mediaFiles()->sole();
}

function queuedProbeJob(MediaFile $file): ProbeMediaFile
{
    return new ProbeMediaFile($file->id, (string) $file->probe()->sole()->request_token);
}

it('queues discovered files only after the folder scan succeeds', function (): void {
    Queue::fake([ProbeMediaFile::class]);
    $root = LibraryPath::factory()->atPath(mediaDirectory(['movie.mkv' => 'video']))->create();

    scanMediaPath($root);

    $file = $root->mediaFiles()->sole();
    expect($file->probe)->not->toBeNull();
    expect($file->probe()->sole()->status)->toBe(TaskStatus::QUEUED);
    Queue::assertPushed(ProbeMediaFile::class, fn (ProbeMediaFile $job): bool => $job->fileId === $file->id && $job->queue === 'probes');
});

it('recognizes fresh metadata and removes probe records when their file is deleted', function (): void {
    $file = MediaFile::factory()->create();
    $probe = MediaProbe::factory()->for($file)->create(['status' => TaskStatus::COMPLETED]);
    MediaStream::factory()->for($probe, 'probe')->create(['stream_index' => 0]);

    expect($file->hasCurrentProbe())->toBeTrue();
    $file->update(['size_bytes' => 42]);
    expect($file->refresh()->unsetRelation('probe')->hasCurrentProbe())->toBeFalse();
    $file->delete();

    expect(MediaProbe::query()->count())->toBe(0);
    expect(MediaStream::query()->count())->toBe(0);
});

it('replaces tracks on success and does not rerun an unchanged successful probe', function (): void {
    $file = pendingProbeFile();
    $probe = $file->probe()->sole();
    MediaStream::factory()->for($probe, 'probe')->create(['stream_index' => 9]);
    $driver = Mockery::mock(FFProbeDriver::class);
    $driver->shouldReceive('command')->once()->andReturn('{"format":{"duration":"12.5","format_name":"matroska,webm"},"streams":[{"index":0,"codec_name":"hevc","codec_type":"video","width":1920,"height":1080}]}');
    app()->instance(FFProbeDriver::class, $driver);

    $job = queuedProbeJob($file);
    app()->call([$job, 'handle']);
    app()->call([$job, 'handle']);
    scanMediaPath($file->libraryPath()->firstOrFail());

    expect($file->refresh()->unsetRelation('probe')->hasCurrentProbe())->toBeTrue();
    expect($probe->refresh()->duration_seconds)->toBe('12.500000');
    expect($probe->streams()->pluck('stream_index')->all())->toBe([0]);
    expect($probe->attempts)->toBe(1);
    Queue::assertPushed(ProbeMediaFile::class, 1);
});

it('discards a probe if the file changes while ffprobe is running', function (): void {
    $file = pendingProbeFile();
    $driver = Mockery::mock(FFProbeDriver::class);
    $driver->shouldReceive('command')->once()->andReturnUsing(function () use ($file): string {
        File::put($file->libraryPath()->firstOrFail()->path.'/movie.mkv', 'a longer video');

        return '{"format":{},"streams":[{"index":0}]}';
    });
    app()->instance(FFProbeDriver::class, $driver);

    app()->call([queuedProbeJob($file), 'handle']);

    expect($file->probe()->sole()->status)->toBe(TaskStatus::PENDING);
    expect(MediaStream::query()->count())->toBe(0);
});

it('retries transient errors three times and leaves unchanged failures alone', function (): void {
    $file = pendingProbeFile();
    $driver = Mockery::mock(FFProbeDriver::class);
    $driver->shouldReceive('command')->times(3)->andThrow(new RuntimeException('Temporary I/O error.'));
    app()->instance(FFProbeDriver::class, $driver);
    $job = queuedProbeJob($file)->withFakeQueueInteractions();

    for ($attempt = 1; $attempt <= 2; $attempt++) {
        expect(fn () => app()->call([$job, 'handle']))->toThrow(RuntimeException::class, 'Temporary I/O error.');
    }
    app()->call([$job, 'handle']);
    scanMediaPath($file->libraryPath()->firstOrFail());

    expect($file->probe()->sole()->status)->toBe(TaskStatus::FAILED);
    expect($file->probe()->sole()->attempts)->toBe(3);
    Queue::assertPushed(ProbeMediaFile::class, 1);

    expect(app(RequestProbe::class)->retry($file->id))->toBeTrue();
    expect($file->probe()->sole()->attempts)->toBe(0);
});

it('keeps the previous result when a new probe returns malformed metadata', function (): void {
    $file = pendingProbeFile();
    $probe = $file->probe()->sole();
    $probe->update(['metadata' => ['previous' => 'report'], 'probed_at' => now()]);
    MediaStream::factory()->for($probe, 'probe')->create(['codec_name' => 'h264']);
    $driver = Mockery::mock(FFProbeDriver::class);
    $driver->shouldReceive('command')->once()->andReturn('{broken');
    app()->instance(FFProbeDriver::class, $driver);

    app()->call([queuedProbeJob($file)->withFakeQueueInteractions(), 'handle']);

    expect($probe->refresh()->status)->toBe(TaskStatus::FAILED);
    expect($probe->metadata)->toBe(['previous' => 'report']);
    expect($probe->streams()->sole()->codec_name)->toBe('h264');
    expect($file->refresh()->unsetRelation('probe')->hasCurrentProbe())->toBeFalse();
});

it('starts a new request when the scanned file changes and ignores the old job and failure', function (): void {
    $file = pendingProbeFile();
    $old = queuedProbeJob($file);
    File::put($file->libraryPath()->firstOrFail()->path.'/movie.mkv', 'changed video');

    scanMediaPath($file->libraryPath()->firstOrFail());
    app()->call([$old, 'handle']);
    $old->failed(new RuntimeException('Outdated failure.'));

    $probe = $file->probe()->sole();
    expect($probe->status)->toBe(TaskStatus::QUEUED);
    expect($probe->request_token)->not->toBe($old->token);
    expect($probe->source_size_bytes)->toBe(13);
    expect($probe->attempts)->toBe(0);
    Queue::assertPushed(ProbeMediaFile::class, 2);
});

it('invalidates missing and excluded files and probes them again when restored', function (string $availability): void {
    $file = pendingProbeFile();
    $root = $file->libraryPath()->firstOrFail();
    $path = $root->path.'/movie.mkv';
    $contents = File::get($path);
    $mtime = $file->filesystem_modified_at->getTimestamp();
    $old = queuedProbeJob($file);
    File::put($root->path.'/sidecar.nfo', 'keep the mount nonempty');
    if ($availability === 'missing') {
        File::delete($path);
    }

    scanMediaPath($root, $availability === 'excluded' ? ['movie.mkv'] : []);
    app()->call([$old, 'handle']);
    expect($file->probe()->sole()->status)->toBe(TaskStatus::PENDING);
    expect($file->refresh()->status->value)->toBe($availability);
    Queue::assertPushed(ProbeMediaFile::class, 1);
    File::put($path, $contents);
    touch($path, $mtime);
    scanMediaPath($root);

    expect($file->probe()->sole()->status)->toBe(TaskStatus::QUEUED);
    Queue::assertPushed(ProbeMediaFile::class, 2);
})->with(['missing', 'excluded']);

it('does not start a duplicate probe while another attempt is running', function (): void {
    $file = pendingProbeFile();
    $job = queuedProbeJob($file);
    $driver = Mockery::mock(FFProbeDriver::class);
    $driver->shouldReceive('command')->once()->andReturnUsing(function () use ($job): string {
        app()->call([$job, 'handle']);
        $job->failed(new RuntimeException('A duplicate delivery failed.'));

        return '{"format":{},"streams":[{"index":0}]}';
    });
    app()->instance(FFProbeDriver::class, $driver);

    app()->call([$job, 'handle']);

    expect($file->probe()->sole()->status)->toBe(TaskStatus::COMPLETED);
    expect($file->probe()->sole()->attempts)->toBe(1);
});

it('cannot overwrite a replacement request created during probing', function (): void {
    $file = pendingProbeFile();
    $old = queuedProbeJob($file);
    $driver = Mockery::mock(FFProbeDriver::class);
    $driver->shouldReceive('command')->once()->andReturnUsing(function () use ($file): string {
        File::put($file->libraryPath()->firstOrFail()->path.'/movie.mkv', 'new media content');
        scanMediaPath($file->libraryPath()->firstOrFail());

        return '{"format":{},"streams":[{"index":0}]}';
    });
    app()->instance(FFProbeDriver::class, $driver);

    app()->call([$old, 'handle']);
    $old->failed(new RuntimeException('Old failure.'));

    expect($file->probe()->sole()->status)->toBe(TaskStatus::QUEUED);
    expect($file->probe()->sole()->request_token)->not->toBe($old->token);
    expect(MediaStream::query()->count())->toBe(0);
});

it('does nothing when the file is deleted during probing', function (): void {
    $file = pendingProbeFile();
    $driver = Mockery::mock(FFProbeDriver::class);
    $driver->shouldReceive('command')->once()->andReturnUsing(function () use ($file): string {
        $file->delete();

        return '{"format":{},"streams":[{"index":0}]}';
    });
    app()->instance(FFProbeDriver::class, $driver);

    app()->call([queuedProbeJob($file), 'handle']);

    expect(MediaProbe::query()->count())->toBe(0);
    expect(MediaStream::query()->count())->toBe(0);
});

it('reclaims an expired running attempt while preserving the attempt budget', function (): void {
    $file = pendingProbeFile();
    $file->probe()->update(['status' => TaskStatus::RUNNING, 'attempts' => 1, 'started_at' => now()->subMinutes(4)]);
    $driver = Mockery::mock(FFProbeDriver::class);
    $driver->shouldReceive('command')->once()->andReturn('{"format":{},"streams":[{"index":0}]}');
    app()->instance(FFProbeDriver::class, $driver);

    app()->call([queuedProbeJob($file), 'handle']);

    expect($file->probe()->sole()->status)->toBe(TaskStatus::COMPLETED);
    expect($file->probe()->sole()->attempts)->toBe(2);
});

it('waits for another scan when the file has changed before probing starts', function (): void {
    $file = pendingProbeFile();
    File::put($file->libraryPath()->firstOrFail()->path.'/movie.mkv', 'new contents');

    app()->call([queuedProbeJob($file), 'handle']);

    expect($file->probe()->sole()->status)->toBe(TaskStatus::PENDING);
    expect($file->probe()->sole()->metadata)->toBeNull();
});

it('rejects a symbolic link substituted for a discovered file', function (): void {
    $file = pendingProbeFile();
    $path = $file->libraryPath()->firstOrFail()->path.'/movie.mkv';
    $target = mediaDirectory(['other.mkv' => 'video']).'/other.mkv';
    File::delete($path);
    symlink($target, $path);

    app()->call([queuedProbeJob($file)->withFakeQueueInteractions(), 'handle']);

    expect($file->probe()->sole()->status)->toBe(TaskStatus::FAILED);
    expect($file->probe()->sole()->error)->toContain('symbolic link');
});

it('fails immediately if ffprobe cannot be found', function (): void {
    $file = pendingProbeFile();
    app()->bind(FFProbeDriver::class, fn () => throw new ExecutableNotFoundException('FFprobe is missing.'));

    app()->call([queuedProbeJob($file)->withFakeQueueInteractions(), 'handle']);

    expect($file->probe()->sole()->status)->toBe(TaskStatus::FAILED);
    expect($file->probe()->sole()->attempts)->toBe(1);
});

it('leaves queue dispatch failures pending without failing the successful folder scan', function (): void {
    $file = pendingProbeFile();
    $file->probe()->update(['status' => TaskStatus::PENDING]);
    $bus = Mockery::mock(Dispatcher::class);
    if (! $bus instanceof Dispatcher) {
        throw new LogicException('The queue double must implement the dispatcher contract.');
    }
    $bus->shouldReceive('dispatch')->andThrow(new RuntimeException('Queue unavailable.'));
    app()->instance(RequestProbe::class, new RequestProbe($bus));
    Exceptions::fake();

    scanMediaPath($file->libraryPath()->firstOrFail());

    expect($file->libraryPath()->firstOrFail()->scan_status)->toBe(TaskStatus::COMPLETED);
    expect($file->probe()->sole()->status)->toBe(TaskStatus::PENDING);
    Exceptions::assertReported(RuntimeException::class);
});

it('rolls back every metadata change when track persistence fails', function (): void {
    $file = pendingProbeFile();
    $probe = $file->probe()->sole();
    $probe->update(['metadata' => ['previous' => 'report']]);
    MediaStream::factory()->for($probe, 'probe')->create(['codec_name' => 'h264']);
    $driver = Mockery::mock(FFProbeDriver::class);
    $driver->shouldReceive('command')->once()->andReturn('{"format":{},"streams":[{"index":0,"codec_name":"hevc"},{"index":1,"codec_type":"audio"}]}');
    app()->instance(FFProbeDriver::class, $driver);
    MediaStream::creating(function (MediaStream $stream): void {
        if ($stream->stream_index === 1) {
            throw new RuntimeException('Storage error.');
        }
    });

    try {
        expect(fn () => app()->call([queuedProbeJob($file), 'handle']))->toThrow(RuntimeException::class, 'Storage error.');

        expect($probe->refresh()->metadata)->toBe(['previous' => 'report']);
        expect($probe->streams()->sole()->codec_name)->toBe('h264');
        expect($probe->status)->toBe(TaskStatus::QUEUED);
    } finally {
        MediaStream::flushEventListeners();
    }
});

it('processes both queues through a real worker with scans taking priority', function (): void {
    config()->set('queue.default', 'database');
    $root = LibraryPath::factory()->atPath(mediaDirectory(['movie.mkv' => 'video']))->create();
    scanMediaPath($root);
    $file = $root->mediaFiles()->sole();
    $driver = Mockery::mock(FFProbeDriver::class);
    $driver->shouldReceive('command')->once()->andReturn('{"format":{},"streams":[{"index":0}]}');
    app()->instance(FFProbeDriver::class, $driver);
    app(RequestLibraryScan::class)->handle($root->library()->firstOrFail());

    artisanCommand('queue:work', ['--once' => true, '--queue' => 'scans,probes'])->assertSuccessful();
    expect($file->probe()->sole()->status)->toBe(TaskStatus::QUEUED);
    artisanCommand('queue:work', ['--once' => true, '--queue' => 'scans,probes'])->assertSuccessful();

    expect($file->refresh()->unsetRelation('probe')->hasCurrentProbe())->toBeTrue();
    expect(DB::table('jobs')->count())->toBe(0);
    expect(config('horizon.defaults.supervisor-1.queue'))->toBe(['scans', 'probes', 'default']);
    $commands = DevCommands::commands();
    expect(array_column($commands, 'command', 'name'))->toMatchArray(['horizon' => 'php artisan horizon']);
});

it('preserves the retry delay when an interrupted probe is recovered into a new queue message', function (): void {
    \Pest\Laravel\freezeTime();
    config()->set('queue.default', 'database');
    $root = LibraryPath::factory()->atPath(mediaDirectory(['movie.mkv' => 'video']))->create();
    scanMediaPath($root);
    $file = $root->mediaFiles()->sole();
    $file->probe()->update(['status' => TaskStatus::RUNNING, 'attempts' => 1, 'started_at' => now()->subMinutes(4)]);
    artisanCommand('tasks:recover')->assertSuccessful();
    $driver = Mockery::mock(FFProbeDriver::class);
    $driver->shouldReceive('command')->once()->andThrow(new RuntimeException('Temporary I/O error.'));
    app()->instance(FFProbeDriver::class, $driver);

    artisanCommand('queue:work', ['--once' => true, '--queue' => 'probes'])->assertSuccessful();
    artisanCommand('queue:work', ['--once' => true, '--queue' => 'probes'])->assertSuccessful();

    expect($file->probe()->sole()->attempts)->toBe(2);
    expect(DB::table('jobs')->value('available_at'))->toBe(now()->addSeconds(120)->getTimestamp());
});
