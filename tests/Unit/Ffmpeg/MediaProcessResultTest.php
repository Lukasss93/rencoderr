<?php

use App\Ffmpeg\MediaProcessResult;

it('retains the AMD descriptor advisory without misclassifying a successful command as a decode error', function (): void {
    $warning = "amdgpu: os_same_file_description couldn't determine if two DRM fds reference the same file description.\nIf they do, bad things may happen!\n";
    $result = new MediaProcessResult(0, $warning);
    expect($result->errorDiagnostics())->toBe('')->and($result->diagnosticTail)->toBe($warning);
    $failed = new MediaProcessResult(234, $warning."Error reinitializing filters!\n");
    expect($failed->errorDiagnostics())->toContain('Error reinitializing filters!')->and($failed->exitCode)->toBe(234);
});

it('retains libva initialization information without treating it as an error', function (string $ending): void {
    $information = implode($ending, [
        'libva info: VA-API version 1.22.0',
        'libva info: Trying to open /usr/lib/x86_64-linux-gnu/dri/iHD_drv_video.so',
        'libva info: Found init function __vaDriverInit_1_22',
        'libva info: va_openDriver() returns 0',
    ]);
    $result = new MediaProcessResult(0, $information.$ending.$information);

    expect($result->errorDiagnostics())->toBe('');
    expect($result->diagnosticTail)->toBe($information.$ending.$information);
})->with(["\n", "\r\n"]);

it('keeps genuine failures alongside libva initialization information', function (int $exitCode): void {
    $diagnostics = "libva info: VA-API version 1.22.0\nlibva error: vaInitialize failed\nError reinitializing filters!\nlibva info: va_openDriver() returns 0\n";
    $result = new MediaProcessResult($exitCode, $diagnostics);

    expect($result->errorDiagnostics())->toBe("libva error: vaInitialize failed\nError reinitializing filters!\n");
    expect($result->diagnosticTail)->toBe($diagnostics);
    expect($result->exitCode)->toBe($exitCode);
})->with([0, 1]);

it('keeps other stderr messages as errors instead of ignoring unknown driver or decoder failures', function (string $message): void {
    expect((new MediaProcessResult(0, $message))->errorDiagnostics())->toBe($message);
})->with(['Decoder failed', 'Failed to create /root/.cache for shader cache (Permission denied)---disabling.', 'amdgpu: GPU reset failed', 'libva error: driver initialization failed', 'Decoder failed: libva info: VA-API version 1.22.0']);
