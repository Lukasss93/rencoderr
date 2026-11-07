<?php

use App\Library\FileFingerprint;
use App\Models\MediaQualityCheck;
use App\Models\MediaWriteback;
use App\QualityControl\Enums\QualityVerdict;
use App\Tasks\Enums\TaskStatus;
use App\Transcoding\StagingArea;
use App\Writeback\Actions\RunWriteback;
use App\Writeback\Enums\WritebackMode;
use App\Writeback\Events\WritebackInstalled;
use App\Writeback\WritebackFilesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

function writebackFixture(string $extension = 'mkv', WritebackMode $mode = WritebackMode::REPLACE): MediaWriteback
{
    $directory = mediaDirectory(['movie.mkv' => 'original media']);
    $disk = Storage::fake('transcodes');
    $source = $directory.'/movie.mkv';
    $check = MediaQualityCheck::factory()->create(['status' => TaskStatus::COMPLETED, 'verdict' => QualityVerdict::PASSED]);
    $transcode = $check->transcode()->firstOrFail();
    $relative = app(StagingArea::class)->directory($transcode->id, $check->transcode_attempt_token).'/output.'.$extension;
    $disk->put($relative, 'encoded media');
    $output = $disk->path($relative);
    $transcode->update(['source_path' => $source, 'output_path' => $relative]);
    $transcode->mediaFile()->firstOrFail()->update(['relative_path' => 'movie.mkv']);
    $check->update(['source_path' => $source, 'output_path' => $relative,
        'source_fingerprint' => FileFingerprint::capture($source), 'output_fingerprint' => FileFingerprint::capture($output)]);
    $transcode->mediaFile()->firstOrFail()->libraryPath()->firstOrFail()->update(['path' => $directory]);
    $token = (string) Str::uuid();

    return MediaWriteback::query()->create([
        'media_transcode_id' => $transcode->id, 'media_quality_check_id' => $check->id,
        'status' => TaskStatus::QUEUED, 'mode' => $mode, 'request_token' => $token, 'comparison' => [],
        'source_path' => $source, 'staging_path' => $output,
        'candidate_path' => $directory.'/movie.rencoderr-'.$token.'.pending.'.$extension,
        'destination_path' => $directory.'/movie.'.$extension, 'backup_path' => $source.'.rencoderr-'.$token.'.backup',
        'source_fingerprint' => FileFingerprint::capture($source), 'output_fingerprint' => FileFingerprint::capture($output),
    ]);
}

it('installs verified output and removes the temporary backup', function (string $extension): void {
    $receipt = writebackFixture($extension);
    chmod($receipt->source_path, 0640);
    $fingerprint = FileFingerprint::capture($receipt->source_path);
    $receipt->update(['source_fingerprint' => $fingerprint]);
    $receipt->qualityCheck()->firstOrFail()->update(['source_fingerprint' => $fingerprint]);
    Event::fake([WritebackInstalled::class]);

    app(RunWriteback::class)->handle($receipt->id, $receipt->request_token);

    expect($receipt->refresh()->status)->toBe(TaskStatus::COMPLETED);
    expect(file_get_contents($receipt->destination_path))->toBe('encoded media');
    expect(file_exists($receipt->backup_path))->toBeFalse();
    expect(file_exists($receipt->candidate_path))->toBeFalse();
    expect(fileperms($receipt->destination_path) & 0777)->toBe(0640);
    if ($extension !== 'mkv') {
        expect(file_exists($receipt->source_path))->toBeFalse();
    }
    Event::assertDispatched(WritebackInstalled::class);
})->with(['mkv', 'mp4']);

