<?php

namespace App\QualityControl\Actions;

use App\Models\MediaQualityCheck;
use App\Models\MediaTranscode;
use App\QualityControl\QualityWorkspace;
use App\Tasks\Enums\TaskStatus;
use Illuminate\Support\Str;

class RecoverQualityChecks
{
    public function __construct(private RetryQualityCheck $retry, private RequestQualityCheck $request, private QualityWorkspace $workspace) {}

    /** Only run during exclusive startup, after workers and media processes have stopped. */
    public function handle(): int
    {
        $recovered = 0;
        foreach (MediaQualityCheck::query()->active()->lazyById(200) as $check) {
            $this->workspace->clean($check);
            if ($check->cancel_requested_at !== null || ! $check->transcode()->whereHas('mediaFile.libraryPath.library')->exists()) {
                $check->update(['status' => TaskStatus::CANCELLED, 'request_token' => (string) Str::uuid(),
                    'attempt_token' => null, 'finished_at' => now(), 'reason' => 'cancelled']);

                continue;
            }
            $this->retry->interrupted($check);
            $recovered++;
        }
        foreach (MediaTranscode::query()->where('status', TaskStatus::COMPLETED)->whereDoesntHave('qualityCheck')
            ->whereNotNull('attempt_token')->whereNotNull('output_path')->whereNotNull('output_size_bytes')->lazyById(200) as $item) {
            if ($this->request->handle($item)->status === TaskStatus::QUEUED) {
                $recovered++;
            }
        }

        return $recovered;
    }
}
