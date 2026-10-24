<?php

use App\Jobs\ProbeMediaFile;
use App\Jobs\ScanLibraryPath;
use App\Library\MediaScanner;
use App\Models\Library;
use App\Models\LibraryPath;
use App\Models\MediaFile;
use App\Models\MediaProbe;
use App\Tasks\Enums\TaskStatus;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;

it('immediately replaces pending scans without changing library timers or finished scans', function (): void {
    Queue::fake([ScanLibraryPath::class]);
    $library = Library::factory()->create(['last_scan_requested_at' => now()]);
    $requestedAt = $library->last_scan_requested_at;
    $queued = LibraryPath::factory()->for($library)->create(['scan_status' => TaskStatus::QUEUED, 'scan_token' => 'queued', 'scan_requested_at' => now()]);
    $running = LibraryPath::factory()->for($library)->create(['scan_status' => TaskStatus::RUNNING, 'scan_token' => 'running', 'scan_started_at' => now()]);
    $completed = LibraryPath::factory()->for($library)->create(['scan_status' => TaskStatus::COMPLETED]);
    $failed = LibraryPath::factory()->for($library)->create(['scan_status' => TaskStatus::FAILED]);
    $file = MediaFile::factory()->for($running, 'libraryPath')->create();
    $originalFile = $file->refresh()->getAttributes();

    artisanCommand('tasks:recover')->expectsOutput('Recovered 2 folder scans.')->assertSuccessful();

    Queue::assertPushed(ScanLibraryPath::class, 2);
    foreach ([$queued, $running] as $path) {
        $oldToken = $path->scan_token;
        expect($path->refresh()->scan_status)->toBe(TaskStatus::QUEUED)
            ->and($path->scan_token)->not->toBe($oldToken)
            ->and($path->scan_started_at)->toBeNull();
        Queue::assertPushed(ScanLibraryPath::class, fn (ScanLibraryPath $job): bool => $job->pathId === $path->id && $job->token === $path->scan_token);
    }
    expect($library->refresh()->last_scan_requested_at)->toEqual($requestedAt)
        ->and($completed->refresh()->scan_status)->toBe(TaskStatus::COMPLETED)
        ->and($failed->refresh()->scan_status)->toBe(TaskStatus::FAILED)
        ->and($file->refresh()->getAttributes())->toBe($originalFile);
});

it('makes old jobs and failures harmless across repeated recovery', function (): void {
    Queue::fake([ScanLibraryPath::class]);
    $path = LibraryPath::factory()->atPath(mediaDirectory(['movie.mkv' => 'video']))->create([
        'scan_status' => TaskStatus::RUNNING, 'scan_token' => 'old', 'scan_started_at' => now(),
    ]);
    $old = new ScanLibraryPath($path->id, 'old');
    artisanCommand('tasks:recover')->assertSuccessful();
    $first = new ScanLibraryPath($path->id, (string) $path->refresh()->scan_token);
    artisanCommand('tasks:recover')->assertSuccessful();
    $current = new ScanLibraryPath($path->id, (string) $path->refresh()->scan_token);

    foreach ([$old, $first] as $job) {
        $job->handle(app(MediaScanner::class));
        $job->failed(new RuntimeException('Interrupted job.'));
    }
    expect($path->refresh()->scan_status)->toBe(TaskStatus::QUEUED);
    $current->handle(app(MediaScanner::class));

    expect($path->refresh()->scan_status)->toBe(TaskStatus::COMPLETED)
        ->and($path->mediaFiles()->count())->toBe(1);
    Queue::assertPushed(ScanLibraryPath::class, 2);
});

it('fails bootstrap while preserving retryable state when dispatch fails', function (): void {
    $path = LibraryPath::factory()->create(['scan_status' => TaskStatus::RUNNING, 'scan_token' => 'old']);
    $originalBus = app(Dispatcher::class);
    $bus = Mockery::mock(Dispatcher::class);
    $bus->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Queue unavailable.'));
    app()->instance(Dispatcher::class, $bus);

    artisanCommand('tasks:recover')->assertFailed();

    expect($path->refresh()->scan_status)->toBe(TaskStatus::QUEUED);
    app()->instance(Dispatcher::class, $originalBus);
    Queue::fake([ScanLibraryPath::class]);
    artisanCommand('tasks:recover')->assertSuccessful();
    Queue::assertPushed(ScanLibraryPath::class, 1);
});

