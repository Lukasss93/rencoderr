<?php

use App\Encoding\Actions\RequestMediaProbe;
use App\Encoding\Enums\ProbeStatus;
use App\Jobs\ProbeMediaFile;
use App\Jobs\ScanLibraryPath;
use App\Models\LibraryPath;
use App\Models\MediaFile;
use App\Scan\Enums\MediaFileStatus;
use App\Scan\Enums\ScanStatus;
use App\Scan\ExclusionMatcher;
use App\Scan\LibraryPathResolver;
use App\Scan\MediaScanner;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

use function Pest\Laravel\assertDatabaseCount;
use function Pest\Laravel\freezeTime;
use function Pest\Laravel\travel;

beforeEach(fn () => Queue::fake([ProbeMediaFile::class]));

it('discovers nested videos with metadata and ignores sidecars and links', function (): void {
    freezeTime();
    $directory = mediaDirectory([
        'Movie.MKV' => 'video',
        'Shows/episode.mp4' => 'episode',
        '.hidden/clip.MOV' => 'hidden',
        'Movie.srt' => 'subtitles',
        'cover.jpg' => 'image',
        'music.mp3' => 'audio',
    ]);
    $external = mediaDirectory(['outside.mkv' => 'outside']);
    symlink($external, $directory.'/linked-folder');
    symlink($directory.'/Movie.MKV', $directory.'/linked-file.mkv');
    touch($directory.'/Movie.MKV', 1700000000);
    $root = LibraryPath::factory()->atPath($directory)->create();

    scanMediaPath($root);

    assertDatabaseCount('media_files', 3);
    $movie = $root->mediaFiles()->where('relative_path', 'Movie.MKV')->firstOrFail();
    expect($movie->extension)->toBe('mkv')
        ->and($movie->size_bytes)->toBe(5)
        ->and($movie->filesystem_modified_at->timestamp)->toBe(1700000000)
        ->and($movie->status)->toBe(MediaFileStatus::Present)
        ->and($root->refresh()->scan_status)->toBe(ScanStatus::Completed)
        ->and($root->last_successful_scan_at)->not->toBeNull();
});

it('updates metadata without duplicates and preserves the first discovery', function (): void {
    freezeTime();
    $directory = mediaDirectory(['movie.mkv' => 'original']);
    $root = LibraryPath::factory()->atPath($directory)->create();
    scanMediaPath($root);
    $file = $root->mediaFiles()->firstOrFail();
    $firstSeen = $file->first_seen_at;
    travel(20)->minutes();
    File::put($directory.'/movie.mkv', 'a longer video');
    touch($directory.'/movie.mkv', 1700000001);
    scanMediaPath($root);

    assertDatabaseCount('media_files', 1);
    expect($file->refresh()->size_bytes)->toBe(14)
        ->and($file->filesystem_modified_at->timestamp)->toBe(1700000001)
        ->and($file->first_seen_at->equalTo($firstSeen))->toBeTrue()
        ->and($file->last_seen_at->greaterThan($firstSeen))->toBeTrue();
});

it('retains missing files and restores them when they return', function (): void {
    $directory = mediaDirectory(['movie.mkv' => 'video', 'movie.srt' => 'subtitles']);
    $root = LibraryPath::factory()->atPath($directory)->create();
    scanMediaPath($root);
    $file = $root->mediaFiles()->firstOrFail();
    File::delete($directory.'/movie.mkv');

    scanMediaPath($root);

    expect($file->refresh()->status)->toBe(MediaFileStatus::Missing)
        ->and($file->size_bytes)->toBe(5);
    File::put($directory.'/movie.mkv', 'returned');

    scanMediaPath($root);

    assertDatabaseCount('media_files', 1);
    expect($file->refresh()->status)->toBe(MediaFileStatus::Present)->and($file->size_bytes)->toBe(8);
});

it('keeps renamed paths as missing and discovers the new path', function (): void {
    $directory = mediaDirectory(['old.mkv' => 'video']);
    $root = LibraryPath::factory()->atPath($directory)->create();
    scanMediaPath($root);
    rename($directory.'/old.mkv', $directory.'/new.mkv');

    scanMediaPath($root);

    assertDatabaseCount('media_files', 2);
    expect($root->mediaFiles()->where('relative_path', 'old.mkv')->firstOrFail()->status)->toBe(MediaFileStatus::Missing)
        ->and($root->mediaFiles()->where('relative_path', 'new.mkv')->firstOrFail()->status)->toBe(MediaFileStatus::Present);
});

