<?php

use App\Jobs\ScanLibraryPath;
use App\Library\Actions\RequestLibraryScan;
use App\Library\Enums\MediaFileStatus;
use App\Library\MediaScanner;
use App\Livewire\Settings\Libraries;
use App\Models\Library;
use App\Models\LibraryPath;
use App\Models\MediaFile;
use App\Tasks\Enums\TaskStatus;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;

use function Pest\Laravel\assertDatabaseCount;
use function Pest\Laravel\freezeTime;
use function Pest\Laravel\travel;

it('queues only due automatic libraries at the exact interval boundary', function (): void {
    Queue::fake([ScanLibraryPath::class]);
    freezeTime();
    $due = Library::factory()->create(['last_scan_requested_at' => now()->subMinutes(37), 'scan_interval_minutes' => 37]);
    $first = LibraryPath::factory()->for($due)->create();
    $second = LibraryPath::factory()->for($due)->create();
    $fresh = Library::factory()->create(['last_scan_requested_at' => now()->subMinutes(36), 'scan_interval_minutes' => 37]);
    LibraryPath::factory()->for($fresh)->create();
    LibraryPath::factory()->for(Library::factory()->manual())->create();

    artisanCommand('libraries:scan-due')->expectsOutput('Queued 2 folder scans.')->assertSuccessful();

    Queue::assertPushed(ScanLibraryPath::class, 2);
    Queue::assertPushed(ScanLibraryPath::class, fn (ScanLibraryPath $job): bool => $job->pathId === $first->id);
    Queue::assertPushed(ScanLibraryPath::class, fn (ScanLibraryPath $job): bool => $job->pathId === $second->id);
    expect($due->refresh()->last_scan_requested_at?->toDateTimeString())->toBe(now()->toDateTimeString());
});

it('allows manual scans of disabled libraries and deduplicates repeated requests', function (): void {
    Queue::fake([ScanLibraryPath::class]);
    $library = Library::factory()->manual()->create();
    $root = LibraryPath::factory()->for($library)->create();

    Livewire::test(Libraries::class)->call('scan', $library->id)->assertDispatched('ts-ui:toast', title: 'Queued 1 folder scans.')
        ->call('scan', $library->id)->assertDispatched('ts-ui:toast', title: 'All folders are already queued or scanning.');

    Queue::assertPushed(ScanLibraryPath::class, 1);
    expect($root->refresh()->scan_status)->toBe(TaskStatus::QUEUED);
});

it('skips active roots while allowing other roots of the library to be requested', function (): void {
    Queue::fake([ScanLibraryPath::class]);
    $library = Library::factory()->create();
    LibraryPath::factory()->for($library)->create(['scan_status' => TaskStatus::RUNNING, 'scan_requested_at' => now(), 'scan_started_at' => now()]);
    LibraryPath::factory()->for($library)->create(['scan_status' => TaskStatus::QUEUED, 'scan_requested_at' => now()]);
    $inactive = LibraryPath::factory()->for($library)->create(['scan_status' => TaskStatus::FAILED]);

    expect(app(RequestLibraryScan::class)->handle($library))->toBe(1);

    Queue::assertPushed(ScanLibraryPath::class, 1);
    Queue::assertPushed(ScanLibraryPath::class, fn (ScanLibraryPath $job): bool => $job->pathId === $inactive->id);
});

it('queues a lost queued scan again once the scan timeout passes, keeping its token', function (): void {
    Queue::fake([ScanLibraryPath::class]);
    freezeTime();
    $token = (string) Str::uuid();
    $library = Library::factory()->create();
    $root = LibraryPath::factory()->for($library)->create([
        'scan_status' => TaskStatus::QUEUED, 'scan_token' => $token,
        'scan_requested_at' => now()->subSeconds(ScanLibraryPath::TIMEOUT_SECONDS - 1),
    ]);

    expect(app(RequestLibraryScan::class)->handle($library))->toBe(0);
    travel(1)->second();
    expect(app(RequestLibraryScan::class)->handle($library))->toBe(1);

    Queue::assertPushed(ScanLibraryPath::class, fn (ScanLibraryPath $job): bool => $job->token === $token);
    expect($root->refresh()->scan_status)->toBe(TaskStatus::QUEUED)
        ->and($root->scan_requested_at?->toDateTimeString())->toBe(now()->toDateTimeString());
});

