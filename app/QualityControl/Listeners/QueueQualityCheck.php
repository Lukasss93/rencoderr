<?php

namespace App\QualityControl\Listeners;

use App\Models\MediaTranscode;
use App\QualityControl\Actions\RequestQualityCheck;
use App\Tasks\Enums\TaskStatus;
use App\Transcoding\Events\TranscodeCompleted;

class QueueQualityCheck
{
    public function __construct(private RequestQualityCheck $request) {}

    public function handle(TranscodeCompleted $event): void
    {
        $transcode = MediaTranscode::query()->whereKey($event->transcodeId)
            ->where('attempt_token', $event->attemptToken)->where('status', TaskStatus::COMPLETED)->first();
        if ($transcode !== null) {
            $this->request->handle($transcode);
        }
    }
}
