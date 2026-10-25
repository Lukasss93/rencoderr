<?php

namespace App\QualityControl\Actions;

use App\Ffmpeg\MediaProcessInterrupted;
use App\Models\MediaQualityCheck;
use App\QualityControl\Enums\QualityVerdict;
use App\QualityControl\QualityEvaluator;
use App\QualityControl\QualityRejected;
use App\QualityControl\QualityWorkspace;
use App\Tasks\Enums\TaskStatus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class RunQualityCheck
{
    public function __construct(private QualityEvaluator $evaluator, private QualityWorkspace $workspace) {}

    public function handle(int $checkId, string $requestToken): void
    {
        $queued = MediaQualityCheck::query()->forRequest($checkId, $requestToken)->where('status', TaskStatus::QUEUED)->first();
        if ($queued === null) {
            return;
        }
        $attempt = (string) Str::uuid();
        $claimed = MediaQualityCheck::query()->forRequest($checkId, $requestToken)->where('status', TaskStatus::QUEUED)->update([
            'status' => TaskStatus::RUNNING, 'attempt_token' => $attempt, 'phase' => 'preparing', 'started_at' => now(),
            'execution_deadline_at' => now()->addSeconds($queued->execution_limit_seconds), 'finished_at' => null,
        ]);
        if ($claimed !== 1) {
            return;
        }
        $item = $queued->refresh();
        $current = fn () => MediaQualityCheck::query()->forRequest($checkId, $requestToken)
            ->where('attempt_token', $attempt)->where('status', TaskStatus::RUNNING);
        $lastCheck = 0.0;
        $stop = function () use ($item, $current, &$lastCheck): bool {
            if ($item->execution_deadline_at?->isPast()) {
                throw new RuntimeException('The quality-control execution deadline expired.');
            }
            if (microtime(true) - $lastCheck < 5) {
                return false;
            }
            $lastCheck = microtime(true);

            return ! $current()->exists() || $this->cancelled($item);
        };
        $progress = function (array $changes) use ($current): void {
            $current()->update((new MediaQualityCheck($changes))->getAttributes());
        };
        $context = ['quality_check_id' => $item->id, 'transcode_id' => $item->media_transcode_id, 'request_token' => $requestToken, 'attempt_token' => $attempt];
        Log::info('Quality control started.', $context);
        try {
            if ($stop()) {
                throw new MediaProcessInterrupted('Quality control was cancelled or its source was deleted.');
            }
            $result = $this->evaluator->evaluate($item, $stop, $progress);
            if ($this->cancelled($item)) {
                throw new MediaProcessInterrupted('Quality control was cancelled before accepting its result.');
            }
            if ($item->execution_deadline_at?->isPast()) {
                throw new RuntimeException('The quality-control execution deadline expired.');
            }
            $result = [...$result, 'status' => TaskStatus::COMPLETED, 'phase' => 'finished', 'progress_percent' => 100];
        } catch (Throwable $exception) {
            $cancelled = $exception instanceof MediaProcessInterrupted || $this->cancelled($item);
            $rejected = ! $cancelled && $exception instanceof QualityRejected;
            $result = ['status' => $cancelled ? TaskStatus::CANCELLED : ($rejected ? TaskStatus::COMPLETED : TaskStatus::FAILED),
                'verdict' => $rejected ? QualityVerdict::REJECTED : null,
                'reason' => $rejected ? $exception->reason : ($cancelled ? 'cancelled' : 'execution_failed'),
                'error' => Str::limit($exception->getMessage(), 4000)];
        } finally {
            try {
                $this->workspace->clean($item);
            } catch (Throwable $exception) {
                Log::warning('Quality-control temporary files could not be removed.', [...$context, 'error' => $exception->getMessage()]);
            }
        }
        $query = $current();
        if ($result['status'] !== TaskStatus::CANCELLED) {
            $query->whereNull('cancel_requested_at')->whereHas('transcode.mediaFile.libraryPath.library');
        }
        $saved = $query->update((new MediaQualityCheck([...$result, 'finished_at' => now()]))->getAttributes());
        if ($saved === 0) {
            $current()->where(fn ($query) => $query->whereNotNull('cancel_requested_at')->orWhereDoesntHave('transcode.mediaFile.libraryPath.library'))
                ->update(['status' => TaskStatus::CANCELLED, 'verdict' => null, 'reason' => 'cancelled', 'finished_at' => now()]);
        }
        if ($saved === 1) {
            Log::info('Quality control finished.', [...$context, 'status' => $result['status'], 'verdict' => $result['verdict'] ?? null, 'reason' => $result['reason'] ?? null]);
        }
    }

    private function cancelled(MediaQualityCheck $item): bool
    {
        return MediaQualityCheck::query()->forRequest($item->id, $item->request_token)->whereNotNull('cancel_requested_at')->exists()
            || ! $item->transcode()->whereHas('mediaFile.libraryPath.library')->exists();
    }
}