it('retains the source until explicit approval', function (): void {
    $receipt = writebackFixture('mp4', WritebackMode::APPROVAL);
    Event::fake([WritebackInstalled::class]);

    app(RunWriteback::class)->handle($receipt->id, $receipt->request_token);

    expect($receipt->refresh()->status)->toBe(TaskStatus::PENDING);
    expect(file_get_contents($receipt->source_path))->toBe('original media');
    expect(file_get_contents($receipt->candidate_path))->toBe('encoded media');
    expect(file_exists($receipt->destination_path))->toBeFalse();
    Event::assertNotDispatched(WritebackInstalled::class);

    $receipt->update(['approved_at' => now(), 'status' => TaskStatus::QUEUED]);
    app(RunWriteback::class)->handle($receipt->id, $receipt->request_token);

    expect($receipt->refresh()->status)->toBe(TaskStatus::COMPLETED);
    expect(file_get_contents($receipt->destination_path))->toBe('encoded media');
});

it('refuses changed source or staged output', function (string $field): void {
    $receipt = writebackFixture('mp4');
    file_put_contents($receipt->{$field}, 'changed media');

    app(RunWriteback::class)->handle($receipt->id, $receipt->request_token);

    expect($receipt->refresh()->status)->toBe(TaskStatus::FAILED);
    expect(file_get_contents($receipt->source_path))->toBe($field === 'source_path' ? 'changed media' : 'original media');
    expect(file_exists($receipt->destination_path))->toBeFalse();
})->with(['source_path', 'staging_path']);

it('preserves unrelated collisions', function (string $field): void {
    $receipt = writebackFixture('mp4');
    file_put_contents($receipt->{$field}, 'unrelated');

    app(RunWriteback::class)->handle($receipt->id, $receipt->request_token);

    expect($receipt->refresh()->status)->toBe(TaskStatus::FAILED);
    expect(file_get_contents($receipt->{$field}))->toBe('unrelated');
    expect(file_get_contents($receipt->source_path))->toBe('original media');
})->with(['candidate_path', 'destination_path', 'backup_path']);

it('ignores stale and duplicate deliveries', function (): void {
    $receipt = writebackFixture();
    Event::fake([WritebackInstalled::class]);

    app(RunWriteback::class)->handle($receipt->id, 'stale');
    expect($receipt->refresh()->status)->toBe(TaskStatus::QUEUED);
    app(RunWriteback::class)->handle($receipt->id, $receipt->request_token);
    app(RunWriteback::class)->handle($receipt->id, $receipt->request_token);

    expect(file_get_contents($receipt->destination_path))->toBe('encoded media');
    Event::assertDispatchedTimes(WritebackInstalled::class, 1);
});

it('recovers an interrupted replacement', function (bool $published): void {
    $receipt = writebackFixture();
    copy($receipt->staging_path, $receipt->candidate_path);
    $receipt->update(['phase' => 'replacing', 'candidate_identity' => FileFingerprint::capture($receipt->candidate_path)]);
    link($receipt->source_path, $receipt->backup_path);
    unlink($receipt->source_path);
    if ($published) {
        link($receipt->candidate_path, $receipt->destination_path);
        unlink($receipt->candidate_path);
    }
    Event::fake([WritebackInstalled::class]);

    app(RunWriteback::class)->handle($receipt->id, $receipt->request_token);

    expect($receipt->refresh()->status)->toBe(TaskStatus::COMPLETED);
    expect(file_get_contents($receipt->destination_path))->toBe('encoded media');
    expect(file_exists($receipt->backup_path))->toBeFalse();
    Event::assertDispatched(WritebackInstalled::class);
})->with([false, true]);

it('restarts only its own interrupted partial copy', function (): void {
    $receipt = writebackFixture('mp4');
    $copy = $receipt->candidate_path.'.copy-'.Str::uuid().'.part';
    file_put_contents($copy, 'partial');
    $receipt->update(['phase' => 'copying', 'copy_path' => $copy, 'candidate_identity' => FileFingerprint::capture($copy)]);
    Event::fake([WritebackInstalled::class]);

    app(RunWriteback::class)->handle($receipt->id, $receipt->request_token);

    expect($receipt->refresh()->status)->toBe(TaskStatus::COMPLETED);
    expect(file_get_contents($receipt->destination_path))->toBe('encoded media');
    expect(file_exists($copy))->toBeFalse();
    Event::assertDispatched(WritebackInstalled::class);
});

