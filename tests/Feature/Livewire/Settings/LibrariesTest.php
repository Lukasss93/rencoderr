<?php

use App\Jobs\ScanLibraryPath;
use App\Library\DirectoryBrowser;
use App\Library\LibraryPathResolver;
use App\Livewire\Settings\Libraries;
use App\Models\Library;
use App\Models\LibraryPath;
use App\Models\MediaFile;
use App\Models\User;
use App\Tasks\Enums\TaskStatus;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseCount;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;
use function Pest\Laravel\freezeTime;
use function Pest\Laravel\get;
use function Pest\Laravel\postJson;

it('requires authentication for library management', function (): void {
    get(route('settings.libraries'))->assertRedirectToRoute('login');
});

it('creates a shared library with multiple roots and queues their initial scans', function (): void {
    Queue::fake([ScanLibraryPath::class]);
    actingAs(User::factory()->create());
    $first = mediaDirectory();
    $second = mediaDirectory();

    Livewire::test(Libraries::class)->call('create')
        ->set('form.name', 'Movies')->set('form.paths', [$first, $second])
        ->set('form.interval', '2')->set('form.intervalUnit', 'hours')
        ->set('form.exclusions', "Extras\n*sample*\n\nExtras")
        ->call('save')->assertHasNoErrors()->assertSet('showEditor', false)
        ->assertDispatched('ts-ui:toast', type: 'success', title: 'Library saved.');

    $library = Library::query()->firstOrFail();
    expect($library->scan_interval_minutes)->toBe(120)
        ->and($library->exclusion_patterns)->toBe(['Extras', '*sample*'])
        ->and($library->paths()->count())->toBe(2)
        ->and($library->last_scan_requested_at)->not->toBeNull();
    Queue::assertPushed(ScanLibraryPath::class, 2);
    assertDatabaseHas('library_paths', ['path' => $first, 'path_hash' => hash('sha256', $first), 'scan_status' => 'queued']);
    actingAs(User::factory()->create())->get(route('settings.libraries'))->assertOk()->assertSee('Movies');
});

it('accepts custom intervals in each supported unit', function (string $value, string $unit, int $minutes): void {
    Queue::fake([ScanLibraryPath::class]);

    Livewire::test(Libraries::class)->set('form.name', 'Videos')->set('form.paths', [mediaDirectory()])
        ->set('form.interval', $value)->set('form.intervalUnit', $unit)->call('save')->assertHasNoErrors();

    expect(Library::query()->firstOrFail()->scan_interval_minutes)->toBe($minutes);
    Queue::assertPushed(ScanLibraryPath::class, 1);
})->with([
    'minimum' => ['1', 'minutes', 1],
    'custom minutes' => ['37', 'minutes', 37],
    'hours' => ['3', 'hours', 180],
    'days' => ['2', 'days', 2880],
]);

it('rejects invalid library inputs', function (string $field, mixed $value, string $error): void {
    Queue::fake([ScanLibraryPath::class]);
    $component = Livewire::test(Libraries::class)->set('form.name', 'Videos')->set('form.paths', [mediaDirectory()]);
    $component->set('form.'.$field, $value)->call('save')->assertHasErrors(['form.'.$error]);

    assertDatabaseCount('libraries', 0);
    Queue::assertNothingPushed();
})->with([
    'blank name' => ['name', '', 'name'],
    'no roots' => ['paths', [], 'paths'],
    'relative root' => ['paths', ['relative/path'], 'paths.0'],
    'nonexistent root' => ['paths', ['/folder-that-does-not-exist-rencoderr'], 'paths.0'],
    'zero interval' => ['interval', '0', 'interval'],
    'fractional interval' => ['interval', '1.5', 'interval'],
    'invalid unit' => ['intervalUnit', 'seconds', 'intervalUnit'],
    'overflow interval' => ['interval', '2147483648', 'interval'],
]);

it('asks to try again when another library save is still in progress', function (): void {
    Queue::fake([ScanLibraryPath::class]);
    Sleep::fake(syncWithCarbon: true);
    Cache::lock('library-path-management', 30)->get();

    Livewire::test(Libraries::class)->set('form.name', 'Videos')->set('form.paths', [mediaDirectory()])
        ->call('save')->assertHasErrors(['form.paths' => 'Another library is being saved. Try again in a moment.']);

    assertDatabaseCount('libraries', 0);
    Queue::assertNothingPushed();
});

