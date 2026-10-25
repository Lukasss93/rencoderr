<?php

use Symfony\Component\Process\Process;

it('loads the pinned VMAF model and encodes software video as an unprivileged user without a GPU', function (): void {
    $process = new Process(['docker', 'run', '--rm', '--platform', 'linux/amd64',
        '--user', '1000:1000', '--network', 'none', '--read-only', '--tmpfs', '/tmp:rw,noexec,nosuid,size=64m',
        '--entrypoint', '/opt/rencoderr/verify-ffmpeg.sh', (string) getenv('CONTAINER_IMAGE')], timeout: 180);

    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
    expect($process->getOutput())->toContain('FFmpeg runtime checks passed', 'VMAF: 48 frames');
})->skip(fn (): bool => ! getenv('CONTAINER_IMAGE'), 'Set CONTAINER_IMAGE to run Docker integration tests.');

it('fails the runtime check when the configured VMAF model cannot be loaded', function (): void {
    $process = new Process(['docker', 'run', '--rm', '--platform', 'linux/amd64',
        '--user', '1000:1000', '--network', 'none', '--read-only', '--tmpfs', '/tmp:rw,noexec,nosuid,size=64m',
        '-e', 'QUALITY_CONTROL_MODEL_PATH=/missing/model.json',
        '--entrypoint', '/opt/rencoderr/verify-ffmpeg.sh', (string) getenv('CONTAINER_IMAGE')], timeout: 180);

    $process->run();

    expect($process->getExitCode())->not->toBe(0);
    expect($process->getErrorOutput())->toContain('VMAF model is not readable');
})->skip(fn (): bool => ! getenv('CONTAINER_IMAGE'), 'Set CONTAINER_IMAGE to run Docker integration tests.');
