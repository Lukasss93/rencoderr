<?php

namespace App\Transcoding;

readonly class SkippedTranscode
{
    public function __construct(public string $reason, public string $message) {}
}