it('excludes matching directories and filenames and restores known files after rules change', function (): void {
    $directory = mediaDirectory([
        'Extras/trailer.mkv' => 'extra',
        'Shows/sample.mkv' => 'sample',
        'Shows/episode.mkv' => 'episode',
    ]);
    $root = LibraryPath::factory()->atPath($directory)->create();
    scanMediaPath($root);

    scanMediaPath($root, ['Extras/*', '*sample*']);

    expect($root->mediaFiles()->where('status', MediaFileStatus::Excluded)->count())->toBe(2)
        ->and($root->mediaFiles()->where('status', MediaFileStatus::Present)->count())->toBe(1);
    File::delete($directory.'/Shows/sample.mkv');

    scanMediaPath($root, ['Extras/*', '*sample*']);

    expect($root->mediaFiles()->where('relative_path', 'Shows/sample.mkv')->firstOrFail()->status)->toBe(MediaFileStatus::Excluded);
    scanMediaPath($root);

    expect($root->mediaFiles()->where('relative_path', 'Extras/trailer.mkv')->firstOrFail()->status)->toBe(MediaFileStatus::Present)
        ->and($root->mediaFiles()->where('relative_path', 'Shows/sample.mkv')->firstOrFail()->status)->toBe(MediaFileStatus::Missing);
});

it('does not create inventory records for undiscovered excluded files', function (): void {
    $root = LibraryPath::factory()->atPath(mediaDirectory(['Extras/trailer.mkv' => 'extra', 'movie.mkv' => 'movie']))->create();

    scanMediaPath($root, ['Extras']);

    assertDatabaseCount('media_files', 1);
    expect($root->mediaFiles()->firstOrFail()->relative_path)->toBe('movie.mkv');
});

it('matches exclusion wildcards and directory ancestry consistently', function (string $path, string $pattern, bool $matches): void {
    expect(app(ExclusionMatcher::class)->matches($path, [$pattern]))->toBe($matches);
})->with([
    'directory name' => ['Shows/Extras/trailer.mkv', 'Extras', true],
    'root directory wildcard' => ['Extras/trailer.mkv', 'Extras/*', true],
    'relative path wildcard' => ['Shows/Season 1/episode.mkv', 'Shows/Season*/episode.mkv', true],
    'filename' => ['Shows/episode.mkv', 'episode.mkv', true],
    'case sensitive' => ['Shows/Sample.mkv', '*sample*', false],
    'literal question mark' => ['Shows/episode.mkv', 'episod?.mkv', false],
    'distinct prefix' => ['Extras-other/clip.mkv', 'Extras', false],
    'file is not a directory' => ['movie.mkv', '*.mkv/*', false],
    'nested directory wildcard' => ['Shows/Extras/clip.mkv', 'Extras/*', true],
]);

it('preserves availability when a configured root becomes inaccessible', function (): void {
    $directory = mediaDirectory(['movie.mkv' => 'video']);
    $root = LibraryPath::factory()->atPath($directory)->create();
    scanMediaPath($root);
    $success = $root->refresh()->last_successful_scan_at?->toDateTimeString();
    expect($success)->not->toBeNull();
    File::deleteDirectory($directory);

    expect(fn () => scanMediaPath($root))->toThrow(ValidationException::class);

    expect($root->refresh()->scan_status)->toBe(ScanStatus::Failed)
        ->and($root->scan_error)->toContain('readable')
        ->and($root->last_successful_scan_at?->toDateTimeString())->toBe($success)
        ->and($root->mediaFiles()->firstOrFail()->status)->toBe(MediaFileStatus::Present);
});

