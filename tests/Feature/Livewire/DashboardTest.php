<?php

use App\Jobs\QueueLibraryTranscodes;
use App\Jobs\TranscodeMediaFile;
use App\Livewire\Dashboard;
use App\Livewire\Library\ShowMedia;
use App\Models\Library;
use App\Models\LibraryPath;
use App\Models\MediaFile;
use App\Models\MediaQualityCheck;
use App\Models\MediaTranscode;
use App\Models\TranscodeRun;
use App\Models\User;
use App\QualityControl\Enums\QualityVerdict;
use App\Tasks\Enums\TaskStatus;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\postJson;

require_once __DIR__.'/../../Support/TranscodeFixtures.php';

it('links to media details and queues a single file from the dashboard', function (): void {
    Queue::fake();
    $file = transcodeSource();
    transcodeSource($file->libraryPath()->firstOrFail()->library()->firstOrFail());
    actingAs(User::factory()->create());

    Livewire::test(Dashboard::class)->assertSee(route('media.show', $file), false)
        ->call('transcodeFile', $file->id)->assertHasNoErrors()
        ->assertDispatched('ts-ui:toast', title: 'Media transcode requested.');

    expect(TranscodeRun::query()->sole()->transcodes()->sole()->media_file_id)->toBe($file->id);
    Queue::assertPushed(TranscodeMediaFile::class, 1);
    Queue::assertNotPushed(QueueLibraryTranscodes::class);
});

it('requires a valid selected library before starting a library transcode', function (string $selection): void {
    Queue::fake();

    Livewire::test(Dashboard::class)->set('library', $selection)->call('transcodeLibrary')->assertHasErrors('library');

    expect(TranscodeRun::query()->count())->toBe(0);
    Queue::assertNothingPushed();
})->with(['', 'invalid', '999999']);

it('starts the whole selected library regardless of dashboard search or availability filters', function (): void {
    Queue::fake();
    $file = transcodeSource();
    $other = transcodeSource($file->libraryPath()->firstOrFail()->library()->firstOrFail());

    Livewire::test(Dashboard::class)->set('library', (string) $file->libraryPath()->firstOrFail()->library_id)
        ->set('search', 'no matching file')->set('status', 'missing')
        ->call('transcodeLibrary')->assertHasNoErrors();

    $run = TranscodeRun::query()->sole();
    app()->call([new QueueLibraryTranscodes($run->id, $run->request_token), 'handle']);
    expect($run->transcodes()->pluck('media_file_id')->all())->toBe([$file->id, $other->id]);
    Queue::assertPushed(TranscodeMediaFile::class, 2);
});

