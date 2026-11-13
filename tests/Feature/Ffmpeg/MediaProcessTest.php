<?php

use App\Ffmpeg\MediaProcess;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

function failedProgressUpdate(string $error): QueryException
{
    return new QueryException('sqlite', 'update "media_transcodes" set "progress_percent" = ?', [50], new PDOException($error));
}

it('keeps running and returns the result when progress updates hit a locked database', function (): void {
    $log = Log::spy();
    $reports = 0;

    $result = (new MediaProcess)->execute(['/bin/sh', '-c', 'sleep 0.3'], 10, fn (): bool => false, function (string $chunk): void {},
        function () use (&$reports): void {
            $reports++;

            throw failedProgressUpdate('SQLSTATE[HY000]: General error: 5 database is locked');
        });

    expect($result->exitCode)->toBe(0);
    expect($reports)->toBe(2);
    $log->shouldHaveReceived('warning')->twice();
});

it('stops the media process when a progress update fails for another reason', function (): void {
    $process = new MediaProcess;

    expect(fn () => $process->execute(['/bin/sh', '-c', 'sleep 5'], 10, fn (): bool => false, function (string $chunk): void {},
        fn () => throw failedProgressUpdate('SQLSTATE[23000]: Integrity constraint violation: 19 NOT NULL constraint failed')))
        ->toThrow(QueryException::class, 'NOT NULL constraint failed');
});
