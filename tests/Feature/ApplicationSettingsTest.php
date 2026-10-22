<?php

use App\Encoding\FfmpegHealth;
use App\Models\Setting;
use App\Settings\ApplicationSettings;
use Illuminate\Support\Facades\Process;
use Illuminate\Validation\ValidationException;

/** @param array<string, mixed> $responses */
function fakeApplicationSettingsHardware(array $responses = []): void
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

}

it('defaults to automatic hardware selection without creating a setting', function (): void {
    fakeApplicationSettingsHardware();
    $settings = app(ApplicationSettings::class);

    expect($settings->hardwareAcceleration())->toBe('auto')
        ->and($settings->resolveHardwareAcceleration(app(FfmpegHealth::class)->detect()))->toBe('nvenc')
        ->and(Setting::query()->count())->toBe(0);
});

it('persists a global preference and updates the same setting', function (): void {
    fakeApplicationSettingsHardware();
    $report = app(FfmpegHealth::class)->detect();
    $settings = app(ApplicationSettings::class);
    $settings->saveHardwareAcceleration('nvenc', $report);
    $settings->saveHardwareAcceleration('qsv', $report);

    expect(app(ApplicationSettings::class)->hardwareAcceleration())->toBe('qsv')
        ->and($settings->resolveHardwareAcceleration($report))->toBe('qsv')
        ->and(Setting::query()->count())->toBe(1)
        ->and(Setting::query()->firstOrFail()->value)->toBe('qsv');
});

it('only offers available hardware backends with video encoders', function (): void {
    fakeApplicationSettingsHardware();
    expect(app(ApplicationSettings::class)->availableHardwareBackends(app(FfmpegHealth::class)->detect()))->toBe([
        'nvenc' => 'NVIDIA NVENC',
        'qsv' => 'Intel Quick Sync (QSV)',
    ]);
});

it('rejects unsupported unavailable and non-encoding backends without changing the preference', function (string $backend): void {
    fakeApplicationSettingsHardware();
    $setting = Setting::factory()->create(['key' => 'hardware_acceleration', 'value' => 'qsv']);

    expect(fn () => app(ApplicationSettings::class)->saveHardwareAcceleration($backend, app(FfmpegHealth::class)->detect()))
        ->toThrow(ValidationException::class)
        ->and($setting->refresh()->value)->toBe('qsv');
})->with(['cuda', 'vaapi', 'invalid', 'qsv; touch /tmp/injected', '']);

it('allows software and automatic modes without available hardware', function (string $mode): void {
    fakeApplicationSettingsHardware();
    $report = app(FfmpegHealth::class)->detect();
    $report['acceleration']['backends'] = [];
    $settings = app(ApplicationSettings::class);
    $settings->saveHardwareAcceleration($mode, $report);

    expect($settings->hardwareAcceleration())->toBe($mode)
        ->and($settings->resolveHardwareAcceleration($report))->toBeNull();
})->with(['auto', 'software']);

it('keeps the preference but falls back to software when its backend disappears', function (): void {
    fakeApplicationSettingsHardware(['*qsv=status:hw*' => Process::result(exitCode: 1)]);
    Setting::factory()->create(['key' => 'hardware_acceleration', 'value' => 'qsv']);
    $report = app(FfmpegHealth::class)->detect();
    $settings = app(ApplicationSettings::class);

    expect($settings->resolveHardwareAcceleration($report))->toBeNull()
        ->and($settings->hardwareAcceleration())->toBe('qsv');
});

it('chooses an encoder backend that supports the requested codec in automatic mode', function (): void {
    fakeApplicationSettingsHardware();
    $report = app(FfmpegHealth::class)->detect();
    $settings = app(ApplicationSettings::class);

    expect($settings->resolveHardwareAcceleration($report, 'hevc'))->toBe('qsv')
        ->and($settings->resolveHardwareAcceleration($report, 'av1'))->toBeNull();

    $settings->saveHardwareAcceleration('nvenc', $report);
    expect($settings->resolveHardwareAcceleration($report, 'hevc'))->toBeNull();
});

it('uses software when explicitly selected even when hardware is available', function (): void {
    fakeApplicationSettingsHardware();
    Setting::factory()->create(['key' => 'hardware_acceleration', 'value' => 'software']);

    expect(app(ApplicationSettings::class)->resolveHardwareAcceleration(app(FfmpegHealth::class)->detect()))->toBeNull();
});