it('runs only one of the original and replacement jobs for a requeued scan', function (): void {
    $token = (string) Str::uuid();
    $library = Library::factory()->create();
    $root = LibraryPath::factory()->for($library)->atPath(mediaDirectory(['movie.mkv' => 'video']))->create([
        'scan_status' => TaskStatus::QUEUED, 'scan_token' => $token, 'scan_requested_at' => now(),
    ]);
    $original = new ScanLibraryPath($root->id, $token);
    $replacement = new ScanLibraryPath($root->id, $token);

    $original->handle(app(MediaScanner::class));
    $finishedAt = $root->refresh()->scan_finished_at?->toDateTimeString();
    travel(1)->minute();
    $replacement->handle(app(MediaScanner::class));

    expect($root->refresh()->scan_status)->toBe(TaskStatus::COMPLETED)
        ->and($root->scan_finished_at?->toDateTimeString())->toBe($finishedAt);
});

it('replaces a scan whose worker stopped and ignores the stopped worker afterwards', function (): void {
    Queue::fake([ScanLibraryPath::class]);
    $staleToken = (string) Str::uuid();
    $library = Library::factory()->create();
    $root = LibraryPath::factory()->for($library)->create([
        'scan_status' => TaskStatus::RUNNING, 'scan_token' => $staleToken,
        'scan_requested_at' => now()->subHours(2), 'scan_started_at' => now()->subSeconds(ScanLibraryPath::TIMEOUT_SECONDS),
    ]);

    expect(app(RequestLibraryScan::class)->handle($library))->toBe(1);
    (new ScanLibraryPath($root->id, $staleToken))->failed(new RuntimeException('Worker was killed.'));

    Queue::assertPushed(ScanLibraryPath::class, fn (ScanLibraryPath $job): bool => $job->token !== $staleToken);
    expect($root->refresh()->scan_status)->toBe(TaskStatus::QUEUED)
        ->and($root->scan_token)->not->toBe($staleToken)
        ->and($root->scan_started_at)->toBeNull()
        ->and($root->scan_error)->toBeNull();
});

it('leaves a folder to the request that changed it first', function (): void {
    Queue::fake([ScanLibraryPath::class]);
    $library = Library::factory()->create();
    $root = LibraryPath::factory()->for($library)->create();
    $winningToken = (string) Str::uuid();
    LibraryPath::retrieved(function (LibraryPath $path) use ($winningToken): void {
        LibraryPath::query()->whereKey($path->id)->update([
            'scan_status' => TaskStatus::QUEUED, 'scan_token' => $winningToken, 'scan_requested_at' => now(),
        ]);
    });

    expect(app(RequestLibraryScan::class)->handle($library))->toBe(0);

    Queue::assertNothingPushed();
    expect(DB::table('library_paths')->where('id', $root->id)->value('scan_token'))->toBe($winningToken);
});

it('does not dispatch work or retain claims when a transaction rolls back', function (): void {
    Queue::fake([ScanLibraryPath::class]);
    $library = Library::factory()->create();
    $root = LibraryPath::factory()->for($library)->create();
    DB::beginTransaction();
    app(RequestLibraryScan::class)->handle($library);
    Queue::assertNothingPushed();

    DB::rollBack();

    expect($root->refresh()->scan_status)->toBe(TaskStatus::PENDING)
        ->and($library->refresh()->last_scan_requested_at)->toBeNull();
    Queue::assertNothingPushed();

    app(RequestLibraryScan::class)->handle($library);
    Queue::assertPushed(ScanLibraryPath::class, 1);
});

it('dispatches a claimed scan only after its surrounding transaction commits', function (): void {
    Queue::fake([ScanLibraryPath::class]);
    $library = Library::factory()->create();
    LibraryPath::factory()->for($library)->create();
    DB::beginTransaction();

    app(RequestLibraryScan::class)->handle($library);
    Queue::assertNothingPushed();
    DB::commit();

    Queue::assertPushed(ScanLibraryPath::class, 1);
});

