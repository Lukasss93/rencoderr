<?php

namespace App\Transcoding;

readonly class TranscodeProcessResult
{
    public function __construct(public int $exitCode, public string $diagnosticTail) {}
}
