<?php

use App\Jobs\WriteMediaFile;
use App\Library\FileFingerprint;
use App\Library\Listeners\RefreshAfterWriteback;
use App\Library\MediaScanner;
use App\Models\MediaFile;
use App\Models\MediaQualityCheck;
use App\Models\MediaTranscode;
use App\Models\MediaWriteback;
use App\QualityControl\Enums\QualityVerdict;
use App\Tasks\Enums\TaskStatus;
use App\Transcoding\Actions\RequestTranscodeRun;
use App\Writeback\Actions\ApproveWriteback;
use App\Writeback\Actions\RecoverWritebacks;
use App\Writeback\Actions\RequestWriteback;
use App\Writeback\Actions\RetryWriteback;
use App\Writeback\Actions\RunWriteback;
use App\Writeback\Enums\WritebackMode;
use App\Writeback\Events\WritebackInstalled;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../../Support/TranscodeFixtures.php';

function passingWritebackCheck(?WritebackMode $mode = WritebackMode::APPROVAL, string $sourceExtension = 'mkv'): MediaQualityCheck
{
    Storage::fake('transcodes');
    $file = transcodeSource();
    if ($sourceExtension !== 'mkv') {
        $root = $file->libraryPath()->firstOrFail();
        rename($root->path.'/movie.mkv', $root->path.'/movie.'.$sourceExtension);
        $file->update(['relative_path' => 'movie.'.$sourceExtension, 'path_hash' => hash('sha256', 'movie.'.$sourceExtension), 'extension' => $sourceExtension]);
    }
    $source = $file->libraryPath()->firstOrFail()->path.'/'.$file->relative_path;
    $attempt = (string) Str::uuid();
    $transcode = MediaTranscode::factory()->for($file)->create(['status' => TaskStatus::COMPLETED, 'writeback_mode' => $mode,
        'attempt_token' => $attempt, 'source_path' => $source, 'source_size_bytes' => $file->size_bytes,
        'source_modified_at' => $file->filesystem_modified_at, 'output_size_bytes' => 3]);
    $output = $transcode->id.'/'.$attempt.'/output.mkv';
    Storage::disk('transcodes')->put($output, 'new');
    $transcode->update(['output_path' => $output]);

    return MediaQualityCheck::factory()->for($transcode, 'transcode')->create(['status' => TaskStatus::COMPLETED,
        'verdict' => QualityVerdict::PASSED, 'transcode_attempt_token' => $attempt,
        'source_path' => $source, 'source_size_bytes' => $file->size_bytes, 'source_modified_at' => $file->filesystem_modified_at,
        'output_path' => $output, 'output_size_bytes' => 3, 'source_fingerprint' => FileFingerprint::capture($source),
        'output_fingerprint' => FileFingerprint::capture(Storage::disk('transcodes')->path($output))]);
}

it('converts existing fingerprint objects to checksum strings', function (): void {
    $writeback = MediaWriteback::factory()->create();
    $check = $writeback->qualityCheck()->firstOrFail();
    $source = hash('sha256', 'video');
    $output = hash('sha256', 'new');
    $migration = require database_path('migrations/2026_11_10_205037_simplify_media_fingerprints.php');
    $migration->down();
    DB::table('media_writebacks')->where('id', $writeback->id)->update([
        'source_fingerprint' => json_encode(['sha256' => $source, 'ino' => 123], JSON_THROW_ON_ERROR),
        'output_fingerprint' => json_encode(['sha256' => $output], JSON_THROW_ON_ERROR),
    ]);
    DB::table('media_quality_checks')->where('id', $check->id)->update([
        'source_fingerprint' => json_encode(['sha256' => $source], JSON_THROW_ON_ERROR),
    ]);

    $migration->up();

    expect($writeback->refresh()->source_fingerprint)->toBe($source);
    expect($writeback->output_fingerprint)->toBe($output);
    expect($check->refresh()->source_fingerprint)->toBe($source);
    expect($check->output_fingerprint)->toBeNull();
});

