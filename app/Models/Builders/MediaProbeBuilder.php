<?php

namespace App\Models\Builders;

use App\Models\MediaProbe;
use App\Tasks\Enums\TaskStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A request token identifies one probe request and an attempt token one run of it, so a worker
 * that lost its claim cannot overwrite the work of the one that replaced it.
 *
 * @extends Builder<MediaProbe>
 */
class MediaProbeBuilder extends Builder
{
    public function forRequest(int $fileId, string $token): static
    {
        return $this->where('media_file_id', $fileId)->where('request_token', $token);
    }

    public function forAttempt(string $attempt): static
    {
        return $this->where('attempt_token', $attempt)->where('status', TaskStatus::RUNNING);
    }

    public function claimable(int $staleAfterSeconds): static
    {
        return $this->where(function (Builder $query) use ($staleAfterSeconds): void {
            $query->where('status', TaskStatus::QUEUED)->orWhere(function (Builder $query) use ($staleAfterSeconds): void {
                $query->where('status', TaskStatus::RUNNING)->where('started_at', '<=', now()->subSeconds($staleAfterSeconds));
            });
        });
    }

    public function claim(int $maxAttempts, int $staleAfterSeconds): ?string
    {
        $attempt = (string) Str::uuid();
        $claimed = $this->where('attempts', '<', $maxAttempts)->claimable($staleAfterSeconds)->update([
            'status' => TaskStatus::RUNNING, 'attempt_token' => $attempt, 'started_at' => now(),
            'attempts' => DB::raw('attempts + 1'),
        ]);

        return $claimed === 0 ? null : $attempt;
    }

    public function resetToPending(): int
    {
        return $this->update(MediaProbe::pendingAttributes());
    }
}
