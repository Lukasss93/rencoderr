<?php

use App\Encoding\EncoderDetector;
use App\Ffmpeg\Enums\HardwareBackend;
use FFMpeg\Driver\FFMpegDriver;
use FFMpeg\Exception\ExecutableNotFoundException;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process as SymfonyProcess;

beforeEach(fn () => fakeFfmpegDriver());

it('detects acceleration backends separately from their installed encoders', function (): void {
    Process::preventStrayProcesses();
    Process::fake([
        '*-version*' => 'ffmpeg version 7.1',
        '*-encoders*' => " V....D h264_nvenc NVIDIA H.264 (codec h264)\n V....D libx264 H.264 (codec h264)\n A....D aac AAC",
        '*-hwaccels*' => "Hardware acceleration methods:\nqsv\nvaapi\ncuda\nvulkan\nfuture_backend\n",
        '*qsv=status:hw*' => 'frame=1',
        '*vaapi=status*' => Process::result(errorOutput: 'No device', exitCode: 1),
        '*cuda=status*-c:v*' => Process::result(errorOutput: 'NVENC unavailable', exitCode: 1),
        '*cuda=status*' => 'frame=1',
        '*' => Process::result(exitCode: 1),
    ]);

    $detection = app(EncoderDetector::class)->refresh();

    expect($detection->backends)->toHaveKeys(['qsv', 'vaapi', 'cuda', 'nvenc', 'vulkan', 'future_backend'])
        ->and($detection->backends['qsv'])->toMatchArray(['available' => true, 'encoders' => 0])
        ->and($detection->backends['vaapi']->available)->toBeFalse()
        ->and($detection->backends['cuda']->available)->toBeTrue()
        ->and($detection->backends['nvenc'])->toMatchArray(['available' => false, 'encoders' => 1])
        ->and($detection->backends['vulkan']->available)->toBeFalse()
        ->and($detection->backends['future_backend']->available)->toBeFalse()
        ->and($detection->encoders[0]->name)->toBe('aac');
    Process::assertRanTimes(fn (PendingProcess $process): bool => true, 9);
});

it('includes encoder backends missing from the hardware acceleration list', function (): void {
    Process::preventStrayProcesses();
    Process::fake([
        '*-version*' => 'ffmpeg version 7.1',
        '*-encoders*' => " V....D h264_nvenc NVIDIA H.264 (codec h264)\n V....D hevc_amf AMD HEVC (codec hevc)",
        '*-hwaccels*' => 'Hardware acceleration methods:',
        '*cuda=status*' => 'frame=1',
        '*' => Process::result(exitCode: 1),
    ]);

    $detection = app(EncoderDetector::class)->refresh();

    expect($detection->backends['nvenc'])->toMatchArray(['available' => true, 'encoders' => 1])
        ->and($detection->backends['amf'])->toMatchArray(['available' => false, 'encoders' => 1]);
    Process::assertRan(fn (PendingProcess $process): bool => is_array($process->command)
        && array_slice($process->command, -9) === ['-c:v', 'h264_nvenc', '-frames:v', '1', '-progress', 'pipe:1', '-f', 'null', '-']
        && $process->timeout === 3);
});

it('keeps encoders visible when acceleration discovery fails', function (string $output, int $exitCode): void {
    Process::preventStrayProcesses();
    Process::fake([
        '*-version*' => 'ffmpeg version 7.1',
        '*-encoders*' => ' V....D libx264 H.264 (codec h264)',
        '*-hwaccels*' => Process::result(output: $output, exitCode: $exitCode),
    ]);

    $detection = app(EncoderDetector::class)->refresh();

    expect($detection->error)->toBeNull()
        ->and($detection->encoders[0]->name)->toBe('libx264')
        ->and($detection->accelerationError)->not->toBeNull();
    Process::assertRanTimes(fn (PendingProcess $process): bool => true, 3);
})->with(['failed command' => ['', 1], 'malformed output' => ['invalid listing', 0]]);

it('does not claim acceleration is available when a device probe times out', function (): void {
    Process::preventStrayProcesses();
    Process::fake([
        '*-version*' => 'ffmpeg version 7.1',
        '*-encoders*' => ' A....D aac AAC',
        '*-hwaccels*' => "Hardware acceleration methods:\nqsv\nvaapi",
        '*qsv=status:hw*' => fn () => throw new ProcessTimedOutException(new SymfonyProcess(['ffmpeg'], timeout: 3), ProcessTimedOutException::TYPE_GENERAL),
        '*vaapi=status*' => 'frame=1',
    ]);

    $detection = app(EncoderDetector::class)->refresh();

    expect($detection->backends['qsv']->available)->toBeFalse()
        ->and($detection->backends['qsv']->message)->toContain('timed out')
        ->and($detection->backends['vaapi']->available)->toBeTrue();
});

it('does not mark a successful device command without a frame as available', function (): void {
    Process::preventStrayProcesses();
    Process::fake([
        '*-version*' => 'ffmpeg version 7.1',
        '*-encoders*' => ' A....D aac AAC',
        '*-hwaccels*' => "Hardware acceleration methods:\nvaapi",
        '*vaapi=status*' => 'frame=0',
    ]);

    $detection = app(EncoderDetector::class)->refresh();

    expect($detection->backends['vaapi']->available)->toBeFalse();
});