it('queues one writeback for a newly passed encode and keeps the source unchanged', function (): void {
    Queue::fake();
    $check = passingWritebackCheck();

    $writeback = app(RequestWriteback::class)->handle($check);
    app(RequestWriteback::class)->handle($check);

    expect($writeback)->toBeInstanceOf(MediaWriteback::class);
    expect(MediaWriteback::query()->count())->toBe(1);
    expect(file_get_contents($check->source_path))->toBe('video');
    Queue::assertPushed(WriteMediaFile::class, 1);
    Queue::assertPushed(WriteMediaFile::class, fn (WriteMediaFile $job): bool => $job->queue === 'writeback' && $job->connection === 'redis-writeback');
});

it('installs and cleans staged output through the Docker storage symlink', function (): void {
    Queue::fake();
    $check = passingWritebackCheck(WritebackMode::REPLACE);
    $root = Storage::disk('transcodes')->path('');
    $directory = mediaDirectory();
    symlink($root, $directory.'/storage');
    config(['filesystems.disks.transcodes.root' => $directory.'/storage']);
    Storage::forgetDisk('transcodes');
    $alias = Storage::disk('transcodes')->path($check->output_path);
    expect(is_readable($alias))->toBeTrue();
    $canonical = realpath($alias);
    $writeback = app(RequestWriteback::class)->handle($check);
    if ($writeback === null) {
        throw new RuntimeException('Missing writeback.');
    }
    $queuedPath = $writeback->staging_path;
    $writeback->update(['status' => TaskStatus::FAILED, 'staging_path' => $alias]);
    app(RetryWriteback::class)->handle($writeback);
    $writeback->refresh();

    app(RunWriteback::class)->handle($writeback->id, $writeback->request_token);

    expect($writeback->refresh()->error)->toBeNull();
    expect($writeback->status)->toBe(TaskStatus::COMPLETED);
    expect($queuedPath)->toBe($canonical);
    expect($writeback->staging_path)->toBe($canonical);
    expect($writeback->staging_cleaned_at)->not->toBeNull();
    expect(file_get_contents($writeback->destination_path))->toBe('new');
    expect(file_exists($alias))->toBeFalse();
    Queue::assertPushed(WriteMediaFile::class, 2);
});

it('does not deliver rejected, unfinished, or legacy quality checks', function (string $condition): void {
    Queue::fake();
    $check = passingWritebackCheck($condition === 'legacy' ? null : WritebackMode::REPLACE);
    match ($condition) {
        'rejected' => $check->update(['verdict' => QualityVerdict::REJECTED]),
        'unfinished' => $check->update(['status' => TaskStatus::RUNNING]),
        default => null,
    };

    expect(app(RequestWriteback::class)->handle($check))->toBeNull();
    expect(MediaWriteback::query()->count())->toBe(0);
    Queue::assertNothingPushed();
})->with(['rejected', 'unfinished', 'legacy']);

it('snapshots each new transcodes library writeback mode', function (): void {
    Queue::fake();
    $file = transcodeSource();
    $library = $file->libraryPath()->firstOrFail()->library()->firstOrFail();
    $library->update(['writeback_mode' => WritebackMode::REPLACE]);

    $run = app(RequestTranscodeRun::class)->forFile($file);
    $library->update(['writeback_mode' => WritebackMode::APPROVAL]);

    expect($run->transcodes()->sole()->writeback_mode)->toBe(WritebackMode::REPLACE);
});

it('copies beside the source, excludes the pending file from scans, and replaces only after approval', function (): void {
    Queue::fake();
    $check = passingWritebackCheck();
    $writeback = app(RequestWriteback::class)->handle($check);
    if ($writeback === null) {
        throw new RuntimeException('Missing writeback.');
    }
    app(RunWriteback::class)->handle($writeback->id, $writeback->request_token);
    $writeback->refresh();
    expect($writeback->error)->toBeNull()->and($writeback->status)->toBe(TaskStatus::PENDING);
    expect(file_get_contents($writeback->source_path))->toBe('video');
    expect(file_get_contents($writeback->candidate_path))->toBe('new');
    $root = $check->transcode()->firstOrFail()->mediaFile()->firstOrFail()->libraryPath()->firstOrFail();
    scanMediaPath($root);
    expect($root->mediaFiles()->count())->toBe(1);
    touch($writeback->source_path, 1000000000);
    touch($writeback->staging_path, 1000000000);
    copy($writeback->candidate_path, $writeback->candidate_path.'.replacement');
    rename($writeback->candidate_path.'.replacement', $writeback->candidate_path);

    app(ApproveWriteback::class)->handle($writeback);
    $writeback->refresh();
    app(RunWriteback::class)->handle($writeback->id, $writeback->request_token);
    $writeback->refresh();
    expect($writeback->error)->toBeNull()->and($writeback->status)->toBe(TaskStatus::COMPLETED);
    expect(file_get_contents($writeback->destination_path))->toBe('new');
    expect($writeback->inventory_refreshed_at)->not->toBeNull()->and($writeback->staging_cleaned_at)->not->toBeNull();
    expect(is_file($writeback->backup_path))->toBeFalse()->and(is_file($writeback->staging_path))->toBeFalse();
});

