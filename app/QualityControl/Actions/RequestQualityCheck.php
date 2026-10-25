<?php

namespace App\QualityControl\Actions;

use App\Jobs\CheckMediaQuality;
use App\Models\MediaQualityCheck;
use App\Models\MediaTranscode;
use App\QualityControl\QualityPolicy;
use App\Tasks\Enums\TaskStatus;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Str;
use LogicException;

class RequestQualityCheck
{
    public function __construct(private QualityPolicy $policy, private Dispatcher $bus) {}

    public function handle(MediaTranscode $transcode): MediaQualityCheck
    {
        $transcode->refresh();
        if ($transcode->status !== TaskStatus::COMPLETED || $transcode->attempt_token === null
            || $transcode->output_path === null || $transcode->output_size_bytes === null) {
            throw new LogicException('Quality control requires an accepted transcode output.');
        }
        $cancelled = ! $transcode->mediaFile()->whereHas('libraryPath.library')->exists();
        $policy = $this->policy->snapshot($transcode);
        $check = MediaQualityCheck::query()->firstOrCreate(['media_transcode_id' => $transcode->id], [
            'status' => $cancelled ? TaskStatus::CANCELLED : TaskStatus::QUEUED,
            'finished_at' => $cancelled ? now() : null, 'reason' => $cancelled ? 'cancelled' : null, 'request_token' => (string) Str::uuid(),
            'transcode_attempt_token' => $transcode->attempt_token, 'policy' => $policy,
            'model_sha256' => $policy['model_sha256'] ?? null,
            'source_path' => $transcode->source_path, 'source_size_bytes' => $transcode->source_size_bytes,
            'source_modified_at' => $transcode->source_modified_at, 'output_path' => $transcode->output_path,
            'output_size_bytes' => $transcode->output_size_bytes, 'execution_limit_seconds' => $policy['execution_limit_seconds'] ?? (int) config('quality_control.timeout_seconds'),
            'threads' => $policy['threads'] ?? (int) config('quality_control.threads'),
        ]);
        if ($check->wasRecentlyCreated && ! $cancelled) {
            $this->bus->dispatch(new CheckMediaQuality($check->id, $check->request_token, $check->execution_limit_seconds));
        }

        return $check;
    }
}
