<?php

use App\Library\FileFingerprint;
use App\Library\LibraryPathLock;
use App\QualityControl\Enums\QualityVerdict;
use App\Tasks\Enums\TaskStatus;
use App\Writeback\Actions\RunWriteback;
use App\Writeback\Enums\WritebackMode;
use App\Writeback\Events\WritebackInstalled;
use App\Writeback\WritebackFilesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/WritebackFixtures.php';

it('installs verified output and removes the temporary backup', function (string $extension): void {
    $receipt = writebackFixture($extension);
    copy($receipt->source_path, $receipt->source_path.'.replacement');
    rename($receipt->source_path.'.replacement', $receipt->source_path);
    chmod($receipt->source_path, 0640);
    touch($receipt->staging_path, 1000000000);
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

it('refuses changed source or staged output', function (string $field): void {
    $receipt = writebackFixture('mp4');
    $modifiedAt = filemtime($receipt->{$field});
    if ($modifiedAt === false) {
        throw new RuntimeException('Missing writeback file modification time.');
    }
    file_put_contents($receipt->{$field}, $field === 'source_path' ? 'tampered media' : 'changed media');
    touch($receipt->{$field}, $modifiedAt);

    app(RunWriteback::class)->handle($receipt->id, $receipt->request_token);

    expect($receipt->refresh()->status)->toBe(TaskStatus::FAILED);
    expect($receipt->error)->toBe('A writeback file checksum does not match: '.$receipt->{$field});
    expect(file_get_contents($receipt->source_path))->toBe($field === 'source_path' ? 'tampered media' : 'original media');
    expect(file_exists($receipt->destination_path))->toBeFalse();
})->with(['source_path', 'staging_path']);

it('preserves content with a different checksum during verified removal', function (): void {
    $directory = mediaDirectory(['source.mkv' => 'original media']);
    $source = $directory.'/source.mkv';
    $fingerprint = FileFingerprint::capture($source);
    file_put_contents($source, 'tampered media');

    expect(fn () => (new WritebackFilesystem)->remove($source, $fingerprint))->toThrow(RuntimeException::class);

    expect(file_get_contents($source))->toBe('tampered media');
});

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
    $receipt->update(['phase' => 'replacing']);
    link($receipt->source_path, $receipt->backup_path);
    unlink($receipt->source_path);
    if ($published) {
        copy($receipt->candidate_path, $receipt->destination_path);
        unlink($receipt->candidate_path);
    }
    Event::fake([WritebackInstalled::class]);

    app(RunWriteback::class)->handle($receipt->id, $receipt->request_token);

    expect($receipt->refresh()->status)->toBe(TaskStatus::COMPLETED);
    expect(file_get_contents($receipt->destination_path))->toBe('encoded media');
    expect(file_exists($receipt->backup_path))->toBeFalse();
    Event::assertDispatched(WritebackInstalled::class);
})->with([false, true]);

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