it('rejects duplicate canonical roots even through symbolic aliases', function (): void {
    Queue::fake([ScanLibraryPath::class]);
    $directory = mediaDirectory();
    $aliases = mediaDirectory();
    symlink($directory, $aliases.'/alias');

    Livewire::test(Libraries::class)->set('form.name', 'Videos')
        ->set('form.paths', [$directory.'/', $aliases.'/alias'])->call('save')->assertHasErrors(['form.paths.1']);

    assertDatabaseCount('libraries', 0);
    Queue::assertNothingPushed();
});

it('rejects root overlaps across libraries in either direction', function (bool $parentAlreadyConfigured): void {
    Queue::fake([ScanLibraryPath::class]);
    $directory = mediaDirectory();
    File::ensureDirectoryExists($directory.'/movies');
    $existing = $parentAlreadyConfigured ? $directory : $directory.'/movies';
    $proposed = $parentAlreadyConfigured ? $directory.'/movies' : $directory;
    LibraryPath::factory()->atPath($existing)->create();

    Livewire::test(Libraries::class)->set('form.name', 'Other videos')->set('form.paths', [$proposed])
        ->call('save')->assertHasErrors(['form.paths.0']);

    assertDatabaseCount('libraries', 1);
    Queue::assertNothingPushed();
})->with([true, false]);

it('accepts sibling roots with similar prefixes', function (): void {
    Queue::fake([ScanLibraryPath::class]);
    $directory = mediaDirectory();
    File::ensureDirectoryExists($directory.'/media');
    File::ensureDirectoryExists($directory.'/media-old');
    LibraryPath::factory()->atPath($directory.'/media')->create();

    Livewire::test(Libraries::class)->set('form.name', 'Other videos')->set('form.paths', [$directory.'/media-old'])
        ->call('save')->assertHasNoErrors();

    assertDatabaseCount('library_paths', 2);
    Queue::assertPushed(ScanLibraryPath::class, 1);
});

it('edits settings while retaining an unavailable root and its inventory', function (): void {
    Queue::fake([ScanLibraryPath::class]);
    $library = Library::factory()->create(['scan_interval_minutes' => 120]);
    $root = LibraryPath::factory()->for($library)->atPath(mediaDirectory())->create();
    $file = MediaFile::factory()->for($root, 'libraryPath')->create();
    File::deleteDirectory($root->path);

    Livewire::test(Libraries::class)->call('edit', $library->id)
        ->assertSet('form.interval', '2')->assertSet('form.intervalUnit', 'hours')
        ->set('form.name', 'Updated videos')->set('form.automaticScanning', false)->call('save')->assertHasNoErrors();

    expect($library->refresh()->name)->toBe('Updated videos')->and($library->automatic_scanning)->toBeFalse();
    assertDatabaseHas('media_files', ['id' => $file->id]);
    Queue::assertPushed(ScanLibraryPath::class, 1);
});

it('scans an added folder without delaying the automatic scan of existing folders', function (): void {
    Queue::fake([ScanLibraryPath::class]);
    freezeTime();
    $library = Library::factory()->create(['last_scan_requested_at' => now()->subMinutes(10)]);
    $existing = LibraryPath::factory()->for($library)->atPath(mediaDirectory())->create(['scan_status' => TaskStatus::COMPLETED]);
    $added = mediaDirectory();

    Livewire::test(Libraries::class)->call('edit', $library->id)->set('form.newPath', $added)->call('save')->assertHasNoErrors();

    Queue::assertPushed(ScanLibraryPath::class, 1);
    Queue::assertPushed(ScanLibraryPath::class, fn (ScanLibraryPath $job): bool => $job->pathId !== $existing->id);
    expect($library->refresh()->last_scan_requested_at?->toDateTimeString())->toBe(now()->subMinutes(10)->toDateTimeString());
});

it('rescans every folder when the exclusion patterns change', function (string $exclusions, int $scans): void {
    Queue::fake([ScanLibraryPath::class]);
    $library = Library::factory()->create(['exclusion_patterns' => ['Extras']]);
    LibraryPath::factory()->for($library)->atPath(mediaDirectory())->create(['scan_status' => TaskStatus::COMPLETED]);
    LibraryPath::factory()->for($library)->atPath(mediaDirectory())->create(['scan_status' => TaskStatus::COMPLETED]);

    Livewire::test(Libraries::class)->call('edit', $library->id)
        ->set('form.name', 'Renamed')->set('form.exclusions', $exclusions)->call('save')->assertHasNoErrors();

    Queue::assertPushed(ScanLibraryPath::class, $scans);
})->with([
    'patterns changed' => ["Extras\n*sample*", 2],
    'patterns unchanged' => ['Extras', 0],
]);