it('shows why a media file cannot be transcoded and rejects direct requests', function (): void {
    Queue::fake();
    $file = MediaFile::factory()->missing()->create();

    Livewire::test(Dashboard::class)->assertSee('Only present files can be transcoded.')
        ->call('transcodeFile', $file->id)->assertHasErrors('transcode');

    expect(TranscodeRun::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('does not describe another files retained output when reusing an active run', function (): void {
    Queue::fake();
    $file = transcodeSource();
    $run = TranscodeRun::factory()->for($file->libraryPath()->firstOrFail()->library()->firstOrFail())
        ->create(['status' => TaskStatus::RUNNING, 'retained_output_count' => 1]);
    MediaTranscode::factory()->for($run, 'run')->for($file)->create(['status' => TaskStatus::QUEUED]);

    Livewire::test(Dashboard::class)->call('transcodeFile', $file->id)->assertHasNoErrors()
        ->assertDispatched('ts-ui:toast', title: 'This media already has a transcode in the current run.');

    expect(TranscodeRun::query()->count())->toBe(1);
    Queue::assertNothingPushed();
});

it('describes retained output without claiming its quality check is still waiting', function (string $page): void {
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
    MediaQualityCheck::factory()->for($item, 'transcode')->create([
        'status' => TaskStatus::COMPLETED, 'verdict' => QualityVerdict::PASSED,
    ]);
    Storage::disk('transcodes')->put('retained/output.mkv', 'retained output');

    $component = $page === 'dashboard' ? Livewire::test(Dashboard::class)
        : Livewire::test(ShowMedia::class, ['mediaFile' => $file]);
    $component->call($page === 'dashboard' ? 'transcodeFile' : 'transcode', ...($page === 'dashboard' ? [$file->id] : []))
        ->assertHasNoErrors()->assertDispatched('ts-ui:toast', title: 'An unchanged encoded output already exists.');

    Queue::assertNothingPushed();
})->with(['dashboard', 'details']);

it('shows tracked metadata and calculates present bytes separately from other statuses', function (): void {
    $library = Library::factory()->create(['name' => 'Movies']);
    $root = LibraryPath::factory()->for($library)->atPath('/media/movies')->create();
    MediaFile::factory()->for($root, 'libraryPath')->atPath('Movies/Film.mkv')->create(['size_bytes' => 2147483648]);
    MediaFile::factory()->for($root, 'libraryPath')->missing()->create();
    MediaFile::factory()->for($root, 'libraryPath')->excluded()->create();

    actingAs(User::factory()->create())->get(route('home'))->assertOk()->assertSee('Film.mkv')->assertSee('/media/movies');
    Livewire::test(Dashboard::class)->assertViewHas('counts', ['present' => 1, 'missing' => 1, 'excluded' => 1])
        ->assertViewHas('presentSize', '2 GB');
});

it('filters by filename, relative path, library, and availability', function (): void {
    $movies = Library::factory()->create();
    $shows = Library::factory()->create();
    $movieRoot = LibraryPath::factory()->for($movies)->create();
    $showRoot = LibraryPath::factory()->for($shows)->create();
    MediaFile::factory()->for($movieRoot, 'libraryPath')->atPath('Drama/My Film.mkv')->create();
    MediaFile::factory()->for($showRoot, 'libraryPath')->atPath('Series/Episode.mkv')->missing()->create();

    $component = Livewire::test(Dashboard::class);
    $component->set('search', 'My Film')->assertSee('My Film.mkv')->assertDontSee('Episode.mkv')
        ->set('search', 'Drama/')->assertSee('My Film.mkv')
        ->set('search', '')->set('library', (string) $shows->id)->assertSee('Episode.mkv')->assertDontSee('My Film.mkv')
        ->assertViewHas('counts', ['present' => 0, 'missing' => 1, 'excluded' => 0]);
    $component->set('library', '')->set('status', 'present')->assertSee('My Film.mkv')->assertDontSee('Episode.mkv');
});

it('matches wildcard characters in searches literally and ignores letter case', function (): void {
    $root = LibraryPath::factory()->create();

    foreach (['S01_E01.mkv', 'S01xE01.mkv', '100% Pure.mkv', '1000 Pure.mkv', 'Wow!.mkv', 'Wowza.mkv'] as $path) {
        MediaFile::factory()->for($root, 'libraryPath')->atPath($path)->create();
    }

    Livewire::test(Dashboard::class)
        ->set('search', 'S01_E01')->assertSee('S01_E01.mkv')->assertDontSee('S01xE01.mkv')
        ->set('search', '100%')->assertSee('100% Pure.mkv')->assertDontSee('1000 Pure.mkv')
        ->set('search', 'Wow!')->assertSee('Wow!.mkv')->assertDontSee('Wowza.mkv')
        ->set('search', 'pure')->assertSee('100% Pure.mkv')->assertSee('1000 Pure.mkv');
});

it('ignores library and availability filters that are not valid values', function (): void {
    $root = LibraryPath::factory()->create();
    MediaFile::factory()->for($root, 'libraryPath')->atPath('Movie.mkv')->create();

    Livewire::withQueryParams(['library' => 'abc', 'status' => 'unknown'])->test(Dashboard::class)
        ->assertOk()->assertSee('Movie.mkv')->assertViewHas('counts', ['present' => 1, 'missing' => 0, 'excluded' => 0]);
});

it('paginates deterministically and returns to page one when a filter changes', function (): void {
    $root = LibraryPath::factory()->create();

    for ($index = 0; $index < 26; $index++) {
        MediaFile::factory()->for($root, 'libraryPath')->atPath(sprintf('film-%02d.mkv', $index))->create();
    }

    $component = Livewire::test(Dashboard::class);
    $component->assertViewHas('files', fn (LengthAwarePaginator $files): bool => count($files->items()) === 25 && $files->total() === 26);
    $component->assertSee('film-00.mkv')->assertDontSee('film-25.mkv')
        ->call('setPage', 2)->assertSee('film-25.mkv')->assertDontSee('film-00.mkv')
        ->set('search', 'film-00')->assertSee('film-00.mkv')->assertSet('paginators.page', 1);
});

it('escapes filenames and library names in inventory output', function (): void {
    $library = Library::factory()->create(['name' => '<script>library()</script>']);
    $root = LibraryPath::factory()->for($library)->create();
    $filename = '<img src=x onerror=alert(1)>.mkv';
    MediaFile::factory()->for($root, 'libraryPath')->atPath($filename)->create();

    actingAs(User::factory()->create())->get(route('home'))->assertOk()->assertSee($filename)->assertDontSee($filename, false)
        ->assertSee($library->name)->assertDontSee($library->name, false);
});

it('shows an empty inventory without requiring an account when authentication is disabled', function (): void {
    Config::set('auth.enabled', false);

    get(route('home'))->assertOk()->assertSee('Media inventory')->assertSee('No video files match these filters');
    get(route('settings.libraries'))->assertOk()->assertSee('Add your first library');
});

it('requests a library transcode through the dashboard', function (): void {
    Queue::fake();
    $library = Library::factory()->create(['name' => 'Movie archive']);
    actingAs(User::factory()->create());

    Livewire::test(Dashboard::class)->assertSee('Transcode library')->set('library', (string) $library->id)->call('transcodeLibrary')
        ->assertHasNoErrors()->assertDispatched('ts-ui:toast', title: 'Library transcode requested.');

    expect(TranscodeRun::query()->where('library_id', $library->id)->firstOrFail()->library_name)->toBe('Movie archive');
    Queue::assertPushed(QueueLibraryTranscodes::class);
});

it('rejects library transcodes after the authenticated session expires', function (): void {
    Queue::fake();
    $library = Library::factory()->create();
    actingAs(User::factory()->create());
    $page = get(route('home'))->assertOk();
    Auth::logout();
    session()->invalidate();
    Auth::forgetGuards();

    postJson(Livewire::getUpdateUri(), livewireUpdatePayload($page, updates: ['library' => (string) $library->id], calls: [
        ['method' => 'transcodeLibrary', 'params' => []],
    ]), ['X-Livewire' => 'true'])->assertUnauthorized();

    expect(TranscodeRun::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('keeps repeated library transcode requests on the existing active run', function (): void {
    Queue::fake();
    $library = Library::factory()->create();
    actingAs(User::factory()->create());

    Livewire::test(Dashboard::class)->set('library', (string) $library->id)->call('transcodeLibrary')->set('library', (string) $library->id)->call('transcodeLibrary')->assertHasNoErrors();

    expect(TranscodeRun::query()->where('library_id', $library->id)->count())->toBe(1);
    Queue::assertPushed(QueueLibraryTranscodes::class, 1);
});