it('refuses symbolic links and noncanonical paths', function (): void {
    $directory = mediaDirectory(['original' => 'media']);
    symlink($directory.'/original', $directory.'/link');

    expect(fn () => FileFingerprint::capture($directory.'/link'))->toThrow(RuntimeException::class);
    expect(fn () => FileFingerprint::capture($directory.'/../'.basename($directory).'/original'))->toThrow(RuntimeException::class);
});

it('defers installing while its library folder is being scanned', function (): void {
    $receipt = writebackFixture('mp4');
    $path = $receipt->transcode()->firstOrFail()->mediaFile()->firstOrFail()->libraryPath()->firstOrFail();
    $path->update(['scan_status' => TaskStatus::RUNNING]);
    Event::fake([WritebackInstalled::class]);

    app(RunWriteback::class)->handle($receipt->id, $receipt->request_token);

    expect($receipt->refresh()->status)->toBe(TaskStatus::QUEUED);
    expect(file_get_contents($receipt->source_path))->toBe('original media');
    expect(file_exists($receipt->destination_path))->toBeFalse();
    Event::assertNotDispatched(WritebackInstalled::class);

    $path->update(['scan_status' => TaskStatus::COMPLETED]);
    app(RunWriteback::class)->handle($receipt->id, $receipt->request_token);

    expect($receipt->refresh()->status)->toBe(TaskStatus::COMPLETED);
    expect(file_get_contents($receipt->destination_path))->toBe('encoded media');
});

it('refuses a modified pending candidate after approval', function (): void {
    $receipt = writebackFixture('mp4', WritebackMode::APPROVAL);
    app(RunWriteback::class)->handle($receipt->id, $receipt->request_token);
    file_put_contents($receipt->candidate_path, 'altered candidate');
    $receipt->refresh()->update(['status' => TaskStatus::QUEUED, 'approved_at' => now()]);

    app(RunWriteback::class)->handle($receipt->id, $receipt->request_token);

    expect($receipt->refresh()->status)->toBe(TaskStatus::FAILED);
    expect(file_get_contents($receipt->source_path))->toBe('original media');
    expect(file_exists($receipt->destination_path))->toBeFalse();
});

it('keeps the source and owned partial candidate after copying fails', function (): void {
    $receipt = writebackFixture('mp4');
    $filesystem = Mockery::mock(WritebackFilesystem::class)->makePartial();
    $filesystem->shouldReceive('syncDirectory')->once()->andThrow(new RuntimeException('Directory sync failed.'));
    app()->instance(WritebackFilesystem::class, $filesystem);

    app(RunWriteback::class)->handle($receipt->id, $receipt->request_token);

    expect($receipt->refresh()->status)->toBe(TaskStatus::FAILED);
    expect($receipt->phase)->toBe('copying');
    expect($receipt->candidate_identity)->not->toBeNull();
    expect(file_get_contents($receipt->source_path))->toBe('original media');
    expect(file_exists($receipt->destination_path))->toBeFalse();
});

it('restores the original when publication fails after its pathname was removed', function (): void {
    $receipt = writebackFixture();
    $filesystem = Mockery::mock(WritebackFilesystem::class)->makePartial();
    $filesystem->shouldReceive('link')->with($receipt->candidate_path, $receipt->destination_path)
        ->once()->andThrow(new RuntimeException('Hard links are unavailable.'));
    app()->instance(WritebackFilesystem::class, $filesystem);

    app(RunWriteback::class)->handle($receipt->id, $receipt->request_token);

    expect($receipt->refresh()->status)->toBe(TaskStatus::FAILED);
    expect($receipt->phase)->toBe('replacing');
    expect(file_get_contents($receipt->source_path))->toBe('original media');
    expect(file_get_contents($receipt->backup_path))->toBe('original media');

    app()->instance(WritebackFilesystem::class, new WritebackFilesystem);
    app()->forgetInstance(RunWriteback::class);
    $receipt->update(['status' => TaskStatus::QUEUED]);
    Event::fake([WritebackInstalled::class]);
    app(RunWriteback::class)->handle($receipt->id, $receipt->request_token);

    expect($receipt->refresh()->status)->toBe(TaskStatus::COMPLETED);
    expect(file_get_contents($receipt->destination_path))->toBe('encoded media');
    Event::assertDispatched(WritebackInstalled::class);
});