it('recovers unfinished requests without resetting attempts or retrying terminal failures', function (): void {
    Queue::fake([ProbeMediaFile::class]);
    $file = MediaFile::factory()->create();
    $probe = MediaProbe::factory()->for($file)->create(['status' => TaskStatus::RUNNING, 'request_token' => 'old', 'attempts' => 2, 'started_at' => now()]);
    $oldJob = new ProbeMediaFile($file->id, 'old');
    $failed = MediaProbe::factory()->create(['status' => TaskStatus::FAILED]);
    $complete = MediaProbe::factory()->create(['status' => TaskStatus::COMPLETED]);

    artisanCommand('tasks:recover')->expectsOutput('Recovered 1 media probes.')->assertSuccessful();
    app()->call([$oldJob, 'handle']);
    $oldJob->failed(new RuntimeException('Old failure.'));

    expect($probe->refresh()->status)->toBe(TaskStatus::QUEUED);
    expect($probe->attempts)->toBe(2);
    expect($probe->request_token)->not->toBe('old');
    expect($failed->refresh()->status)->toBe(TaskStatus::FAILED);
    expect($complete->refresh()->status)->toBe(TaskStatus::COMPLETED);
    Queue::assertPushed(ProbeMediaFile::class, 1);
});

it('recovers pending dispatches only from a successfully completed scan', function (): void {
    Queue::fake([ProbeMediaFile::class]);
    $root = LibraryPath::factory()->create(['scan_status' => TaskStatus::COMPLETED, 'scan_token' => 'completed']);
    $file = MediaFile::factory()->for($root, 'libraryPath')->create(['last_seen_scan_token' => 'completed']);
    $pending = MediaProbe::factory()->for($file)->create();
    $incomplete = MediaProbe::factory()->create();

    artisanCommand('tasks:recover')->assertSuccessful();

    expect($pending->refresh()->status)->toBe(TaskStatus::QUEUED);
    expect($incomplete->refresh()->status)->toBe(TaskStatus::PENDING);
    Queue::assertPushed(ProbeMediaFile::class, 1);
});

it('marks exhausted interrupted probes failed without another dispatch', function (): void {
    Queue::fake([ProbeMediaFile::class]);
    $probe = MediaProbe::factory()->create(['status' => TaskStatus::RUNNING, 'attempts' => 3]);

    artisanCommand('tasks:recover')->assertSuccessful();

    expect($probe->refresh()->status)->toBe(TaskStatus::FAILED);
    Queue::assertNothingPushed();
});

it('fails startup while preserving pending work if the queue is unavailable', function (): void {
    $probe = MediaProbe::factory()->create(['status' => TaskStatus::QUEUED]);
    $dispatcher = app(Dispatcher::class);
    $bus = Mockery::mock(Dispatcher::class);
    $bus->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Queue unavailable.'));
    app()->instance(Dispatcher::class, $bus);
    Exceptions::fake();

    artisanCommand('tasks:recover')->assertFailed();

    expect($probe->refresh()->status)->toBe(TaskStatus::PENDING);
    expect($probe->error)->toBe('Queue unavailable.');
    Exceptions::assertReported(RuntimeException::class);

    app()->instance(Dispatcher::class, $dispatcher);
    Queue::fake([ProbeMediaFile::class]);
    artisanCommand('tasks:recover')->expectsOutput('Recovered 1 media probes.')->assertSuccessful();

    expect($probe->refresh()->status)->toBe(TaskStatus::QUEUED);
    Queue::assertPushed(ProbeMediaFile::class, 1);
});

it('recovers a manual request whose initial dispatch failed after an unsuccessful folder scan', function (): void {
    $root = LibraryPath::factory()->create(['scan_status' => TaskStatus::FAILED]);
    $file = MediaFile::factory()->for($root, 'libraryPath')->create();
    $dispatcher = app(Dispatcher::class);
    $bus = Mockery::mock(Dispatcher::class);
    $bus->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Queue unavailable.'));
    app()->instance(Dispatcher::class, $bus);
    Exceptions::fake();

    artisanCommand('media:probe', ['fileId' => $file->id])->assertFailed();
    app()->instance(Dispatcher::class, $dispatcher);
    Queue::fake([ProbeMediaFile::class]);
    artisanCommand('tasks:recover')->assertSuccessful();

    expect($file->probe()->sole()->status)->toBe(TaskStatus::QUEUED);
    Queue::assertPushed(ProbeMediaFile::class, 1);
});