it('recovers the tracked partial copy after copying fails', function (): void {
    $receipt = writebackFixture('mp4');
    $filesystem = Mockery::mock(WritebackFilesystem::class)->makePartial();
    $filesystem->shouldReceive('syncDirectory')->once()->andThrow(new RuntimeException('Directory sync failed.'));
    app()->instance(WritebackFilesystem::class, $filesystem);

    app(RunWriteback::class)->handle($receipt->id, $receipt->request_token);

    expect($receipt->refresh()->status)->toBe(TaskStatus::FAILED);
    expect($receipt->phase)->toBe('copying');
    expect($receipt->copy_path)->not->toBeNull();
    expect(file_exists((string) $receipt->copy_path))->toBeTrue();
    expect(file_get_contents($receipt->source_path))->toBe('original media');
    expect(file_exists($receipt->destination_path))->toBeFalse();

    $copy = (string) $receipt->copy_path;
    file_put_contents($copy, 'partial');
    app()->instance(WritebackFilesystem::class, new WritebackFilesystem);
    app()->forgetInstance(RunWriteback::class);
    $receipt->update(['status' => TaskStatus::QUEUED]);
    Event::fake([WritebackInstalled::class]);

    app(RunWriteback::class)->handle($receipt->id, $receipt->request_token);

    expect($receipt->refresh()->status)->toBe(TaskStatus::COMPLETED);
    expect(file_get_contents($receipt->destination_path))->toBe('encoded media');
    expect(file_exists($copy))->toBeFalse();
    Event::assertDispatched(WritebackInstalled::class);
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

it('finishes the journal after filesystem cleanup', function (string $phase): void {
    $receipt = writebackFixture('mp4');
    copy($receipt->staging_path, $receipt->destination_path);
    $receipt->update(['phase' => $phase]);
    unlink($receipt->source_path);
    Event::fake([WritebackInstalled::class]);

    app(RunWriteback::class)->handle($receipt->id, $receipt->request_token);

    expect($receipt->refresh()->status)->toBe(TaskStatus::COMPLETED);
    expect(file_get_contents($receipt->destination_path))->toBe('encoded media');
    Event::assertDispatched(WritebackInstalled::class);
})->with(['replacing', 'installed']);

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
    $receipt->update(['phase' => 'replacing']);

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
    $receipt->update(['phase' => 'replacing']);
    link($receipt->source_path, $receipt->backup_path);
    unlink($receipt->source_path);
    file_put_contents($receipt->source_path, 'unrelated replacement');

    app(RunWriteback::class)->handle($receipt->id, $receipt->request_token);

    expect($receipt->refresh()->status)->toBe(TaskStatus::FAILED);
    expect(file_get_contents($receipt->source_path))->toBe('unrelated replacement');
    expect(file_get_contents($receipt->backup_path))->toBe('original media');
});

it('delivers the installed event without holding a database transaction', function (): void {
    $receipt = writebackFixture('mp4');
    $level = DB::transactionLevel();
    $deliveredAt = null;
    $pathId = $receipt->transcode()->firstOrFail()->mediaFile()->firstOrFail()->library_path_id;
    $libraryLocked = false;
    Event::listen(WritebackInstalled::class, function (WritebackInstalled $event) use ($receipt, $pathId, &$deliveredAt, &$libraryLocked): void {
        if ($event->writebackId === $receipt->id) {
            $deliveredAt = DB::transactionLevel();
            $libraryLocked = LibraryPathLock::forPath($pathId, 60)->get(fn (): bool => true) === false;
        }
    });

    app(RunWriteback::class)->handle($receipt->id, $receipt->request_token);

    expect($receipt->refresh()->status)->toBe(TaskStatus::COMPLETED);
    expect($deliveredAt)->toBe($level);
    expect($libraryLocked)->toBeTrue();
    expect(LibraryPathLock::forPath($pathId, 60)->get(fn (): bool => true))->toBeTrue();
});

it('recovers a matching copy when promotion succeeds before a worker failure', function (): void {
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
    $copy = (string) $receipt->copy_path;
    unlink($copy);
    file_put_contents($copy, 'partial');

    app()->instance(WritebackFilesystem::class, new WritebackFilesystem);
    app()->forgetInstance(RunWriteback::class);
    $receipt->update(['status' => TaskStatus::QUEUED]);
    app(RunWriteback::class)->handle($receipt->id, $receipt->request_token);

    expect($receipt->refresh()->status)->toBe(TaskStatus::PENDING);
    expect($receipt->copy_path)->toBeNull();
    expect(file_exists($copy))->toBeFalse();
    expect(file_get_contents($receipt->candidate_path))->toBe('encoded media');
    expect(file_get_contents($receipt->source_path))->toBe('original media');
});

it('restores source permissions before promoting a recovered complete temporary copy', function (): void {
    $receipt = writebackFixture('mp4', WritebackMode::APPROVAL);
    chmod($receipt->source_path, 0640);
    $copy = $receipt->candidate_path.'.copy-'.Str::uuid().'.part';
    copy($receipt->staging_path, $copy);
    chmod($copy, 0600);
    $receipt->update(['phase' => 'copying', 'copy_path' => $copy]);

    app(RunWriteback::class)->handle($receipt->id, $receipt->request_token);

    expect($receipt->refresh()->status)->toBe(TaskStatus::PENDING);
    expect(fileperms($receipt->candidate_path) & 0777)->toBe(0640);
    expect(file_exists($copy))->toBeFalse();
    expect(file_get_contents($receipt->source_path))->toBe('original media');
});