it('does not touch source data when quality control no longer passes', function (): void {
    $receipt = writebackFixture('mp4');
    $receipt->qualityCheck()->firstOrFail()->update(['verdict' => QualityVerdict::REJECTED]);

    app(RunWriteback::class)->handle($receipt->id, $receipt->request_token);

    expect($receipt->refresh()->status)->toBe(TaskStatus::FAILED);
    expect(file_get_contents($receipt->source_path))->toBe('original media');
    expect(file_exists($receipt->candidate_path))->toBeFalse();
});

it('rejects symbolic links in every writeback file position', function (string $field): void {
    $receipt = writebackFixture('mp4');
    $target = mediaDirectory(['unrelated' => 'unrelated']).'/unrelated';
    if (file_exists($receipt->{$field})) {
        unlink($receipt->{$field});
    }
    symlink($target, $receipt->{$field});

    app(RunWriteback::class)->handle($receipt->id, $receipt->request_token);

    expect($receipt->refresh()->status)->toBe(TaskStatus::FAILED);
    expect(file_get_contents($target))->toBe('unrelated');
    if ($field !== 'source_path') {
        expect(file_get_contents($receipt->source_path))->toBe('original media');
    }
})->with(['source_path', 'staging_path', 'candidate_path', 'destination_path', 'backup_path']);

it('resumes when the original still has its pathname after backup creation', function (): void {
    $receipt = writebackFixture();
    copy($receipt->staging_path, $receipt->candidate_path);
    $receipt->update(['phase' => 'replacing', 'candidate_identity' => FileFingerprint::capture($receipt->candidate_path)]);
    link($receipt->source_path, $receipt->backup_path);
    Event::fake([WritebackInstalled::class]);

    app(RunWriteback::class)->handle($receipt->id, $receipt->request_token);

    expect($receipt->refresh()->status)->toBe(TaskStatus::COMPLETED);
    expect(file_get_contents($receipt->destination_path))->toBe('encoded media');
    expect(file_exists($receipt->backup_path))->toBeFalse();
    Event::assertDispatched(WritebackInstalled::class);
});

it('finishes the journal after verified installation and backup cleanup', function (): void {
    $receipt = writebackFixture('mp4');
    copy($receipt->staging_path, $receipt->candidate_path);
    $receipt->update(['phase' => 'installed', 'candidate_identity' => FileFingerprint::capture($receipt->candidate_path)]);
    link($receipt->candidate_path, $receipt->destination_path);
    unlink($receipt->candidate_path);
    unlink($receipt->source_path);
    Event::fake([WritebackInstalled::class]);

    app(RunWriteback::class)->handle($receipt->id, $receipt->request_token);

    expect($receipt->refresh()->status)->toBe(TaskStatus::COMPLETED);
    expect(file_get_contents($receipt->destination_path))->toBe('encoded media');
    Event::assertDispatched(WritebackInstalled::class);
});

it('refuses an unrelated destination even when its bytes equal the output', function (): void {
    $receipt = writebackFixture('mp4');
    copy($receipt->staging_path, $receipt->destination_path);
    $identity = FileFingerprint::capture($receipt->destination_path);

    app(RunWriteback::class)->handle($receipt->id, $receipt->request_token);

    expect($receipt->refresh()->status)->toBe(TaskStatus::FAILED);
    expect(FileFingerprint::matches($receipt->destination_path, $identity))->toBeTrue();
    expect(file_get_contents($receipt->source_path))->toBe('original media');
});

it('refuses receipt paths detached from the verified attempt', function (string $field): void {
    $receipt = writebackFixture('mp4');
    $other = mediaDirectory(['output.mp4' => 'encoded media']);
    $receipt->update([$field => $other.'/output.mp4']);

    app(RunWriteback::class)->handle($receipt->id, $receipt->request_token);

    expect($receipt->refresh()->status)->toBe(TaskStatus::FAILED);
    expect(file_get_contents($receipt->source_path))->toBe('original media');
    expect(file_get_contents($other.'/output.mp4'))->toBe('encoded media');
})->with(['staging_path', 'candidate_path', 'destination_path', 'backup_path']);

