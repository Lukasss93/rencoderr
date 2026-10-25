<?php

use App\Ffmpeg\MediaProcessResult;

it('retains the AMD descriptor advisory without misclassifying a successful command as a decode error', function (): void {
    $warning = "amdgpu: os_same_file_description couldn't determine if two DRM fds reference the same file description.\nIf they do, bad things may happen!\n";
    $result = new MediaProcessResult(0, $warning);
    expect($result->errorDiagnostics())->toBe('')->and($result->diagnosticTail)->toBe($warning);
    $failed = new MediaProcessResult(234, $warning."Error reinitializing filters!\n");
    expect($failed->errorDiagnostics())->toContain('Error reinitializing filters!')->and($failed->exitCode)->toBe(234);
});

it('keeps other stderr messages as errors instead of ignoring unknown driver or decoder failures', function (string $message): void {
    expect((new MediaProcessResult(0, $message))->errorDiagnostics())->toBe($message);
})->with(['Decoder failed', 'Failed to create /root/.cache for shader cache (Permission denied)---disabling.', 'amdgpu: GPU reset failed']);
