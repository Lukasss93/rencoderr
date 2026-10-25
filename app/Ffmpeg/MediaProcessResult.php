<?php

namespace App\Ffmpeg;

readonly class MediaProcessResult
{
    public function __construct(public int $exitCode, public string $diagnosticTail) {}

    public function errorDiagnostics(): string
    {
        return str_replace("amdgpu: os_same_file_description couldn't determine if two DRM fds reference the same file description.\nIf they do, bad things may happen!\n", '', $this->diagnosticTail);
    }
}
