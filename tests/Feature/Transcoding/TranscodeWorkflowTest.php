<?php

use App\Jobs\QueueLibraryTranscodes;
use App\Jobs\TranscodeMediaFile;
use App\Models\Library;
use App\Models\MediaFile;
use App\Models\MediaTranscode;
use App\Models\TranscodeRun;
use App\Tasks\Enums\TaskStatus;
use App\Transcoding\Actions\DispatchTranscode;
use App\Transcoding\Actions\EnumerateTranscodeRun;
use App\Transcoding\Actions\RequestTranscodeRun;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\freezeTime;
use function Pest\Laravel\travel;

require_once __DIR__.'/../../Support/TranscodeFixtures.php';

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
    expect($run->transcodes()->sole()->media_file_id)->toBe($file->id);
    Queue::assertPushed(TranscodeMediaFile::class, 1);
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

it('acquires the native unique lock once and keeps a contending item pending', function (): void {
    Queue::fake();
    $item = MediaTranscode::factory()->create();
    $other = MediaTranscode::factory()->for($item->mediaFile()->firstOrFail())->create();
    expect(app(DispatchTranscode::class)->handle($item->id))->toBeTrue();
    expect(app(DispatchTranscode::class)->handle($item->id))->toBeFalse();
    expect(app(DispatchTranscode::class)->handle($other->id))->toBeFalse();
    expect($other->refresh()->status)->toBe(TaskStatus::PENDING);
    expect($item->refresh()->unique_lock_owner)->not->toBeNull();
    Queue::assertPushed(TranscodeMediaFile::class, 1);
});

it('releases its native lock on an outer transaction rollback', function (): void {
    Queue::fake();
    $item = MediaTranscode::factory()->create();
    try {
        DB::transaction(function () use ($item): void {
            app(DispatchTranscode::class)->handle($item->id);
            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }
    expect($item->refresh()->status)->toBe(TaskStatus::PENDING);
    expect(app(DispatchTranscode::class)->handle($item->id))->toBeTrue();
    Queue::assertPushed(TranscodeMediaFile::class, 1);
});

it('does not release a replacement native lock through an old owner', function (): void {
    freezeTime();
    $old = new TranscodeMediaFile(1, 1, 'old', 1);
    $new = new TranscodeMediaFile(2, 1, 'new', 1);
    $locks = new UniqueLock(Cache::store());
    expect($locks->acquire($old))->toBeTrue();
    travel(302)->seconds();
    expect($locks->acquire($new))->toBeTrue();
    $locks->release($old);
    expect($locks->acquire(new TranscodeMediaFile(3, 1, 'third', 1)))->toBeFalse();
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

it('never overwrites a task that starts before publication is acknowledged', function (): void {
    $item = MediaTranscode::factory()->create();
    $bus = Mockery::mock(Dispatcher::class);
    $bus->shouldReceive('dispatch')->once()->andReturnUsing(function () use ($item): void {
        $item->update(['status' => TaskStatus::RUNNING, 'attempt_token' => 'claimed']);
    });
    app()->instance(Dispatcher::class, $bus);
    expect(app(DispatchTranscode::class)->handle($item->id))->toBeTrue();
    expect($item->refresh()->status)->toBe(TaskStatus::RUNNING);
    expect($item->attempt_token)->toBe('claimed');
    expect($item->dispatched_at)->toBeNull();
});