it('removes a folder inventory on save and leaves source media unchanged', function (): void {
    Queue::fake([ScanLibraryPath::class]);
    $library = Library::factory()->create();
    $first = LibraryPath::factory()->for($library)->atPath(mediaDirectory(['movie.mkv' => 'video']))->create();
    $second = LibraryPath::factory()->for($library)->atPath(mediaDirectory())->create();
    $file = MediaFile::factory()->for($first, 'libraryPath')->create();

    Livewire::test(Libraries::class)->call('edit', $library->id)->call('removePath', 0)->call('save')->assertHasNoErrors();

    assertDatabaseMissing('library_paths', ['id' => $first->id]);
    assertDatabaseMissing('media_files', ['id' => $file->id]);
    assertDatabaseHas('library_paths', ['id' => $second->id]);
    expect(File::get($first->path.'/movie.mkv'))->toBe('video');
    Queue::assertPushed(ScanLibraryPath::class, 1);
});

it('removes a library and its records without deleting source files', function (): void {
    $library = Library::factory()->create();
    $root = LibraryPath::factory()->for($library)->atPath(mediaDirectory(['movie.mkv' => 'video']))->create();
    MediaFile::factory()->for($root, 'libraryPath')->create();

    Livewire::test(Libraries::class)->call('delete', $library->id);

    assertDatabaseCount('libraries', 0);
    assertDatabaseCount('library_paths', 0);
    assertDatabaseCount('media_files', 0);
    expect(File::get($root->path.'/movie.mkv'))->toBe('video');
});

it('lists only directories with breadcrumbs and paginated navigation', function (): void {
    $directory = mediaDirectory(['video.mkv' => 'video']);

    for ($index = 0; $index < 51; $index++) {
        File::ensureDirectoryExists($directory.'/folder-'.$index);
    }

    $browser = app(DirectoryBrowser::class);
    $first = $browser->browse($directory);
    $second = $browser->browse($directory, 2);

    expect($first['total'])->toBe(51)->and($first['directories'])->toHaveCount(50)
        ->and($second['directories'])->toHaveCount(1)
        ->and($first['parent'])->toBe(dirname($directory))
        ->and($first['breadcrumbs'][0])->toBe(['name' => '/', 'path' => '/']);
});

it('navigates and selects server folders while preserving the last valid location on error', function (): void {
    $directory = mediaDirectory();
    File::ensureDirectoryExists($directory.'/movies');

    Livewire::test(Libraries::class)->call('create')->call('browse')
        ->set('browserPath', $directory.'/movies')->call('navigate')
        ->assertSet('listing.path', $directory.'/movies')
        ->assertSee('Parent folder')->set('browserPath', '/folder-that-does-not-exist-rencoderr')->call('navigate')
        ->assertHasErrors(['browserPath'])->assertSet('listing.path', $directory.'/movies')
        ->call('selectDirectory')->assertSet('form.paths', [$directory.'/movies'])->assertSet('showBrowser', false);
});

it('reads a browsed folder from disk only when navigating, not on every refresh', function (): void {
    $directory = mediaDirectory();
    File::ensureDirectoryExists($directory.'/movies');
    $browser = Mockery::mock(DirectoryBrowser::class, [app(LibraryPathResolver::class)])->makePartial();
    $browser->shouldReceive('browse')->twice()->passthru();
    app()->instance(DirectoryBrowser::class, $browser);

    Livewire::test(Libraries::class)->call('create')->call('browse')->call('navigate', $directory)
        ->call('$refresh')->call('$refresh')->assertSee('movies')->assertSet('listing.path', $directory);
});

it('rejects selecting a directory that disappeared after navigation', function (): void {
    $directory = mediaDirectory();
    $component = Livewire::test(Libraries::class)->call('create')->call('browse')->call('navigate', $directory);
    File::deleteDirectory($directory);

    $component->call('selectDirectory')->assertHasErrors(['browserPath'])->assertSet('form.paths', []);
});

