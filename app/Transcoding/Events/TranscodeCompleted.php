<?php

namespace App\Transcoding\Events;

readonly class TranscodeCompleted
{
    public function __construct(public int $transcodeId, public string $attemptToken) {}
}
