<?php

use App\Ffmpeg\Enums\HardwareBackend;
use App\Ffmpeg\HardwareDecoding;

it('selects backend-specific decoders and downloads frames without reducing bit depth', function (HardwareBackend $backend, string $device, string $api, string $codec): void {
    $decoding = new HardwareDecoding($backend, $device);
    $arguments = $decoding->inputArguments(['index' => 3, 'codec_name' => 'hevc', 'pix_fmt' => 'yuv420p10le']);
    expect($arguments)->toBe(['-hwaccel:3', $api, '-hwaccel_device:3', 'decode', '-hwaccel_output_format:3', 'p010le', '-c:3', $codec])
        ->and(implode(' ', $decoding->deviceArguments()))->toContain($device);
})->with([
    [HardwareBackend::VAAPI, '/dev/dri/renderD129', 'vaapi', 'hevc'],
    [HardwareBackend::QSV, '/dev/dri/renderD129', 'qsv', 'hevc_qsv'],
    [HardwareBackend::NVENC, '1', 'cuda', 'hevc'],
]);

it('keeps software mode and unsupported inputs on CPU without forcing a device', function (): void {
    expect((new HardwareDecoding)->inputArguments(['index' => 0, 'codec_name' => 'h264', 'pix_fmt' => 'yuv420p']))->toBe([])
        ->and((new HardwareDecoding)->deviceArguments())->toBe([]);
    $decoding = new HardwareDecoding(HardwareBackend::VAAPI);
    foreach ([['ffv1', 'yuv420p'], ['hevc', 'yuv444p10le'], ['h264', 'yuv420p10le'], ['unknown', 'yuv420p']] as [$codec, $pixel]) {
        expect($decoding->inputArguments(['index' => 0, 'codec_name' => $codec, 'pix_fmt' => $pixel]))->toBe([]);
    }
});

it('round trips the frozen device selection and rejects malformed saved contexts', function (): void {
    $decoding = new HardwareDecoding(HardwareBackend::NVENC, '0');
    expect(HardwareDecoding::fromPolicy(['decoding' => $decoding->toArray()])->toArray())->toBe($decoding->toArray())
        ->and(HardwareDecoding::fromPolicy([])->inputArguments([]))->toBe([]);
    expect(fn () => HardwareDecoding::fromPolicy(['decoding' => ['backend' => 'invalid', 'device' => null]]))->toThrow(InvalidArgumentException::class);
});
