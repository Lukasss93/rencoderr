<?php

use App\Jobs\QueueLibraryTranscodes;
use App\Jobs\ScanLibraryPath;
use App\Livewire\Queue;
use App\Models\Library;
use App\Models\LibraryPath;
use App\Models\MediaTranscode;
use App\Models\TranscodeRun;
use App\Models\User;
use App\Tasks\Enums\TaskStatus;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue as JobQueue;
use Illuminate\Support\Str;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\freezeTime;
use function Pest\Laravel\get;
use function Pest\Laravel\postJson;

it('keeps background scan jobs out of the transcoding queue', function (): void {
    Config::set('queue.default', 'database');
    freezeTime();
    $library = Library::factory()->create(['name' => 'Movies']);
    $running = LibraryPath::factory()->for($library)->atPath('/media/running')->create([
        'scan_status' => TaskStatus::RUNNING, 'scan_requested_at' => now(), 'scan_started_at' => now(),
    ]);
    $waiting = LibraryPath::factory()->for($library)->atPath('/media/waiting')->create([
        'scan_status' => TaskStatus::QUEUED, 'scan_requested_at' => now(),
    ]);
    LibraryPath::factory()->for($library)->atPath('/media/failed')->create([
        'scan_status' => TaskStatus::FAILED, 'scan_requested_at' => now(), 'scan_error' => 'Storage unavailable.',
    ]);
    LibraryPath::factory()->for($library)->atPath('/media/not-scanned')->create();
    JobQueue::push(new ScanLibraryPath($running->id, (string) Str::uuid()));
    expect(JobQueue::pop())->not->toBeNull();
    JobQueue::push(new ScanLibraryPath($waiting->id, (string) Str::uuid()));
    JobQueue::later(now()->addMinutes(10), new ScanLibraryPath($waiting->id, (string) Str::uuid()));
    JobQueue::pushOn('other-queue', new ScanLibraryPath($waiting->id, (string) Str::uuid()));

    Livewire::test(Queue::class)->assertSee('Queue empty')->assertSee('No transcoding jobs yet')
        ->assertDontSee('Processing jobs')->assertDontSee('Waiting jobs')->assertDontSee('Scheduled jobs')
        ->assertDontSee('Failed scans')->assertDontSee('Movies')->assertDontSee('/media/running')
        ->assertDontSee('/media/waiting')->assertDontSee('Storage unavailable.')->assertDontSee('/media/not-scanned');
    actingAs(User::factory()->create())->get(route('queue.index'))->assertOk()->assertSeeLivewire(Queue::class);
});

it('keeps the transcoding queue empty when a library scan finishes', function (): void {
    Config::set('queue.default', 'database');
    $root = LibraryPath::factory()->create(['scan_status' => TaskStatus::RUNNING, 'scan_requested_at' => now()]);
    JobQueue::push(new ScanLibraryPath($root->id, (string) Str::uuid()));
    $job = JobQueue::pop();

    if ($job === null) {
        throw new LogicException('The processing fixture must be reserved.');
    }

    $component = Livewire::test(Queue::class);
    $component->assertSee('Queue empty')->assertSee('No transcoding jobs yet');

    $job->delete();
    $root->update(['scan_status' => TaskStatus::COMPLETED, 'scan_finished_at' => now()]);
    $component->call('$refresh')->assertSee('Queue empty')->assertSee('No transcoding jobs yet')
        ->assertDontSee('Scan completed');
});

it('ignores old scan status links on the transcoding queue', function (): void {
    LibraryPath::factory()->atPath('/media/pending')->create(['scan_status' => TaskStatus::QUEUED]);
    LibraryPath::factory()->atPath('/media/completed')->create(['scan_status' => TaskStatus::COMPLETED]);
    LibraryPath::factory()->atPath('/media/failed')->create(['scan_status' => TaskStatus::FAILED]);

    Livewire::withQueryParams(['status' => 'failed'])->test(Queue::class)
        ->assertSee('No transcoding jobs yet')->assertDontSee('Scan status')
        ->assertDontSee('/media/failed')->assertDontSee('/media/pending')->assertDontSee('/media/completed');
});