it('requires approval even when resuming the replacement journal', function (): void {
    $receipt = writebackFixture('mp4', WritebackMode::APPROVAL);
    copy($receipt->staging_path, $receipt->candidate_path);
    $receipt->update(['phase' => 'replacing', 'candidate_identity' => FileFingerprint::capture($receipt->candidate_path)]);

    app(RunWriteback::class)->handle($receipt->id, $receipt->request_token);

    expect($receipt->refresh()->status)->toBe(TaskStatus::FAILED);
    expect(file_get_contents($receipt->source_path))->toBe('original media');
    expect(file_exists($receipt->destination_path))->toBeFalse();
});

it('rejects nonregular filesystem entries before opening them', function (): void {
    $directory = mediaDirectory();
    expect(fn () => FileFingerprint::capture($directory))->toThrow(RuntimeException::class);
});

it('does not recreate the original while an unrelated replacement occupies its pathname', function (): void {
    $receipt = writebackFixture();
    copy($receipt->staging_path, $receipt->candidate_path);
    $receipt->update(['phase' => 'replacing', 'candidate_identity' => FileFingerprint::capture($receipt->candidate_path)]);
    link($receipt->source_path, $receipt->backup_path);
    unlink($receipt->source_path);
    file_put_contents($receipt->source_path, 'unrelated replacement');

    app(RunWriteback::class)->handle($receipt->id, $receipt->request_token);

    expect($receipt->refresh()->status)->toBe(TaskStatus::FAILED);
    expect(file_get_contents($receipt->source_path))->toBe('unrelated replacement');
    expect(file_get_contents($receipt->backup_path))->toBe('original media');
});

it('recovers a temporary copy created before its identity was journaled', function (): void {
    $receipt = writebackFixture('mp4', WritebackMode::APPROVAL);
    $copy = $receipt->candidate_path.'.copy-'.Str::uuid().'.part';
    file_put_contents($copy, '');
    $receipt->update(['phase' => 'copying', 'copy_path' => $copy, 'candidate_identity' => null]);
    Event::fake([WritebackInstalled::class]);

    app(RunWriteback::class)->handle($receipt->id, $receipt->request_token);

    expect($receipt->refresh()->status)->toBe(TaskStatus::PENDING);
    expect(file_get_contents($receipt->source_path))->toBe('original media');
    expect(file_get_contents($receipt->candidate_path))->toBe('encoded media');
    expect(file_exists($copy))->toBeTrue();
    expect($receipt->copy_path)->toBeNull();
    Event::assertNotDispatched(WritebackInstalled::class);
});

it('recognizes a verified candidate promoted before its phase was journaled', function (): void {
    $receipt = writebackFixture('mp4', WritebackMode::APPROVAL);
    $copy = $receipt->candidate_path.'.copy-'.Str::uuid().'.part';
    copy($receipt->staging_path, $copy);
    $receipt->update(['phase' => 'copying', 'copy_path' => $copy, 'candidate_identity' => FileFingerprint::capture($copy)]);
    link($copy, $receipt->candidate_path);
    $identity = FileFingerprint::capture($receipt->candidate_path);
    Event::fake([WritebackInstalled::class]);

    app(RunWriteback::class)->handle($receipt->id, $receipt->request_token);

    expect($receipt->refresh()->status)->toBe(TaskStatus::PENDING);
    expect(file_get_contents($receipt->source_path))->toBe('original media');
    expect(FileFingerprint::matches($receipt->candidate_path, $identity, false))->toBeTrue();
    expect(file_exists($copy))->toBeFalse();
    expect($receipt->copy_path)->toBeNull();
    Event::assertNotDispatched(WritebackInstalled::class);
});