it('renders working folder navigation arguments for paths containing special characters', function (): void {
    $parent = mediaDirectory();
    $name = "Films ' \" & < > 日本語 \\";
    $directory = $parent.'/'.$name;
    $childName = "00 ' \" & < > 日本語 \\";
    File::ensureDirectoryExists($directory.'/'.$childName);

    for ($index = 0; $index < 50; $index++) {
        File::ensureDirectoryExists($directory.'/folder-'.$index);
    }

    $component = Livewire::test(Libraries::class)->call('create')->call('browse')->call('navigate', $directory);
    $document = new DOMDocument;
    $document->loadHTML('<meta charset="utf-8">'.$component->html(), LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
    $actions = [];

    foreach ($document->getElementsByTagName('button') as $button) {
        $expression = $button->getAttribute('wire:click');

        if (! str_starts_with($expression, 'navigate(')) {
            continue;
        }

        $arguments = substr($expression, strlen('navigate('), -1);
        $actions[trim($button->textContent)] = json_decode('['.$arguments.']', true, flags: JSON_THROW_ON_ERROR);
    }

    expect($actions)->toHaveKeys([$name, $childName, 'Parent folder', 'Previous', 'Next']);
    expect($actions[$name])->toBe([$directory]);
    expect($actions[$childName])->toBe([$directory.'/'.$childName]);
    expect($actions['Parent folder'])->toBe([$parent]);
    expect($actions['Previous'])->toBe([$directory, 0]);
    expect($actions['Next'])->toBe([$directory, 2]);
});

it('validates manually added paths at save rather than trusting the selection list', function (): void {
    Livewire::test(Libraries::class)->call('create')->set('form.name', 'Videos')->set('form.newPath', 'relative-path')
        ->call('addPath')->assertSet('form.paths', ['relative-path'])
        ->call('save')->assertHasErrors(['form.paths.0']);
    assertDatabaseCount('libraries', 0);
});

it('allows library creation through a guest Livewire request when authentication is disabled', function (): void {
    Config::set('auth.enabled', false);
    Queue::fake([ScanLibraryPath::class]);
    $page = get(route('settings.libraries'))->assertOk();

    postJson(Livewire::getUpdateUri(), livewireUpdatePayload($page, [
        'form.name' => 'Local videos', 'form.paths' => [mediaDirectory()],
    ], [['method' => 'save', 'params' => []]]), ['X-Livewire' => 'true'])->assertOk();

    assertDatabaseHas('libraries', ['name' => 'Local videos']);
    assertDatabaseCount('users', 0);
    Queue::assertPushed(ScanLibraryPath::class, 1);
});

it('rejects a library mutation after the authenticated session expires', function (): void {
    $library = Library::factory()->create();
    Auth::login(User::factory()->create());
    $page = get(route('settings.libraries'))->assertOk();
    Auth::logout();
    session()->invalidate();
    Auth::forgetGuards();

    postJson(Livewire::getUpdateUri(), livewireUpdatePayload($page, [], [
        ['method' => 'delete', 'params' => [$library->id]],
    ]), ['X-Livewire' => 'true'])->assertUnauthorized();

    assertDatabaseHas('libraries', ['id' => $library->id]);
});

it('rejects non-directory and non-absolute folder selections', function (): void {
    $directory = mediaDirectory(['movie.mkv' => 'video']);

    expect(fn () => app(LibraryPathResolver::class)->canonical($directory.'/movie.mkv'))->toThrow(ValidationException::class)
        ->and(fn () => app(LibraryPathResolver::class)->canonical('relative-path'))->toThrow(ValidationException::class);
});

it('rejects unreadable folders and hides them from the folder picker', function (): void {
    $directory = mediaDirectory();
    File::ensureDirectoryExists($directory.'/restricted');
    chmod($directory.'/restricted', 0000);

    try {
        expect(fn () => app(LibraryPathResolver::class)->canonical($directory.'/restricted'))->toThrow(ValidationException::class);
        expect(app(DirectoryBrowser::class)->browse($directory)['directories'])->toBe([]);
    } finally {
        chmod($directory.'/restricted', 0755);
    }
});

it('rejects interval conversion that exceeds the stored minute range', function (): void {
    Livewire::test(Libraries::class)->set('form.name', 'Videos')->set('form.paths', [mediaDirectory()])
        ->set('form.interval', '2147483647')->set('form.intervalUnit', 'days')->call('save')->assertHasErrors(['form.interval']);
    assertDatabaseCount('libraries', 0);
});

it('keeps transcode launch controls out of library settings', function (): void {
    Library::factory()->create();
    Livewire::test(Libraries::class)->assertDontSee('Transcode library');
});