it('does not paginate library scans on the transcoding queue', function (): void {
    $library = Library::factory()->create();

    for ($index = 0; $index < 26; $index++) {
        LibraryPath::factory()->for($library)->atPath('/media/folder-'.$index)->create([
            'scan_status' => $index === 0 ? TaskStatus::FAILED : TaskStatus::COMPLETED,
            'scan_requested_at' => now(),
        ]);
    }

    Livewire::withQueryParams(['page' => 2])->test(Queue::class)
        ->assertSee('No transcoding jobs yet')->assertDontSee('/media/folder-0')
        ->assertDontSee('/media/folder-25')->assertDontSee('Pagination Navigation');
});

it('does not expose library names folder paths or scan errors in the queue page', function (): void {
    $name = '<script>library()</script>';
    $path = '/media/<img src=x onerror=alert(1)>';
    $error = '<script>error()</script>';
    LibraryPath::factory()->for(Library::factory()->create(['name' => $name]))->atPath($path)->create([
        'scan_status' => TaskStatus::FAILED, 'scan_error' => $error,
    ]);

    actingAs(User::factory()->create())->get(route('queue.index'))->assertOk()
        ->assertDontSee($name)->assertDontSee($name, false)->assertDontSee($path)->assertDontSee($path, false)
        ->assertDontSee($error)->assertDontSee($error, false);
});

it('shows an empty queue when no work has been requested', function (): void {
    Config::set('auth.enabled', false);

    get(route('queue.index'))->assertOk()->assertSee('Queue empty')->assertSee('No transcoding jobs yet')->assertDontSee('Latest library scans');
});

it('requires authentication for live queue updates after the session expires', function (): void {
    Auth::login(User::factory()->create());
    $page = get(route('queue.index'))->assertOk();
    Auth::logout();
    session()->invalidate();
    Auth::forgetGuards();

    postJson(Livewire::getUpdateUri(), livewireUpdatePayload($page), ['X-Livewire' => 'true'])->assertUnauthorized();
});

it('allows queue updates without an account when authentication is disabled', function (): void {
    Config::set('auth.enabled', false);
    $page = get(route('queue.index'))->assertOk();

    postJson(Livewire::getUpdateUri(), livewireUpdatePayload($page), ['X-Livewire' => 'true'])->assertOk()
        ->assertJsonStructure(['components' => [['effects' => ['html']]]]);
});

it('shows run snapshots and file progress with retained outputs awaiting quality control', function (): void {
    freezeTime();
    $run = TranscodeRun::factory()->create(['library_name' => 'Archive movies', 'status' => TaskStatus::RUNNING, 'excluded_metadata_count' => 3, 'retained_output_count' => 1]);
    MediaTranscode::factory()->for($run, 'run')->create(['source_path' => '/media/encoding.mkv', 'status' => TaskStatus::RUNNING, 'encoder' => 'libx265', 'progress_percent' => 37.5, 'started_at' => now()->subSeconds(90)]);
    MediaTranscode::factory()->for($run, 'run')->create(['source_path' => '/media/ready.mkv', 'status' => TaskStatus::COMPLETED, 'output_size_bytes' => 2048]);
    MediaTranscode::factory()->for($run, 'run')->create(['source_path' => '/media/failed.mkv', 'status' => TaskStatus::FAILED, 'error' => '<script>failure()</script>']);

    actingAs(User::factory()->create());
    Livewire::test(Queue::class)->assertSee('Archive movies')->assertSee('/media/encoding.mkv')
        ->assertSee('libx265')->assertSee('37.5%')->assertSee('90 seconds')->assertSee('Awaiting quality control')
        ->assertSee('3 excluded for missing metadata')->assertSee('1 retained output')
        ->assertSee('<script>failure()</script>')->assertDontSeeHtml('<script>failure()</script>');
});