it('classifies Media Foundation audio encoders as software', function (): void {
    Process::preventStrayProcesses();
    Process::fake([
        '*-version*' => 'ffmpeg version 7.1',
        '*-encoders*' => " A....D aac_mf AAC Media Foundation (codec aac)\n A....D ac3_mf AC3 Media Foundation (codec ac3)\n A....D mp3_mf MP3 Media Foundation (codec mp3)\n V....D h264_mf H264 Media Foundation (codec h264)",
        '*-hwaccels*' => 'Hardware acceleration methods:',
        '*' => Process::result(exitCode: 1),
    ]);

    $detection = app(EncoderDetector::class)->refresh();

    expect(array_column($detection->encoders, 'backend', 'name'))
        ->toMatchArray(['aac_mf' => null, 'ac3_mf' => null, 'mp3_mf' => null, 'h264_mf' => HardwareBackend::MF])
        ->and($detection->backends['mf']->encoders)->toBe(1);
    Process::assertRanTimes(fn (PendingProcess $process): bool => true, 4);
});

it('requires a hardware Vulkan device instead of a software implementation', function (string $details, bool $available): void {
    Process::preventStrayProcesses();
    Process::fake([
        '*-version*' => 'ffmpeg version 7.1',
        '*-encoders*' => ' V....D h264_vulkan Vulkan H264 (codec h264)',
        '*-hwaccels*' => "Hardware acceleration methods:\nvulkan",
        '*vulkan=status*' => Process::result(output: 'frame=1', errorOutput: $details),
    ]);

    expect(app(EncoderDetector::class)->refresh()->backends['vulkan']->available)->toBe($available);
})->with([
    'discrete GPU' => ['Device 0 selected: NVIDIA (discrete) (0x1234)', true],
    'integrated GPU' => ['Device 0 selected: Intel (integrated) (0x1234)', true],
    'software device' => ['Device 0 selected: llvmpipe (software) (0x0000)', false],
    'GPU listed but CPU selected' => ["0: Intel (integrated)\nDevice 1 selected: llvmpipe (software) (0x0000)", false],
    'no device details' => ['', false],
]);

it('disables software fallback when probing acceleration', function (string $backend, string $encoder, array $requiredArguments): void {
    Process::preventStrayProcesses();
    Process::fake([
        '*-version*' => 'ffmpeg version 7.1',
        '*-encoders*' => " V....D {$encoder} H264 (codec h264)",
        '*-hwaccels*' => "Hardware acceleration methods:\n{$backend}",
        '*' => 'frame=1',
    ]);

    expect(app(EncoderDetector::class)->refresh()->backends[$backend]->available)->toBeTrue();
    Process::assertRan(fn (PendingProcess $process): bool => is_array($process->command)
        && array_all($requiredArguments, fn (string $argument): bool => in_array($argument, $process->command, true)));
})->with([
    'Media Foundation' => ['mf', 'h264_mf', ['-hw_encoding', '1']],
    'VideoToolbox' => ['videotoolbox', 'h264_videotoolbox', ['-allow_sw', '-require_sw', '0']],
    'OpenCL' => ['opencl', 'libx264', ['-init_hw_device', 'opencl=status:,device_type=gpu']],
]);

it('reports missing binaries discovered by PHP-FFMpeg', function (): void {
    app()->bind(FFMpegDriver::class, fn () => throw new ExecutableNotFoundException('Unable to load FFmpeg'));

    expect(app(EncoderDetector::class)->refresh())->toMatchArray([
        'version' => null,
        'error' => 'Install FFmpeg and make sure the application can run it from PATH.',
        'encoders' => [],
    ]);
});

it('probes widely supported codecs before newer codecs for encoder backends', function (string $backend): void {
    Process::preventStrayProcesses();
    Process::fake([
        '*-version*' => 'ffmpeg version 7.1',
        '*-encoders*' => " V....D av1_{$backend} AV1 (codec av1)\n V....D h264_{$backend} H264 (codec h264)\n V....D hevc_{$backend} HEVC (codec hevc)",
        '*-hwaccels*' => 'Hardware acceleration methods:',
        '*h264_'.$backend.'*' => 'frame=1',
        '*' => Process::result(errorOutput: 'AV1 is not supported by this GPU', exitCode: 1),
    ]);

    expect(app(EncoderDetector::class)->refresh()->backends[$backend]->available)->toBeTrue();
    Process::assertRan(fn (PendingProcess $process): bool => is_array($process->command)
        && in_array('h264_'.$backend, $process->command, true));
})->with([
    'NVENC' => ['nvenc'],
    'AMF' => ['amf'],
    'VideoToolbox' => ['videotoolbox'],
]);

it('reuses the stored detection until it is refreshed', function (): void {
    Process::preventStrayProcesses();
    Process::fake([
        '*-version*' => 'ffmpeg version 7.1',
        '*-encoders*' => Process::sequence()->push(' V....D libx264 H.264 (codec h264)')->push(' V....D libx265 HEVC (codec hevc)'),
        '*-hwaccels*' => 'Hardware acceleration methods:',
    ]);
    $detector = app(EncoderDetector::class);

    expect($detector->current()->encoders[0]->name)->toBe('libx264')
        ->and($detector->current()->encoders[0]->name)->toBe('libx264')
        ->and($detector->refresh()->encoders[0]->name)->toBe('libx265')
        ->and($detector->current()->encoders[0]->name)->toBe('libx265');
    Process::assertRanTimes(fn (PendingProcess $process): bool => true, 6);
});
