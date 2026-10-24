<?php

namespace App\Probe\Actions;

use App\Jobs\ProbeMediaFile;
use App\Library\Enums\MediaFileStatus;
use App\Library\MediaFilePath;
use App\Models\MediaFile;
use App\Models\MediaProbe;
use App\Probe\Exceptions\MalformedProbeResult;
use App\Probe\Exceptions\ProbeFailed;
use App\Probe\MediaProber;
use App\Probe\ProbeResult;
use App\Tasks\Enums\TaskStatus;
use Closure;
use FFMpeg\Exception\ExecutableNotFoundException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class RunProbe
{
    public function __construct(private MediaFilePath $paths) {}

    public function handle(int $fileId, string $token): void
    {
        $attempt = MediaProbe::claimAttempt($fileId, $token, ProbeMediaFile::MAX_ATTEMPTS, staleAfterSeconds: ProbeMediaFile::TIMEOUT_SECONDS + 30);

        if ($attempt === null) {
            return;
        }

        $current = fn (): Builder => MediaProbe::query()->forRequest($fileId, $token)->forAttempt($attempt);

        try {
            $file = MediaFile::query()->with('libraryPath')->find($fileId);
            $probe = $current()->first();

            if ($probe === null) {
                return;
            }

            if (! $this->isCurrent($file, $probe)) {
                $current()->update(MediaProbe::pendingAttributes());

                return;
            }

            $path = $this->paths->resolve($file);

            if (! $this->paths->isUnchanged($file, $path)) {
                $current()->update(MediaProbe::pendingAttributes());

                return;
            }

            // Resolved inside the failure boundary so a missing executable fails the probe for good.
            $result = app(MediaProber::class)->probe($path);

            if (! $this->paths->isUnchanged($file, $path)) {
                $current()->update(MediaProbe::pendingAttributes());

                return;
            }

            $this->complete($fileId, $current, $result);
        } catch (Throwable $exception) {
            $this->fail($current, $exception);
        }
    }

    private function complete(int $fileId, Closure $current, ProbeResult $result): void
    {
        DB::transaction(function () use ($fileId, $current, $result): void {
            $file = MediaFile::query()->whereKey($fileId)->lockForUpdate()->first();
            $probe = $current()->lockForUpdate()->first();

            if ($probe === null) {
                return;
            }

            if (! $this->isCurrent($file, $probe)) {
                $current()->update(MediaProbe::pendingAttributes());

                return;
            }

            $probe->streams()->delete();
            $probe->streams()->createMany($result->streams);
            $probe->update([
                'status' => TaskStatus::COMPLETED, 'format' => $result->format,
                'duration_seconds' => $result->duration, 'bit_rate' => $result->bitRate, 'metadata' => $result->metadata,
                'probed_at' => now(), 'finished_at' => now(), 'error' => null,
            ]);
        });
    }

    private function fail(Closure $current, Throwable $exception): void
    {
        $probe = $current()->first();

        if ($probe === null) {
            return;
        }

        $permanent = $probe->attempts >= ProbeMediaFile::MAX_ATTEMPTS
            || $exception instanceof MalformedProbeResult
            || $exception instanceof ExecutableNotFoundException
            || $exception instanceof InvalidArgumentException;

        $current()->update([
            'status' => $permanent ? TaskStatus::FAILED : TaskStatus::QUEUED,
            'error' => Str::limit($exception->getMessage(), 4000),
            'finished_at' => $permanent ? now() : null,
        ]);

        throw $permanent ? new ProbeFailed($exception) : $exception;
    }

    /** @phpstan-assert-if-true MediaFile $file */
    private function isCurrent(?MediaFile $file, MediaProbe $probe): bool
    {
        return $file !== null && $file->status === MediaFileStatus::PRESENT && $probe->matches($file);
    }
}
