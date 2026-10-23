<?php

use App\Encoding\Enums\ProbeStatus;
use App\Jobs\ProbeMediaFile;
use App\Models\LibraryPath;
use App\Models\MediaFile;
use App\Models\MediaProbe;
use App\Scan\Enums\ScanStatus;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;

it('allows an explicit request for present files and leaves active requests alone', function (): void {
    Queue::fake([ProbeMediaFile::class]);
    $file = MediaFile::factory()->create();

    artisanCommand('media:probe', ['fileId' => $file->id])->assertSuccessful();
    $token = $file->probe()->sole()->request_token;
    artisanCommand('media:probe', ['fileId' => $file->id])->assertSuccessful();

    expect($file->probe()->sole()->request_token)->toBe($token);
    Queue::assertPushed(ProbeMediaFile::class, 1);
    $file->update(['status' => 'missing']);
    artisanCommand('media:probe', ['fileId' => $file->id])->assertFailed();
    artisanCommand('media:probe', ['fileId' => 'invalid'])->assertFailed();
    artisanCommand('media:probe', ['fileId' => 999999])->assertFailed();
});

it('recovers unfinished requests without resetting attempts or retrying terminal failures', function (): void {
    Queue::fake([ProbeMediaFile::class]);
    $file = MediaFile::factory()->create();
    $probe = MediaProbe::factory()->for($file)->create(['status' => ProbeStatus::Probing, 'request_token' => 'old', 'attempts' => 2, 'started_at' => now()]);
    $oldJob = new ProbeMediaFile($file->id, 'old');
    $failed = MediaProbe::factory()->create(['status' => ProbeStatus::Failed]);
    $complete = MediaProbe::factory()->create(['status' => ProbeStatus::Completed]);

    artisanCommand('media:recover-probes')->expectsOutput('Recovered 1 media probes.')->assertSuccessful();
    app()->call([$oldJob, 'handle']);
    $oldJob->failed(new RuntimeException('Old failure.'));

    expect($probe->refresh()->status)->toBe(ProbeStatus::Queued);
    expect($probe->attempts)->toBe(2);
    expect($probe->request_token)->not->toBe('old');
    expect($failed->refresh()->status)->toBe(ProbeStatus::Failed);
    expect($complete->refresh()->status)->toBe(ProbeStatus::Completed);
    Queue::assertPushed(ProbeMediaFile::class, 1);
});

it('recovers pending dispatches only from a successfully completed scan', function (): void {
    Queue::fake([ProbeMediaFile::class]);
    $root = LibraryPath::factory()->create(['scan_status' => ScanStatus::Completed, 'scan_token' => 'completed']);
    $file = MediaFile::factory()->for($root, 'libraryPath')->create(['last_seen_scan_token' => 'completed']);
    $pending = MediaProbe::factory()->for($file)->create();
    $incomplete = MediaProbe::factory()->create();

    artisanCommand('media:recover-probes')->assertSuccessful();

    expect($pending->refresh()->status)->toBe(ProbeStatus::Queued);
    expect($incomplete->refresh()->status)->toBe(ProbeStatus::Pending);
    Queue::assertPushed(ProbeMediaFile::class, 1);
});

it('marks exhausted interrupted probes failed without another dispatch', function (): void {
    Queue::fake([ProbeMediaFile::class]);
    $probe = MediaProbe::factory()->create(['status' => ProbeStatus::Probing, 'attempts' => 3]);

    artisanCommand('media:recover-probes')->assertSuccessful();

    expect($probe->refresh()->status)->toBe(ProbeStatus::Failed);
    Queue::assertNothingPushed();
});

it('fails startup while preserving pending work if the queue is unavailable', function (): void {
    $probe = MediaProbe::factory()->create(['status' => ProbeStatus::Queued]);
    $dispatcher = app(Dispatcher::class);
    $bus = Mockery::mock(Dispatcher::class);
    $bus->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Queue unavailable.'));
    app()->instance(Dispatcher::class, $bus);
    Exceptions::fake();

    artisanCommand('media:recover-probes')->assertFailed();

    expect($probe->refresh()->status)->toBe(ProbeStatus::Pending);
    expect($probe->error)->toBe('Queue unavailable.');
    Exceptions::assertReported(RuntimeException::class);

    app()->instance(Dispatcher::class, $dispatcher);
    Queue::fake([ProbeMediaFile::class]);
    artisanCommand('media:recover-probes')->expectsOutput('Recovered 1 media probes.')->assertSuccessful();

    expect($probe->refresh()->status)->toBe(ProbeStatus::Queued);
    Queue::assertPushed(ProbeMediaFile::class, 1);
});

it('recovers a manual request whose initial dispatch failed after an unsuccessful folder scan', function (): void {
    $root = LibraryPath::factory()->create(['scan_status' => ScanStatus::Failed]);
    $file = MediaFile::factory()->for($root, 'libraryPath')->create();
    $dispatcher = app(Dispatcher::class);
    $bus = Mockery::mock(Dispatcher::class);
    $bus->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Queue unavailable.'));
    app()->instance(Dispatcher::class, $bus);
    Exceptions::fake();

    artisanCommand('media:probe', ['fileId' => $file->id])->assertFailed();
    app()->instance(Dispatcher::class, $dispatcher);
    Queue::fake([ProbeMediaFile::class]);
    artisanCommand('media:recover-probes')->assertSuccessful();

    expect($file->probe()->sole()->status)->toBe(ProbeStatus::Queued);
    Queue::assertPushed(ProbeMediaFile::class, 1);
});