it('records dispatch failures and permits a subsequent scan request', function (): void {
    Exceptions::fake();
    $library = Library::factory()->create();
    $root = LibraryPath::factory()->for($library)->create();
    $bus = Mockery::mock(Dispatcher::class);
    if (! $bus instanceof Dispatcher) {
        throw new LogicException('The queue double must implement the dispatcher contract.');
    }
    $bus->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Queue unavailable.'));
    $dispatcher = new RequestLibraryScan($bus);

    $dispatcher->handle($library);

    expect($root->refresh()->scan_status)->toBe(TaskStatus::FAILED)->and($root->scan_error)->toBe('Queue unavailable.');
    Exceptions::assertReported(RuntimeException::class);
    Queue::fake([ScanLibraryPath::class]);
    app(RequestLibraryScan::class)->handle($library);
    Queue::assertPushed(ScanLibraryPath::class, 1);
});

it('processes a real database queue job and accepts the next request once it completes', function (): void {
    Config::set('queue.default', 'database');
    $directory = mediaDirectory(['movie.mkv' => 'video']);
    $library = Library::factory()->create();
    $root = LibraryPath::factory()->for($library)->atPath($directory)->create();

    app(RequestLibraryScan::class)->handle($library);
    assertDatabaseCount('jobs', 1);
    artisanCommand('queue:work', ['--once' => true, '--queue' => 'scans', '--tries' => 1, '--timeout' => 1800])->assertSuccessful();

    expect($root->refresh()->scan_status)->toBe(TaskStatus::COMPLETED)
        ->and($root->mediaFiles()->firstOrFail()->status)->toBe(MediaFileStatus::PRESENT);
    assertDatabaseCount('jobs', 1);
    expect(DB::table('jobs')->value('queue'))->toBe('probes');

    app(RequestLibraryScan::class)->handle($library);
    assertDatabaseCount('jobs', 2);
});

it('ignores stale jobs and stale failures instead of overwriting the current scan', function (): void {
    $root = LibraryPath::factory()->create(['scan_status' => TaskStatus::QUEUED, 'scan_token' => (string) Str::uuid()]);
    $job = new ScanLibraryPath($root->id, (string) Str::uuid());

    $job->handle(app(MediaScanner::class));
    $job->failed(new RuntimeException('Old failure.'));

    expect($root->refresh()->scan_status)->toBe(TaskStatus::QUEUED)->and($root->scan_error)->toBeNull();
    assertDatabaseCount('media_files', 0);
});

it('exits safely if a root was removed while its scan was queued', function (): void {
    $token = (string) Str::uuid();
    $root = LibraryPath::factory()->create(['scan_status' => TaskStatus::QUEUED, 'scan_token' => $token]);
    $job = new ScanLibraryPath($root->id, $token);
    $root->delete();

    $job->handle(app(MediaScanner::class));

    assertDatabaseCount('library_paths', 0);
    assertDatabaseCount('media_files', 0);
});

it('records a terminal timeout without changing known file availability', function (): void {
    $token = (string) Str::uuid();
    $root = LibraryPath::factory()->create(['scan_status' => TaskStatus::RUNNING, 'scan_token' => $token]);
    $file = MediaFile::factory()->for($root, 'libraryPath')->create();

    (new ScanLibraryPath($root->id, $token))->failed(new RuntimeException('Scan timed out.'));

    expect($root->refresh()->scan_status)->toBe(TaskStatus::FAILED)
        ->and($root->scan_error)->toBe('Scan timed out.')
        ->and($file->refresh()->status)->toBe(MediaFileStatus::PRESENT);
});

it('makes failed roots eligible again at the configured interval', function (): void {
    Queue::fake([ScanLibraryPath::class]);
    freezeTime();
    $library = Library::factory()->create(['last_scan_requested_at' => now()->subMinutes(15)]);
    LibraryPath::factory()->for($library)->create(['scan_status' => TaskStatus::FAILED]);

    artisanCommand('libraries:scan-due')->expectsOutput('Queued 1 folder scans.')->assertSuccessful();

    Queue::assertPushed(ScanLibraryPath::class, 1);
});

it('does not reserve a queued scan again before its execution timeout has elapsed', function (): void {
    Config::set('queue.default', 'database');
    freezeTime();
    $library = Library::factory()->create();
    LibraryPath::factory()->for($library)->create();
    app(RequestLibraryScan::class)->handle($library);
    $queue = Queue::connection('database');

    expect($queue->pop('scans'))->not->toBeNull();
    travel(1800)->seconds();
    expect($queue->pop('scans'))->toBeNull();
    travel(60)->seconds();
    expect($queue->pop('scans'))->not->toBeNull();
});