it('delivers the installed event before the installation transaction releases its lock', function (): void {
    $receipt = writebackFixture('mp4');
    $level = DB::transactionLevel();
    $deliveredAt = null;
    Event::listen(WritebackInstalled::class, function (WritebackInstalled $event) use ($receipt, &$deliveredAt): void {
        if ($event->writebackId === $receipt->id) {
            $deliveredAt = DB::transactionLevel();
        }
    });

    app(RunWriteback::class)->handle($receipt->id, $receipt->request_token);

    expect($receipt->refresh()->status)->toBe(TaskStatus::COMPLETED);
    expect($deliveredAt)->toBe($level + 1);
});

it('recovers the persisted copy identity when promotion succeeds before a worker failure', function (): void {
    $receipt = writebackFixture('mp4', WritebackMode::APPROVAL);
    $filesystem = Mockery::mock(WritebackFilesystem::class)->makePartial();
    $filesystem->shouldReceive('link')->with(Mockery::type('string'), $receipt->candidate_path)
        ->once()->andReturnUsing(function (string $from, string $to): void {
            (new WritebackFilesystem)->link($from, $to);
            throw new RuntimeException('Worker interrupted after candidate publication.');
        });
    app()->instance(WritebackFilesystem::class, $filesystem);

    app(RunWriteback::class)->handle($receipt->id, $receipt->request_token);

    expect($receipt->refresh()->status)->toBe(TaskStatus::FAILED);
    expect($receipt->phase)->toBe('copying');
    expect(file_get_contents($receipt->candidate_path))->toBe('encoded media');
    expect(file_get_contents($receipt->source_path))->toBe('original media');

    app()->instance(WritebackFilesystem::class, new WritebackFilesystem);
    app()->forgetInstance(RunWriteback::class);
    $receipt->update(['status' => TaskStatus::QUEUED]);
    app(RunWriteback::class)->handle($receipt->id, $receipt->request_token);

    expect($receipt->refresh()->status)->toBe(TaskStatus::PENDING);
    expect($receipt->copy_path)->toBeNull();
    expect(file_get_contents($receipt->candidate_path))->toBe('encoded media');
    expect(file_get_contents($receipt->source_path))->toBe('original media');
});

it('replays a replacement whose database transaction rolled back after filesystem cleanup', function (): void {
    $receipt = writebackFixture();
    copy($receipt->staging_path, $receipt->candidate_path);
    $receipt->update(['phase' => 'replacing', 'candidate_identity' => FileFingerprint::capture($receipt->candidate_path)]);
    unlink($receipt->source_path);
    link($receipt->candidate_path, $receipt->destination_path);
    unlink($receipt->candidate_path);
    Event::fake([WritebackInstalled::class]);

    app(RunWriteback::class)->handle($receipt->id, $receipt->request_token);

    expect($receipt->refresh()->status)->toBe(TaskStatus::COMPLETED);
    expect(file_get_contents($receipt->destination_path))->toBe('encoded media');
    expect(file_exists($receipt->backup_path))->toBeFalse();
    Event::assertDispatched(WritebackInstalled::class);
});

it('restores source permissions before promoting a recovered complete temporary copy', function (): void {
    $receipt = writebackFixture('mp4', WritebackMode::APPROVAL);
    chmod($receipt->source_path, 0640);
    $fingerprint = FileFingerprint::capture($receipt->source_path);
    $receipt->update(['source_fingerprint' => $fingerprint]);
    $receipt->qualityCheck()->firstOrFail()->update(['source_fingerprint' => $fingerprint]);
    $copy = $receipt->candidate_path.'.copy-'.Str::uuid().'.part';
    copy($receipt->staging_path, $copy);
    chmod($copy, 0600);
    $receipt->update(['phase' => 'copying', 'copy_path' => $copy, 'candidate_identity' => FileFingerprint::capture($copy)]);

    app(RunWriteback::class)->handle($receipt->id, $receipt->request_token);

    expect($receipt->refresh()->status)->toBe(TaskStatus::PENDING);
    expect(fileperms($receipt->candidate_path) & 0777)->toBe(0640);
    expect(file_exists($copy))->toBeFalse();
    expect(file_get_contents($receipt->source_path))->toBe('original media');
});