it('treats an emptied folder with known videos as unavailable, such as an unmounted network share', function (): void {
    $directory = mediaDirectory(['movie.mkv' => 'video']);
    $root = LibraryPath::factory()->atPath($directory)->create();
    scanMediaPath($root);
    File::delete($directory.'/movie.mkv');

    expect(fn () => scanMediaPath($root))->toThrow(RuntimeException::class, 'check that it is mounted');

    expect($root->refresh()->scan_status)->toBe(ScanStatus::Failed)
        ->and($root->scan_error)->toContain('check that it is mounted')
        ->and($root->mediaFiles()->firstOrFail()->status)->toBe(MediaFileStatus::Present);
});

it('completes scans of empty folders without known present videos', function (): void {
    $directory = mediaDirectory();
    $root = LibraryPath::factory()->atPath($directory)->create();
    $missing = MediaFile::factory()->for($root, 'libraryPath')->missing()->create();

    scanMediaPath($root);

    expect($root->refresh()->scan_status)->toBe(ScanStatus::Completed)
        ->and($missing->refresh()->status)->toBe(MediaFileStatus::Missing);
});

it('preserves unseen availability after a partial traversal while keeping completed batches', function (): void {
    $directory = mediaDirectory(['observed.mkv' => 'observed']);
    $root = LibraryPath::factory()->atPath($directory)->create();
    $unseen = MediaFile::factory()->for($root, 'libraryPath')->atPath('unseen.mkv')->create();
    $token = (string) Str::uuid();
    $root->update(['scan_status' => ScanStatus::Queued, 'scan_token' => $token]);
    $scanner = new class(app(ExclusionMatcher::class), app(LibraryPathResolver::class), app(RequestMediaProbe::class)) extends MediaScanner
    {
        /** @return Generator<int, SplFileInfo> */
        protected function files(string $root, array $patterns): Generator
        {
            for ($index = 0; $index < 200; $index++) {
                yield new SplFileInfo($root.'/observed.mkv');
            }

            throw new RuntimeException('Storage became unavailable.');
        }
    };

    app()->instance(MediaScanner::class, $scanner);

    expect(fn () => ScanLibraryPath::dispatchSync($root->id, $token))
        ->toThrow(RuntimeException::class, 'Storage became unavailable.');

    expect($root->refresh()->scan_status)->toBe(ScanStatus::Failed)
        ->and($unseen->refresh()->status)->toBe(MediaFileStatus::Present)
        ->and($root->mediaFiles()->where('relative_path', 'observed.mkv')->firstOrFail()->status)->toBe(MediaFileStatus::Present);
    expect($root->mediaFiles()->where('relative_path', 'observed.mkv')->firstOrFail()->probe()->sole()->status)
        ->toBe(ProbeStatus::Pending);
    Queue::assertNotPushed(ProbeMediaFile::class);
});

it('handles more than one write batch and never changes source files', function (): void {
    $files = [];

    for ($index = 0; $index < 205; $index++) {
        $files['movie-'.$index.'.mkv'] = 'video-'.$index;
    }

    $directory = mediaDirectory($files);
    $root = LibraryPath::factory()->atPath($directory)->create();
    $modified = filemtime($directory.'/movie-0.mkv');

    scanMediaPath($root);
    scanMediaPath($root);

    assertDatabaseCount('media_files', 205);
    assertDatabaseCount('media_probes', 205);
    Queue::assertPushed(ProbeMediaFile::class, 205);
    foreach ($files as $name => $contents) {
        expect(File::get($directory.'/'.$name))->toBe($contents);
    }
    expect(filemtime($directory.'/movie-0.mkv'))->toBe($modified);
});

it('fails safely on unreadable subfolders and prunes them when excluded', function (): void {
    $directory = mediaDirectory(['restricted/movie.mkv' => 'video']);
    $root = LibraryPath::factory()->atPath($directory)->create();
    $file = MediaFile::factory()->for($root, 'libraryPath')->atPath('restricted/movie.mkv')->create();
    chmod($directory.'/restricted', 0000);

    try {
        expect(fn () => scanMediaPath($root))->toThrow(RuntimeException::class, 'Could not read directory');
        expect($file->refresh()->status)->toBe(MediaFileStatus::Present);
        scanMediaPath($root, ['restricted']);
        expect($root->refresh()->scan_status)->toBe(ScanStatus::Completed)
            ->and($file->refresh()->status)->toBe(MediaFileStatus::Excluded);
    } finally {
        chmod($directory.'/restricted', 0755);
    }
});
