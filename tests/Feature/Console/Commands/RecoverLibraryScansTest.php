<?php

use App\Jobs\ScanLibraryPath;
use App\Models\Library;
use App\Models\LibraryPath;
use App\Models\MediaFile;
use App\Scan\Enums\ScanStatus;
use App\Scan\MediaScanner;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Queue;

it('immediately replaces pending scans without changing library timers or finished scans', function (): void {
    Queue::fake([ScanLibraryPath::class]);
    $library = Library::factory()->create(['last_scan_requested_at' => now()]);
    $requestedAt = $library->last_scan_requested_at;
    $queued = LibraryPath::factory()->for($library)->create(['scan_status' => ScanStatus::Queued, 'scan_token' => 'queued', 'scan_requested_at' => now()]);
    $running = LibraryPath::factory()->for($library)->create(['scan_status' => ScanStatus::Scanning, 'scan_token' => 'running', 'scan_started_at' => now()]);
    $completed = LibraryPath::factory()->for($library)->create(['scan_status' => ScanStatus::Completed]);
    $failed = LibraryPath::factory()->for($library)->create(['scan_status' => ScanStatus::Failed]);
    $file = MediaFile::factory()->for($running, 'libraryPath')->create();
    $originalFile = $file->refresh()->getAttributes();

    artisanCommand('libraries:recover-scans')->expectsOutput('Recovered 2 folder scans.')->assertSuccessful();

    Queue::assertPushed(ScanLibraryPath::class, 2);
    foreach ([$queued, $running] as $path) {
        $oldToken = $path->scan_token;
        expect($path->refresh()->scan_status)->toBe(ScanStatus::Queued)
            ->and($path->scan_token)->not->toBe($oldToken)
            ->and($path->scan_started_at)->toBeNull();
        Queue::assertPushed(ScanLibraryPath::class, fn (ScanLibraryPath $job): bool => $job->pathId === $path->id && $job->token === $path->scan_token);
    }
    expect($library->refresh()->last_scan_requested_at)->toEqual($requestedAt)
        ->and($completed->refresh()->scan_status)->toBe(ScanStatus::Completed)
        ->and($failed->refresh()->scan_status)->toBe(ScanStatus::Failed)
        ->and($file->refresh()->getAttributes())->toBe($originalFile);
});

it('makes old jobs and failures harmless across repeated recovery', function (): void {
    Queue::fake([ScanLibraryPath::class]);
    $path = LibraryPath::factory()->atPath(mediaDirectory(['movie.mkv' => 'video']))->create([
        'scan_status' => ScanStatus::Scanning, 'scan_token' => 'old', 'scan_started_at' => now(),
    ]);
    $old = new ScanLibraryPath($path->id, 'old');
    artisanCommand('libraries:recover-scans')->assertSuccessful();
    $first = new ScanLibraryPath($path->id, (string) $path->refresh()->scan_token);
    artisanCommand('libraries:recover-scans')->assertSuccessful();
    $current = new ScanLibraryPath($path->id, (string) $path->refresh()->scan_token);

    foreach ([$old, $first] as $job) {
        $job->handle(app(MediaScanner::class));
        $job->failed(new RuntimeException('Interrupted job.'));
    }
    expect($path->refresh()->scan_status)->toBe(ScanStatus::Queued);
    $current->handle(app(MediaScanner::class));

    expect($path->refresh()->scan_status)->toBe(ScanStatus::Completed)
        ->and($path->mediaFiles()->count())->toBe(1);
    Queue::assertPushed(ScanLibraryPath::class, 2);
});

it('fails bootstrap while preserving retryable state when dispatch fails', function (): void {
    $path = LibraryPath::factory()->create(['scan_status' => ScanStatus::Scanning, 'scan_token' => 'old']);
    $originalBus = app(Dispatcher::class);
    $bus = Mockery::mock(Dispatcher::class);
    $bus->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Queue unavailable.'));
    app()->instance(Dispatcher::class, $bus);

    artisanCommand('libraries:recover-scans')->assertFailed();

    expect($path->refresh()->scan_status)->toBe(ScanStatus::Queued);
    app()->instance(Dispatcher::class, $originalBus);
    Queue::fake([ScanLibraryPath::class]);
    artisanCommand('libraries:recover-scans')->assertSuccessful();
    Queue::assertPushed(ScanLibraryPath::class, 1);
});