it('paginates individual transcodes without dropping run controls', function (): void {
    $run = TranscodeRun::factory()->create(['status' => TaskStatus::RUNNING]);
    MediaTranscode::factory()->count(26)->for($run, 'run')->sequence(fn ($sequence): array => ['source_path' => '/media/item-'.$sequence->index.'.mkv'])->create();

    Livewire::test(Queue::class)->assertSee('/media/item-25.mkv')->assertDontSee('/media/item-0.mkv')
        ->call('nextPage', 'filesPage')->assertSee('/media/item-0.mkv')->assertDontSee('/media/item-25.mkv')->assertSee('Cancel run');
});

it('rejects run actions after the authenticated session expires', function (string $action): void {
    $run = TranscodeRun::factory()->create();
    actingAs(User::factory()->create());
    $page = get(route('queue.index'))->assertOk();
    Auth::logout();
    session()->invalidate();
    Auth::forgetGuards();

    postJson(Livewire::getUpdateUri(), livewireUpdatePayload($page, calls: [
        ['method' => $action, 'params' => [$run->id]],
    ]), ['X-Livewire' => 'true'])->assertUnauthorized();

    expect($run->refresh()->cancel_requested_at)->toBeNull()->and(TranscodeRun::query()->count())->toBe(1);
})->with(['cancel', 'retryFailed']);

it('cancels a requested run through the authenticated queue', function (): void {
    JobQueue::fake();
    $run = TranscodeRun::factory()->create(['status' => TaskStatus::QUEUED]);
    actingAs(User::factory()->create());

    Livewire::test(Queue::class)->call('cancel', $run->id)->assertHasNoErrors()
        ->assertDispatched('ts-ui:toast', title: 'Run cancellation requested.');

    expect($run->refresh()->cancel_requested_at)->not->toBeNull();
    JobQueue::assertNothingPushed();
});

it('requests a separate retry run for failed files through the queue', function (): void {
    JobQueue::fake();
    $run = TranscodeRun::factory()->create(['status' => TaskStatus::FAILED, 'enumeration_finished_at' => now(), 'finished_at' => now()]);
    MediaTranscode::factory()->for($run, 'run')->create(['status' => TaskStatus::FAILED]);
    actingAs(User::factory()->create());

    Livewire::test(Queue::class)->assertSee('Retry failed')->call('retryFailed', $run->id)->assertHasNoErrors()
        ->assertDispatched('ts-ui:toast', title: 'Failed transcodes requested again.');

    expect(TranscodeRun::query()->where('retry_of_run_id', $run->id)->exists())->toBeTrue();
    JobQueue::assertPushed(QueueLibraryTranscodes::class);
});

it('shows retry validation when a run can no longer be retried', function (): void {
    JobQueue::fake();
    $run = TranscodeRun::factory()->create(['status' => TaskStatus::FAILED, 'enumeration_finished_at' => now(), 'finished_at' => now()]);
    MediaTranscode::factory()->for($run, 'run')->create(['status' => TaskStatus::FAILED]);
    $run->library()->firstOrFail()->delete();
    actingAs(User::factory()->create());

    Livewire::test(Queue::class)->call('retryFailed', $run->id)->assertHasErrors('run')
        ->assertSee('Only a finished run with failed files and an existing library can be retried.');

    expect(TranscodeRun::query()->count())->toBe(1);
    JobQueue::assertNothingPushed();
});

it('shows skipped totals without suggesting an output is awaiting quality control', function (): void {
    $run = TranscodeRun::factory()->create(['status' => TaskStatus::COMPLETED, 'finished_at' => now()]);
    MediaTranscode::factory()->count(2)->for($run, 'run')->create(['status' => TaskStatus::SKIPPED]);
    Livewire::test(Queue::class)->assertSee('2 skipped')->assertSee('Completed')->assertDontSee('Awaiting quality control');
});
