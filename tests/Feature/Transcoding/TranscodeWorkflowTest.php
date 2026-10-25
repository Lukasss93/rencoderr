<?php

use App\Jobs\QueueLibraryTranscodes;
use App\Jobs\TranscodeMediaFile;
use App\Library\Enums\MediaFileStatus;
use App\Models\Library;
use App\Models\MediaFile;
use App\Models\MediaQualityCheck;
use App\Models\MediaTranscode;
use App\Models\TranscodeRun;
use App\QualityControl\Enums\QualityVerdict;
use App\Tasks\Enums\TaskStatus;
use App\Transcoding\Actions\EnumerateTranscodeRun;
use App\Transcoding\Actions\RecoverTranscodes;
use App\Transcoding\Actions\RequestTranscodeRun;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../../Support/TranscodeFixtures.php';

it('queues only the requested media file and recovers it without enumerating its library', function (): void {
    Queue::fake();
    $file = transcodeSource();
    $library = $file->libraryPath()->firstOrFail()->library()->firstOrFail();
    transcodeSource($library);

    $run = app(RequestTranscodeRun::class)->forFile($file);

    expect($run->library_id)->toBe($library->id)
        ->and($run->enumeration_finished_at)->not->toBeNull()
        ->and($run->transcodes()->sole()->media_file_id)->toBe($file->id);
    Queue::assertPushed(TranscodeMediaFile::class, 1);
    Queue::assertNotPushed(QueueLibraryTranscodes::class);

    app(RecoverTranscodes::class)->handle();

    expect($run->transcodes()->sole()->media_file_id)->toBe($file->id);
    Queue::assertPushed(TranscodeMediaFile::class, 2);
    Queue::assertNotPushed(QueueLibraryTranscodes::class);
});

it('does not queue an unavailable media file or one without current metadata', function (string $condition): void {
    Queue::fake();
    $file = transcodeSource();
    match ($condition) {
        'missing' => $file->update(['status' => MediaFileStatus::MISSING]),
        'excluded' => $file->update(['status' => MediaFileStatus::EXCLUDED]),
        'stale' => $file->update(['size_bytes' => 999]),
        'failed probe' => $file->probe()->update(['status' => TaskStatus::FAILED]),
        'no probe' => $file->probe()->delete(),
        default => throw new InvalidArgumentException('Unknown test condition.'),
    };

    expect(fn () => app(RequestTranscodeRun::class)->forFile($file))->toThrow(ValidationException::class);
    expect(TranscodeRun::query()->count())->toBe(0);
    Queue::assertNothingPushed();
})->with(['missing', 'excluded', 'stale', 'failed probe', 'no probe']);

it('reuses a single file request and rejects another file while its library is busy', function (): void {
    Queue::fake();
    $file = transcodeSource();
    $other = transcodeSource($file->libraryPath()->firstOrFail()->library()->firstOrFail());
    $requests = app(RequestTranscodeRun::class);

    $run = $requests->forFile($file);

    expect($requests->forFile($file)->id)->toBe($run->id);
    expect(fn () => $requests->forFile($other))->toThrow(ValidationException::class, 'This library already has an active transcode run.');
    expect($requests->handle($file->libraryPath()->firstOrFail()->library()->firstOrFail())->id)->toBe($run->id)
        ->and(TranscodeRun::query()->count())->toBe(1);
    Queue::assertPushed(TranscodeMediaFile::class, 1);
    Queue::assertNotPushed(QueueLibraryTranscodes::class);
});

it('does not add an individual file to a library run that is still enumerating', function (): void {
    Queue::fake();
    $file = transcodeSource();
    app(RequestTranscodeRun::class)->handle($file->libraryPath()->firstOrFail()->library()->firstOrFail());

    expect(fn () => app(RequestTranscodeRun::class)->forFile($file))->toThrow(ValidationException::class);
    expect(MediaTranscode::query()->count())->toBe(0);
    Queue::assertNotPushed(TranscodeMediaFile::class);
});

