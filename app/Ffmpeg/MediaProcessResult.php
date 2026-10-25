<?php

namespace App\Ffmpeg;

readonly class MediaProcessResult
{
    public function __construct(public int $exitCode, public string $diagnosticTail) {}
}
