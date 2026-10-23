<?php

namespace App\Jobs;

use App\Encoding\Actions\RequestMediaProbe;
use App\Encoding\Enums\ProbeStatus;
use App\Encoding\MediaProber;
use App\Encoding\ProbeFile;
use App\Encoding\ProbeResult;
use App\Exceptions\Encoding\MalformedProbeResult;
use App\Models\MediaFile;
use App\Models\MediaProbe;
use App\Scan\Enums\MediaFileStatus;
use FFMpeg\Exception\ExecutableNotFoundException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class ProbeMediaFile implements ShouldQueue
{
    use Queueable;

    public const int MAX_ATTEMPTS = 3;

    public const int TIMEOUT_SECONDS = 150;

    public int $tries = self::MAX_ATTEMPTS;

    public int $timeout = self::TIMEOUT_SECONDS;

    public function __construct(public int $fileId, public string $token, public int $previousAttempts = 0)
    {
        $this->onQueue('probes');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return $this->previousAttempts === 0 ? [30, 120] : [120];
    }

    public function handle(ProbeFile $files): void
    {
        $attempt = (string) Str::uuid();
        $claimed = $this->request()->where('attempts', '<', self::MAX_ATTEMPTS)
            ->where(function (Builder $query): void {
                $query->where('status', ProbeStatus::Queued)->orWhere(function (Builder $query): void {
                    $query->where('status', ProbeStatus::Probing)->where('started_at', '<=', now()->subSeconds(self::TIMEOUT_SECONDS + 30));
                });
            })->update([
                'status' => ProbeStatus::Probing, 'attempt_token' => $attempt, 'started_at' => now(),
                'attempts' => DB::raw('attempts + 1'),
            ]);
        if ($claimed === 0) {
            return;
        }

        try {
            $file = MediaFile::query()->with('libraryPath')->find($this->fileId);
            $probe = $this->attempt($attempt)->first();
            if ($probe === null) {
                return;
            }
            if ($file === null || $file->status !== MediaFileStatus::Present || ! $probe->matches($file)) {
                $this->invalidate($attempt);

                return;
            }
            $path = $files->path($file);
            if (! $files->matches($file, $path)) {
                $this->invalidate($attempt);

                return;
            }
            // Resolve inside the failure boundary so a missing executable is terminal.
            $result = app(MediaProber::class)->probe($path);
            if (! $files->matches($file, $path)) {
                $this->invalidate($attempt);

                return;
            }
            $this->complete($attempt, $result);
        } catch (Throwable $exception) {
            $probe = $this->attempt($attempt)->first();
            if ($probe === null) {
                return;
            }
            $terminal = $probe->attempts >= self::MAX_ATTEMPTS
                || $exception instanceof MalformedProbeResult
                || $exception instanceof ExecutableNotFoundException
                || $exception instanceof InvalidArgumentException;
            $this->attempt($attempt)->update([
                'status' => $terminal ? ProbeStatus::Failed : ProbeStatus::Queued,
                'error' => Str::limit($exception->getMessage(), 4000),
                'finished_at' => $terminal ? now() : null,
            ]);
            if ($terminal) {
                $this->fail($exception);

                return;
            }
            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->request()->where(function (Builder $query): void {
            $query->where('status', ProbeStatus::Queued)->orWhere(function (Builder $query): void {
                $query->where('status', ProbeStatus::Probing)->where('started_at', '<=', now()->subSeconds(self::TIMEOUT_SECONDS));
            });
        })->update([
            'status' => ProbeStatus::Failed, 'finished_at' => now(),
            'error' => Str::limit($exception?->getMessage() ?? 'The probe failed.', 4000),
        ]);
    }

    private function complete(string $attempt, ProbeResult $result): void
    {
        DB::transaction(function () use ($attempt, $result): void {
            $file = MediaFile::query()->whereKey($this->fileId)->lockForUpdate()->first();
            $probe = $this->attempt($attempt)->lockForUpdate()->first();
            if ($probe === null) {
                return;
            }
            if ($file === null || $file->status !== MediaFileStatus::Present || ! $probe->matches($file)) {
                $this->invalidate($attempt);

                return;
            }
            $probe->streams()->delete();
            $probe->streams()->createMany($result->streams);
            $probe->update([
                'status' => ProbeStatus::Completed, 'format' => $result->format,
                'duration_seconds' => $result->duration, 'bit_rate' => $result->bitRate, 'metadata' => $result->metadata,
                'probed_at' => now(), 'finished_at' => now(), 'error' => null,
            ]);
        });
    }

    private function invalidate(string $attempt): void
    {
        $this->attempt($attempt)->update(RequestMediaProbe::pendingAttributes());
    }

    /** @return Builder<MediaProbe> */
    private function request(): Builder
    {
        return MediaProbe::query()->where('media_file_id', $this->fileId)->where('request_token', $this->token);
    }

    /** @return Builder<MediaProbe> */
    private function attempt(string $attempt): Builder
    {
        return $this->request()->where('attempt_token', $attempt)->where('status', ProbeStatus::Probing);
    }
}