it('retains unchanged output when a single media file is requested again', function (): void {
    Queue::fake();
    Storage::fake('transcodes');
    $file = transcodeSource();
    $root = $file->libraryPath()->firstOrFail();
    $previous = TranscodeRun::factory()->for($root->library()->firstOrFail())->create(['status' => TaskStatus::COMPLETED, 'finished_at' => now()]);
    MediaTranscode::factory()->for($previous, 'run')->for($file)->create([
        'status' => TaskStatus::COMPLETED, 'source_path' => $root->path.'/'.$file->relative_path,
        'source_size_bytes' => $file->size_bytes, 'source_modified_at' => $file->filesystem_modified_at,
        'output_path' => 'retained/output.mkv',
    ]);
    Storage::disk('transcodes')->put('retained/output.mkv', 'accepted');

    $run = app(RequestTranscodeRun::class)->forFile($file);

    expect($run->refresh()->status)->toBe(TaskStatus::COMPLETED)
        ->and($run->retained_output_count)->toBe(1)
        ->and($run->transcodes()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('does not publish a single file request when the transaction is rolled back', function (): void {
    Queue::fake();
    $file = transcodeSource();
    expect(fn () => DB::transaction(function () use ($file): void {
        app(RequestTranscodeRun::class)->forFile($file);
        throw new RuntimeException('rollback');
    }))->toThrow(RuntimeException::class, 'rollback');

    expect(TranscodeRun::query()->count())->toBe(0)
        ->and(MediaTranscode::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('admits a fresh encode after quality control rejected the retained output', function (string $request): void {
    Queue::fake();
    Storage::fake('transcodes');
    $file = transcodeSource();
    $root = $file->libraryPath()->firstOrFail();
    $library = $root->library()->firstOrFail();
    $previous = TranscodeRun::factory()->for($library)->create(['status' => TaskStatus::COMPLETED, 'finished_at' => now()]);
    $item = MediaTranscode::factory()->for($previous, 'run')->for($file)->create([
        'status' => TaskStatus::COMPLETED, 'source_path' => $root->path.'/'.$file->relative_path,
        'source_size_bytes' => $file->size_bytes, 'source_modified_at' => $file->filesystem_modified_at,
        'output_path' => 'rejected/output.mkv',
    ]);
    $check = MediaQualityCheck::factory()->for($item, 'transcode')->create([
        'status' => TaskStatus::COMPLETED, 'verdict' => QualityVerdict::REJECTED,
    ]);
    Storage::disk('transcodes')->put('rejected/output.mkv', 'rejected output');
    $requests = app(RequestTranscodeRun::class);

    if ($request === 'file') {
        $run = $requests->forFile($file);
    } else {
        $run = $requests->handle($library);
        app(EnumerateTranscodeRun::class)->handle($run->id, $run->request_token);
    }

    expect($run->refresh()->retained_output_count)->toBe(0)
        ->and($run->transcodes()->sole()->status)->toBe(TaskStatus::QUEUED)
        ->and($run->transcodes()->sole()->media_file_id)->toBe($file->id)
        ->and($item->refresh()->status)->toBe(TaskStatus::COMPLETED)
        ->and($check->refresh()->verdict)->toBe(QualityVerdict::REJECTED);
    Storage::disk('transcodes')->assertExists('rejected/output.mkv');
    Queue::assertPushed(TranscodeMediaFile::class, 1);
})->with(['file', 'library']);

it('retains output while its quality check is pending, passed or needs retry', function (TaskStatus $status, ?QualityVerdict $verdict): void {
    Queue::fake();
    Storage::fake('transcodes');
    $file = transcodeSource();
    $root = $file->libraryPath()->firstOrFail();
    $previous = TranscodeRun::factory()->for($root->library()->firstOrFail())->create(['status' => TaskStatus::COMPLETED, 'finished_at' => now()]);
    $item = MediaTranscode::factory()->for($previous, 'run')->for($file)->create([
        'status' => TaskStatus::COMPLETED, 'source_path' => $root->path.'/'.$file->relative_path,
        'source_size_bytes' => $file->size_bytes, 'source_modified_at' => $file->filesystem_modified_at,
        'output_path' => 'retained/output.mkv',
    ]);
    MediaQualityCheck::factory()->for($item, 'transcode')->create(['status' => $status, 'verdict' => $verdict]);
    Storage::disk('transcodes')->put('retained/output.mkv', 'retained output');

    $run = app(RequestTranscodeRun::class)->forFile($file);

    expect($run->retained_output_count)->toBe(1)->and($run->transcodes()->count())->toBe(0);
    Queue::assertNothingPushed();
})->with([
    'queued' => [TaskStatus::QUEUED, null],
    'running' => [TaskStatus::RUNNING, null],
    'passed' => [TaskStatus::COMPLETED, QualityVerdict::PASSED],
    'failed' => [TaskStatus::FAILED, null],
    'cancelled' => [TaskStatus::CANCELLED, null],
]);

it('returns the active run and admits only present files with current metadata', function (): void {
    Queue::fake();
    $file = transcodeSource();
    $library = $file->libraryPath()->firstOrFail()->library()->firstOrFail();
    MediaFile::factory()->for($file->libraryPath()->firstOrFail())->create();
    $stale = transcodeSource($library);
    $stale->update(['size_bytes' => 90]);
    MediaFile::factory()->for($file->libraryPath()->firstOrFail())->missing()->create();
    $run = app(RequestTranscodeRun::class)->handle($library);
    expect(app(RequestTranscodeRun::class)->handle($library)->id)->toBe($run->id);
    app()->call([new QueueLibraryTranscodes($run->id, $run->request_token), 'handle']);
    expect($run->refresh()->excluded_metadata_count)->toBe(2);
    expect($run->enumeration_finished_at)->not->toBeNull();
    $item = $run->transcodes()->sole();
    expect($item->media_file_id)->toBe($file->id)
        ->and($item->status)->toBe(TaskStatus::QUEUED);
    Queue::assertPushed(TranscodeMediaFile::class, fn (TranscodeMediaFile $job): bool => $job->transcodeId === $item->id && $job->token === $item->request_token);
});

it('does not publish a run rolled back by its caller', function (): void {
    Queue::fake();
    $library = Library::factory()->create();
    try {
        DB::transaction(function () use ($library): void {
            app(RequestTranscodeRun::class)->handle($library);
            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }
    expect(TranscodeRun::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('resumes chunked inventory at its cursor and excludes files added after the cutoff', function (): void {
    Queue::fake();
    $file = transcodeSource();
    $root = $file->libraryPath()->firstOrFail();
    $library = $root->library()->firstOrFail();
    MediaFile::factory()->count(201)->for($root)->create();
    $run = app(RequestTranscodeRun::class)->handle($library);
    $enumerate = app(EnumerateTranscodeRun::class);
    expect($enumerate->handle($run->id, $run->request_token))->toBeTrue();
    expect($run->refresh()->inventory_cursor_id)->toBe(200);
    expect($run->enumeration_finished_at)->toBeNull();
    transcodeSource($library);
    expect($enumerate->handle($run->id, $run->request_token))->toBeFalse();
    expect($run->refresh()->excluded_metadata_count)->toBe(201);
    expect($run->transcodes()->sole()->media_file_id)->toBe($file->id);
    expect($enumerate->handle($run->id, $run->request_token))->toBeFalse();
    expect($run->refresh()->excluded_metadata_count)->toBe(201);
});

it('excludes unchanged sources with retained completed output', function (): void {
    Queue::fake();
    Storage::fake('transcodes');
    $file = transcodeSource();
    $root = $file->libraryPath()->firstOrFail();
    $library = $root->library()->firstOrFail();
    $previous = TranscodeRun::factory()->for($library)->create(['status' => TaskStatus::COMPLETED, 'finished_at' => now()]);
    MediaTranscode::factory()->for($previous, 'run')->for($file)->create([
        'status' => TaskStatus::COMPLETED, 'source_path' => $root->path.'/'.$file->relative_path,
        'source_size_bytes' => $file->size_bytes, 'source_modified_at' => $file->filesystem_modified_at, 'output_path' => 'retained/output.mkv',
    ]);
    Storage::disk('transcodes')->put('retained/output.mkv', 'accepted');
    $run = app(RequestTranscodeRun::class)->handle($library);
    app()->call([new QueueLibraryTranscodes($run->id, $run->request_token), 'handle']);
    expect($run->refresh()->retained_output_count)->toBe(1);
    expect($run->status)->toBe(TaskStatus::COMPLETED);
    expect($run->transcodes()->count())->toBe(0);
    Queue::assertNotPushed(TranscodeMediaFile::class);
});

it('enumerates only failed files with current probes in a retry run', function (): void {
    Queue::fake();
    $failed = transcodeSource();
    $library = $failed->libraryPath()->firstOrFail()->library()->firstOrFail();
    $finished = transcodeSource($library);
    $stale = transcodeSource($library);
    $stale->probe()->update(['status' => TaskStatus::PENDING]);
    $previous = TranscodeRun::factory()->for($library)->create(['status' => TaskStatus::FAILED, 'finished_at' => now()]);
    MediaTranscode::factory()->for($previous, 'run')->for($failed)->create(['status' => TaskStatus::FAILED]);
    MediaTranscode::factory()->for($previous, 'run')->for($finished)->create(['status' => TaskStatus::COMPLETED]);
    MediaTranscode::factory()->for($previous, 'run')->for($stale)->create(['status' => TaskStatus::FAILED]);
    $run = app(RequestTranscodeRun::class)->retryFailed($previous);
    app()->call([new QueueLibraryTranscodes($run->id, $run->request_token), 'handle']);
    expect($run->transcodes()->sole()->media_file_id)->toBe($failed->id);
    expect($run->refresh()->excluded_metadata_count)->toBe(1);
    expect($run->retry_of_run_id)->toBe($previous->id);
    Queue::assertPushed(TranscodeMediaFile::class, 1);
});

it('admits a source again when its earlier output is gone or came from a different version', function (array $previous, bool $outputExists): void {
    Queue::fake([TranscodeMediaFile::class]);
    Storage::fake('transcodes');
    $file = transcodeSource();
    $root = $file->libraryPath()->firstOrFail();
    $library = $root->library()->firstOrFail();
    $earlier = TranscodeRun::factory()->for($library)->create(['status' => TaskStatus::COMPLETED, 'finished_at' => now()]);
    MediaTranscode::factory()->for($earlier, 'run')->for($file)->create([
        'status' => TaskStatus::COMPLETED, 'source_path' => $root->path.'/'.$file->relative_path,
        'source_size_bytes' => $file->size_bytes, 'source_modified_at' => $file->filesystem_modified_at, 'output_path' => 'earlier/output.mkv',
        ...$previous,
    ]);
    if ($outputExists) {
        Storage::disk('transcodes')->put('earlier/output.mkv', 'accepted');
    }
    $run = app(RequestTranscodeRun::class)->handle($library);

    app()->call([new QueueLibraryTranscodes($run->id, $run->request_token), 'handle']);

    expect($run->refresh()->retained_output_count)->toBe(0);
    expect($run->transcodes()->sole()->media_file_id)->toBe($file->id);
    Queue::assertPushed(TranscodeMediaFile::class, 1);
})->with([
    'output removed' => [[], false],
    'different size' => [['source_size_bytes' => 4], true],
]);

it('keeps a file queued for startup recovery when its job cannot be published', function (): void {
    Queue::fake([QueueLibraryTranscodes::class]);
    Exceptions::fake();
    $file = transcodeSource();
    $run = app(RequestTranscodeRun::class)->handle($file->libraryPath()->firstOrFail()->library()->firstOrFail());
    $bus = Mockery::mock(Dispatcher::class);
    $bus->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Redis unavailable'));
    app()->instance(Dispatcher::class, $bus);

    app(EnumerateTranscodeRun::class)->handle($run->id, $run->request_token);

    expect($run->transcodes()->sole()->status)->toBe(TaskStatus::QUEUED);
    Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'Redis unavailable');
});
