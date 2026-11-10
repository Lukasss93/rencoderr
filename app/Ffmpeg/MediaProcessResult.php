<?php

namespace App\Ffmpeg;

readonly class MediaProcessResult
{
    public function __construct(public int $exitCode, public string $diagnosticTail) {}

    public function errorDiagnostics(): string
    {
        $diagnostics = str_replace("amdgpu: os_same_file_description couldn't determine if two DRM fds reference the same file description.\nIf they do, bad things may happen!\n", '', $this->diagnosticTail);

        return preg_replace('/^libva info: [^\r\n]*(?:\r?\n|$)/m', '', $diagnostics) ?? $diagnostics;
    }
}
