<?php

use App\Jobs\ScanLibraryPath;
use App\Jobs\WriteMediaFile;
use App\Library\LibraryPathLock;
use App\Library\MediaScanner;
use App\Models\LibraryPath;
use App\Models\MediaWriteback;
use App\Tasks\Enums\TaskStatus;
use App\Writeback\Actions\RunWriteback;
use App\Writeback\WritebackFilesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/WritebackFixtures.php';

it('allows another connection to save progress during slow writeback work', function (string $phase): void {
    DB::select('SELECT 1');
    $original = DB::getDefaultConnection();
    $database = mediaDirectory().'/database.sqlite';
    touch($database);
    $configuration = array_replace(config()->array('database.connections.sqlite'), [
        'url' => null, 'database' => $database, 'busy_timeout' => 100,
    ]);
    config([
        'database.connections.writeback-concurrency' => $configuration,
        'database.connections.writeback-progress' => $configuration,
    ]);
    Artisan::call('migrate', ['--database' => 'writeback-concurrency', '--force' => true]);
    DB::setDefaultConnection('writeback-concurrency');
    $competing = DB::connection('writeback-progress');

    try {
        $receipt = writebackFixture();
        $progressSaved = false;
        MediaWriteback::retrieved(function (MediaWriteback $writeback) use ($competing, $phase, &$progressSaved): void {
            if ($phase === 'inventory verification' && ! $progressSaved && $writeback->status === TaskStatus::COMPLETED) {
                $progressSaved = true;
                $competing->table('media_transcodes')->where('id', $writeback->media_transcode_id)->update(['progress_percent' => 42]);
            }
        });
        $filesystem = Mockery::mock(WritebackFilesystem::class)->makePartial();
        $filesystem->shouldReceive('link')->with($receipt->candidate_path, $receipt->destination_path)
            ->once()->andReturnUsing(function (string $from, string $to) use ($competing, $receipt, $phase): void {
                if ($phase === 'installation') {
                    $competing->table('media_transcodes')->where('id', $receipt->media_transcode_id)->update(['progress_percent' => 42]);
                }
                (new WritebackFilesystem)->link($from, $to);
            });
        app()->instance(WritebackFilesystem::class, $filesystem);

        app(RunWriteback::class)->handle($receipt->id, $receipt->request_token);

        expect($receipt->refresh()->status)->toBe(TaskStatus::COMPLETED);
        expect($competing->table('media_transcodes')->where('id', $receipt->media_transcode_id)->value('progress_percent'))->toEqual(42);
        expect(file_get_contents($receipt->destination_path))->toBe('encoded media');
        expect($receipt->inventory_refreshed_at)->not->toBeNull();
    } finally {
        DB::setDefaultConnection($original);
        DB::purge('writeback-progress');
        DB::purge('writeback-concurrency');
    }
})->with(['installation', 'inventory verification']);

it('keeps a scan queued while replacement owns the library path', function (): void {
    $root = LibraryPath::factory()->atPath(mediaDirectory(['movie.mkv' => 'video']))->create();
    $token = (string) Str::uuid();
    $root->queueScan($token);
    $lock = LibraryPathLock::forPath($root->id, 60);
    $lock->get();
    $job = (new ScanLibraryPath($root->id, $token))->withFakeQueueInteractions();

    try {
        $job->handle(app(MediaScanner::class));

        $job->assertReleased(30);
        expect($root->refresh()->scan_status)->toBe(TaskStatus::QUEUED);
        expect($root->mediaFiles()->count())->toBe(0);
    } finally {
        $lock->release();
    }

    ScanLibraryPath::dispatchSync($root->id, $token);
    expect($root->refresh()->scan_status)->toBe(TaskStatus::COMPLETED);
    expect($root->mediaFiles()->count())->toBe(1);
});

it('keeps writeback queued while a scan owns the library path', function (): void {
    $receipt = writebackFixture('mp4');
    $pathId = $receipt->transcode()->firstOrFail()->mediaFile()->firstOrFail()->library_path_id;
    $lock = LibraryPathLock::forPath($pathId, 60);
    $lock->get();

    try {
        app(RunWriteback::class)->handle($receipt->id, $receipt->request_token);

        expect($receipt->refresh()->status)->toBe(TaskStatus::QUEUED);
        expect(file_get_contents($receipt->source_path))->toBe('original media');
        expect(file_exists($receipt->destination_path))->toBeFalse();
    } finally {
        $lock->release();
    }

    app(RunWriteback::class)->handle($receipt->id, $receipt->request_token);
    expect($receipt->refresh()->status)->toBe(TaskStatus::COMPLETED);
});

it('fails writeback without touching files after its library is deleted', function (bool $recover): void {
    $receipt = writebackFixture();
    $receipt->transcode()->firstOrFail()->mediaFile()->firstOrFail()->libraryPath()->firstOrFail()->delete();
    Queue::fake([WriteMediaFile::class]);
    if ($recover) {
        artisanCommand('tasks:recover')->assertSuccessful();
        Queue::assertPushed(WriteMediaFile::class);
    } else {
        Queue::assertNothingPushed();
    }
    $job = (new WriteMediaFile($receipt->id, $receipt->refresh()->request_token))->withFakeQueueInteractions();

    $job->handle(app(RunWriteback::class));

    $job->assertNotReleased();
    expect($receipt->refresh()->status)->toBe(TaskStatus::FAILED);
    expect(file_get_contents($receipt->source_path))->toBe('original media');
    expect(file_get_contents($receipt->staging_path))->toBe('encoded media');
    expect(file_exists($receipt->candidate_path))->toBeFalse();
})->with([false, true]);

it('clears folder locks left after a terminal writeback state during startup', function (TaskStatus $status): void {
    $receipt = writebackFixture();
    $root = $receipt->transcode()->firstOrFail()->mediaFile()->firstOrFail()->libraryPath()->firstOrFail();
    $root->update(['scan_status' => TaskStatus::COMPLETED]);
    $receipt->update(['status' => $status, 'inventory_refreshed_at' => now(), 'staging_cleaned_at' => now()]);
    LibraryPathLock::forPath($root->id, 21660)->get();

    artisanCommand('tasks:recover')->assertSuccessful();

    expect(LibraryPathLock::forPath($root->id, 60)->get(fn (): bool => true))->toBeTrue();
    expect($receipt->refresh()->status)->toBe($status);
})->with([TaskStatus::PENDING, TaskStatus::FAILED, TaskStatus::COMPLETED]);
