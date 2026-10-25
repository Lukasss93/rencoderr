<?php

use App\Encoding\AccelerationPreference;
use App\Encoding\Data\Detection;
use App\Encoding\EncoderDetector;
use App\Ffmpeg\Enums\HardwareBackend;
use App\Models\Setting;
use Illuminate\Support\Facades\Process;

/** @param array<string, mixed> $responses */
function detectPreferenceHardware(array $responses = []): Detection
{
    fakeFfmpegDriver();
    Process::preventStrayProcesses();
    Process::fake($responses + [
        '*-version*' => 'ffmpeg version 7.1',
        '*-encoders*' => " V....D h264_nvenc NVIDIA H264 (codec h264)\n V....D h264_qsv Intel H264 (codec h264)\n V....D hevc_qsv Intel HEVC (codec hevc)\n V....D h264_vaapi VAAPI H264 (codec h264)\n A....D aac AAC",
        '*-hwaccels*' => "Hardware acceleration methods:\ncuda\nqsv\nvaapi",
        '*vaapi=status*' => Process::result(exitCode: 1),
        '*=status*' => 'frame=1',
    ]);

    return app(EncoderDetector::class)->refresh();
}

it('defaults to automatic hardware selection without creating a setting', function (): void {
    $detection = detectPreferenceHardware();
    $preference = app(AccelerationPreference::class);

    expect($preference->saved())->toBe('auto')
        ->and($preference->resolve($detection))->toBe(HardwareBackend::NVENC)
        ->and(Setting::query()->count())->toBe(0);
});

it('persists a global preference and updates the same setting', function (): void {
    $detection = detectPreferenceHardware();
    $preference = app(AccelerationPreference::class);
    $preference->save('nvenc');
    $preference->save('qsv');

    expect(app(AccelerationPreference::class)->saved())->toBe('qsv')
        ->and($preference->resolve($detection))->toBe(HardwareBackend::QSV)
        ->and(Setting::query()->count())->toBe(1)
        ->and(Setting::query()->firstOrFail()->value)->toBe('qsv');
});

it('only offers available hardware backends with video encoders', function (): void {
    $detection = detectPreferenceHardware();
    $preference = app(AccelerationPreference::class);

    expect($preference->availableBackends($detection))->toBe([HardwareBackend::NVENC, HardwareBackend::QSV])
        ->and($preference->choices($detection))->toBe(['auto', 'software', 'nvenc', 'qsv']);
});

it('resolves software for automatic and software modes without available hardware', function (string $mode): void {
    $detected = detectPreferenceHardware();
    $detection = new Detection($detected->detectedAt, $detected->version, null, $detected->encoders);
    $preference = app(AccelerationPreference::class);
    $preference->save($mode);

    expect($preference->choices($detection))->toBe(['auto', 'software'])
        ->and($preference->resolve($detection))->toBeNull();
})->with(['auto', 'software']);

it('keeps the preference but falls back to software when its backend disappears', function (): void {
    Setting::factory()->create(['key' => 'hardware_acceleration', 'value' => 'qsv']);
    $detection = detectPreferenceHardware(['*qsv=status:hw*' => Process::result(exitCode: 1)]);
    $preference = app(AccelerationPreference::class);

    expect($preference->resolve($detection))->toBeNull()
        ->and($preference->saved())->toBe('qsv');
});

it('chooses an encoder backend that supports the requested codec in automatic mode', function (): void {
    $detection = detectPreferenceHardware();
    $preference = app(AccelerationPreference::class);

    expect($preference->resolve($detection, 'hevc'))->toBe(HardwareBackend::QSV)
        ->and($preference->resolve($detection, 'av1'))->toBeNull();

    $preference->save('nvenc');
    expect($preference->resolve($detection, 'hevc'))->toBeNull();
});

it('uses software when explicitly selected even when hardware is available', function (): void {
    Setting::factory()->create(['key' => 'hardware_acceleration', 'value' => 'software']);

    expect(app(AccelerationPreference::class)->resolve(detectPreferenceHardware()))->toBeNull();
});

it('does not reuse an explicitly selected device after switching to automatic', function (): void {
    $preference = app(AccelerationPreference::class);
    $preference->save('nvenc', '1');
    $detection = detectPreferenceHardware();

    $preference->save('auto');

    expect($preference->savedDevice())->toBeNull()
        ->and($preference->resolve($detection))->toBe(HardwareBackend::QSV);
});
