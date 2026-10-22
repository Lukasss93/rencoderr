<?php

use App\Encoding\FfmpegHealth;
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

    $report = app(FfmpegHealth::class)->detect();

    expect($report['acceleration']['backends'])->toHaveKeys(['qsv', 'vaapi', 'cuda', 'nvenc', 'vulkan', 'future_backend'])
        ->and($report['acceleration']['backends']['qsv'])->toMatchArray(['available' => true, 'encoders' => 0])
        ->and($report['acceleration']['backends']['vaapi']['available'])->toBeFalse()
        ->and($report['acceleration']['backends']['cuda']['available'])->toBeTrue()
        ->and($report['acceleration']['backends']['nvenc'])->toMatchArray(['available' => false, 'encoders' => 1])
        ->and($report['acceleration']['backends']['vulkan']['available'])->toBeFalse()
        ->and($report['acceleration']['backends']['future_backend']['available'])->toBeFalse()
        ->and($report['encoders'][0]['name'])->toBe('aac');
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

    $report = app(FfmpegHealth::class)->detect();

    expect($report['acceleration']['backends']['nvenc'])->toMatchArray(['available' => true, 'encoders' => 1])
        ->and($report['acceleration']['backends']['amf'])->toMatchArray(['available' => false, 'encoders' => 1]);
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

    $report = app(FfmpegHealth::class)->detect();

    expect($report['error'])->toBeNull()
        ->and($report['encoders'][0]['name'])->toBe('libx264')
        ->and($report['acceleration']['error'])->not->toBeNull();
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

    $report = app(FfmpegHealth::class)->detect();

    expect($report['acceleration']['backends']['qsv']['available'])->toBeFalse()
        ->and($report['acceleration']['backends']['qsv']['message'])->toContain('timed out')
        ->and($report['acceleration']['backends']['vaapi']['available'])->toBeTrue();
});

it('does not mark a successful device command without a frame as available', function (): void {
    Process::preventStrayProcesses();
    Process::fake([
        '*-version*' => 'ffmpeg version 7.1',
        '*-encoders*' => ' A....D aac AAC',
        '*-hwaccels*' => "Hardware acceleration methods:\nvaapi",
        '*vaapi=status*' => 'frame=0',
    ]);

    $report = app(FfmpegHealth::class)->detect();

    expect($report['acceleration']['backends']['vaapi']['available'])->toBeFalse();
});

it('classifies Media Foundation audio encoders as software', function (): void {
    Process::preventStrayProcesses();
    Process::fake([
        '*-version*' => 'ffmpeg version 7.1',
        '*-encoders*' => " A....D aac_mf AAC Media Foundation (codec aac)\n A....D ac3_mf AC3 Media Foundation (codec ac3)\n A....D mp3_mf MP3 Media Foundation (codec mp3)\n V....D h264_mf H264 Media Foundation (codec h264)",
        '*-hwaccels*' => 'Hardware acceleration methods:',
        '*' => Process::result(exitCode: 1),
    ]);

    $report = app(FfmpegHealth::class)->detect();
    $encoders = collect($report['encoders'])->keyBy('name');

    expect($encoders['aac_mf'])->toMatchArray(['hardware' => false, 'backend' => null])
        ->and($encoders['ac3_mf'])->toMatchArray(['hardware' => false, 'backend' => null])
        ->and($encoders['mp3_mf'])->toMatchArray(['hardware' => false, 'backend' => null])
        ->and($encoders['h264_mf'])->toMatchArray(['hardware' => true, 'backend' => 'mf'])
        ->and($report['acceleration']['backends']['mf']['encoders'])->toBe(1);
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

    expect(app(FfmpegHealth::class)->detect()['acceleration']['backends']['vulkan']['available'])->toBe($available);
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

    expect(app(FfmpegHealth::class)->detect()['acceleration']['backends'][$backend]['available'])->toBeTrue();
    Process::assertRan(fn (PendingProcess $process): bool => is_array($process->command)
        && array_all($requiredArguments, fn (string $argument): bool => in_array($argument, $process->command, true)));
})->with([
    'Media Foundation' => ['mf', 'h264_mf', ['-hw_encoding', '1']],
    'VideoToolbox' => ['videotoolbox', 'h264_videotoolbox', ['-allow_sw', '-require_sw', '0']],
    'OpenCL' => ['opencl', 'libx264', ['-init_hw_device', 'opencl=status:,device_type=gpu']],
]);

it('reports missing binaries discovered by PHP-FFMpeg', function (): void {
    app()->bind(FFMpegDriver::class, fn () => throw new ExecutableNotFoundException('Unable to load FFmpeg'));

    expect(app(FfmpegHealth::class)->detect())->toMatchArray([
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

    expect(app(FfmpegHealth::class)->detect()['acceleration']['backends'][$backend]['available'])->toBeTrue();
    Process::assertRan(fn (PendingProcess $process): bool => is_array($process->command)
        && in_array('h264_'.$backend, $process->command, true));
})->with([
    'NVENC' => ['nvenc'],
    'AMF' => ['amf'],
    'VideoToolbox' => ['videotoolbox'],
]);