it('rejects another single-file encode while its output awaits approval', function (): void {
    Queue::fake();
    $check = passingWritebackCheck();
    $writeback = app(RequestWriteback::class)->handle($check);
    if ($writeback === null) {
        throw new RuntimeException('Missing writeback.');
    }
    app(RunWriteback::class)->handle($writeback->id, $writeback->request_token);

    expect(fn () => app(RequestTranscodeRun::class)->forFile($check->transcode()->firstOrFail()->mediaFile()->firstOrFail()))
        ->toThrow(ValidationException::class, 'write-back');
});

it('requeues interrupted writebacks with new tokens but leaves approvals waiting', function (): void {
    Queue::fake();
    $check = passingWritebackCheck();
    $writeback = app(RequestWriteback::class)->handle($check);
    if ($writeback === null) {
        throw new RuntimeException('Missing writeback.');
    }
    $old = $writeback->request_token;
    Cache::lock('writeback-source:'.hash('sha256', $writeback->source_path), 21660)->get();
    $writeback->update(['status' => TaskStatus::RUNNING, 'attempt_token' => (string) Str::uuid()]);
    app(RecoverWritebacks::class)->handle();
    expect($writeback->refresh()->request_token)->not->toBe($old);
    app(RunWriteback::class)->handle($writeback->id, $old);
    expect(file_get_contents($writeback->source_path))->toBe('video');
    app(RunWriteback::class)->handle($writeback->id, $writeback->request_token);
    $token = $writeback->refresh()->request_token;
    app(RecoverWritebacks::class)->handle();
    expect($writeback->refresh()->status)->toBe(TaskStatus::PENDING)->and($writeback->request_token)->toBe($token);
});

it('reconciles an existing destination inventory row after a cross-container replacement', function (bool $interveningScan): void {
    Queue::fake();
    $check = passingWritebackCheck(WritebackMode::REPLACE, 'avi');
    $sourceFile = $check->transcode()->firstOrFail()->mediaFile()->firstOrFail();
    $root = $sourceFile->libraryPath()->firstOrFail();
    $duplicate = null;
    if (! $interveningScan) {
        $duplicate = MediaFile::factory()->for($root)->atPath('movie.mkv')->missing()->create();
        MediaTranscode::factory()->for($duplicate)->create(['status' => TaskStatus::COMPLETED]);
    }
    $writeback = app(RequestWriteback::class)->handle($check);
    if ($writeback === null) {
        throw new RuntimeException('Missing writeback.');
    }
    Event::fake([WritebackInstalled::class]);
    app(RunWriteback::class)->handle($writeback->id, $writeback->request_token);
    expect($writeback->refresh()->status)->toBe(TaskStatus::COMPLETED);
    if ($interveningScan) {
        $scanToken = (string) Str::uuid();
        $root->update(['scan_token' => $scanToken, 'scan_status' => TaskStatus::RUNNING]);
        app(MediaScanner::class)->scan($root->id, $scanToken);
        expect($root->mediaFiles()->where('relative_path', 'movie.mkv')->count())->toBe(1);
    }

    app(RefreshAfterWriteback::class)->handle(new WritebackInstalled($writeback->id));

    expect($sourceFile->refresh()->relative_path)->toBe('movie.mkv')->and($sourceFile->size_bytes)->toBe(3);
    expect($root->mediaFiles()->where('relative_path', 'movie.mkv')->count())->toBe(1);
    expect($writeback->refresh()->inventory_refreshed_at)->not->toBeNull()->and($writeback->staging_cleaned_at)->not->toBeNull();
    if ($duplicate !== null) {
        expect(MediaTranscode::query()->where('media_file_id', $duplicate->id)->count())->toBe(0);
        expect($sourceFile->transcodes()->count())->toBe(2);
    }
})->with([false, true]);
