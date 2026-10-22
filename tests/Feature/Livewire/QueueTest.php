<?php

use App\Jobs\ScanLibraryPath;
use App\Livewire\Queue;
use App\Models\Library;
use App\Models\LibraryPath;
use App\Models\User;
use App\Scan\Enums\ScanStatus;
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
        'scan_status' => ScanStatus::Scanning, 'scan_requested_at' => now(), 'scan_started_at' => now(),
    ]);
    $waiting = LibraryPath::factory()->for($library)->atPath('/media/waiting')->create([
        'scan_status' => ScanStatus::Queued, 'scan_requested_at' => now(),
    ]);
    LibraryPath::factory()->for($library)->atPath('/media/failed')->create([
        'scan_status' => ScanStatus::Failed, 'scan_requested_at' => now(), 'scan_error' => 'Storage unavailable.',
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
    $root = LibraryPath::factory()->create(['scan_status' => ScanStatus::Scanning, 'scan_requested_at' => now()]);
    JobQueue::push(new ScanLibraryPath($root->id, (string) Str::uuid()));
    $job = JobQueue::pop();

    if ($job === null) {
        throw new LogicException('The processing fixture must be reserved.');
    }

    $component = Livewire::test(Queue::class);
    $component->assertSee('Queue empty')->assertSee('No transcoding jobs yet');

    $job->delete();
    $root->update(['scan_status' => ScanStatus::Completed, 'scan_finished_at' => now()]);
    $component->call('$refresh')->assertSee('Queue empty')->assertSee('No transcoding jobs yet')
        ->assertDontSee('Scan completed');
});

it('ignores old scan status links on the transcoding queue', function (): void {
    LibraryPath::factory()->atPath('/media/pending')->create(['scan_status' => ScanStatus::Queued]);
    LibraryPath::factory()->atPath('/media/completed')->create(['scan_status' => ScanStatus::Completed]);
    LibraryPath::factory()->atPath('/media/failed')->create(['scan_status' => ScanStatus::Failed]);

    Livewire::withQueryParams(['status' => 'failed'])->test(Queue::class)
        ->assertSee('No transcoding jobs yet')->assertDontSee('Scan status')
        ->assertDontSee('/media/failed')->assertDontSee('/media/pending')->assertDontSee('/media/completed');
});

it('does not paginate library scans on the transcoding queue', function (): void {
    $library = Library::factory()->create();

    for ($index = 0; $index < 26; $index++) {
        LibraryPath::factory()->for($library)->atPath('/media/folder-'.$index)->create([
            'scan_status' => $index === 0 ? ScanStatus::Failed : ScanStatus::Completed,
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
        'scan_status' => ScanStatus::Failed, 'scan_error' => $error,
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
