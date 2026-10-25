<?php

use App\Encoding\Data\MediaSettings;
use App\Encoding\Enums\TargetVideoCodec;
use App\Encoding\MediaPreferences;
use App\Models\Library;
use App\Models\LibraryPath;
use App\Models\Setting;
use Illuminate\Support\Facades\Queue;

it('provides preservation defaults without writing settings', function (): void {
    expect(app(MediaPreferences::class)->globalSettings()->toArray())->toBe([
        'video_codec' => 'hevc', 'video_quality' => 'high', 'encoding_speed' => 'balanced',
        'hdr_mode' => 'preserve', 'audio_codec' => 'copy', 'audio_channels' => 'preserve',
        'audio_bitrate_per_channel' => 64, 'preserve_lossless_audio' => true, 'audio_languages' => [],
        'retain_subtitles' => true, 'subtitle_languages' => [], 'preserve_forced_subtitles' => true,
        'skip_video_codecs' => ['hevc', 'av1'], 'require_smaller_output' => true,
        'minimum_savings_percent' => 5, 'verify_video_quality' => true, 'minimum_vmaf' => 95.0,
    ])->and(Setting::query()->count())->toBe(0);
});

it('persists globals and supplies defaults for missing saved fields', function (): void {
    Setting::factory()->create(['key' => 'media.video_codec', 'value' => 'av1']);
    $otherSetting = Setting::factory()->create(['key' => 'hardware_acceleration', 'value' => 'software']);
    $preferences = app(MediaPreferences::class);
    expect($preferences->globalSettings()->videoCodec)->toBe(TargetVideoCodec::AV1)
        ->and($preferences->globalSettings()->minimumVmaf)->toBe(95.0);

    $preferences->saveGlobal(new MediaSettings(minimumVmaf: 96.5));

    expect(app(MediaPreferences::class)->globalSettings()->minimumVmaf)->toBe(96.5)
        ->and(Setting::valueOf('media.video_codec'))->toBe('hevc')
        ->and(Setting::valueOf('media.minimum_vmaf'))->toBe(96.5)
        ->and(Setting::query()->where('key', 'like', 'media.%')->count())->toBe(17)
        ->and(Setting::query()->where('key', 'media_defaults')->exists())->toBeFalse()
        ->and($otherSetting->refresh()->value)->toBe('software');
});

it('stores false zero and empty lists as individual settings without losing their types', function (): void {
    $preferences = app(MediaPreferences::class);
    $preferences->saveGlobal(new MediaSettings(
        preserveLosslessAudio: false, audioLanguages: [], minimumSavingsPercent: 0,
    ));

    expect(Setting::valueOf('media.preserve_lossless_audio'))->toBeFalse()
        ->and(Setting::valueOf('media.minimum_savings_percent'))->toBe(0)
        ->and(Setting::valueOf('media.audio_languages'))->toBe([]);
    $settings = $preferences->globalSettings();
    expect($settings->preserveLosslessAudio)->toBeFalse()
        ->and($settings->minimumSavingsPercent)->toBe(0)
        ->and($settings->audioLanguages)->toBe([]);
});

it('inherits current globals without materializing library defaults', function (): void {
    $library = Library::factory()->create();
    $preferences = app(MediaPreferences::class);
    $preferences->saveGlobal(new MediaSettings(videoCodec: TargetVideoCodec::AV1));

    expect($preferences->forLibrary($library)->videoCodec)->toBe(TargetVideoCodec::AV1)
        ->and($library->settings()->pluck('value', 'key')->all())->toBe([]);
});

it('keeps equal false zero and empty overrides when globals change', function (): void {
    $library = Library::factory()->create();
    $other = Library::factory()->create();
    $preferences = app(MediaPreferences::class);
    $preferences->saveLibrary($library, new MediaSettings(
        preserveLosslessAudio: false, audioLanguages: [], minimumSavingsPercent: 0,
    ), ['video_codec', 'preserve_lossless_audio', 'audio_languages', 'minimum_savings_percent']);

    $preferences->saveGlobal(new MediaSettings(
        videoCodec: TargetVideoCodec::AV1, audioLanguages: ['eng'], minimumVmaf: 97,
    ));

    expect($library->settings()->pluck('value', 'key')->all())->toEqual([
        'media.video_codec' => 'hevc', 'media.preserve_lossless_audio' => false,
        'media.audio_languages' => [], 'media.minimum_savings_percent' => 0,
    ]);
    $resolved = $preferences->forLibrary($library);
    expect($resolved->videoCodec)->toBe(TargetVideoCodec::HEVC)
        ->and($resolved->preserveLosslessAudio)->toBeFalse()
        ->and($resolved->audioLanguages)->toBe([])
        ->and($resolved->minimumSavingsPercent)->toBe(0)
        ->and($resolved->minimumVmaf)->toBe(97.0)
        ->and($preferences->forLibrary($other)->videoCodec)->toBe(TargetVideoCodec::AV1);
});

it('replaces lists and removes overrides without changing library scanning', function (): void {
    $path = LibraryPath::factory()->create();
    $library = $path->library()->firstOrFail();
    $original = $library->getAttributes();
    $preferences = app(MediaPreferences::class);
    $preferences->saveGlobal(new MediaSettings(audioLanguages: ['eng', 'ita', 'und']));
    Queue::fake();

    $settings = new MediaSettings(audioLanguages: ['jpn'], minimumSavingsPercent: 10);
    $preferences->saveLibrary($library, $settings, ['audio_languages', 'minimum_savings_percent']);
    expect($preferences->forLibrary($library)->audioLanguages)->toBe(['jpn']);
    $preferences->saveLibrary($library, $settings, ['minimum_savings_percent']);
    expect($preferences->forLibrary($library)->audioLanguages)->toBe(['eng', 'ita', 'und']);
    $preferences->saveLibrary($library, $settings, []);

    expect($library->settings()->pluck('value', 'key')->all())->toBe([])
        ->and($library->name)->toBe($original['name'])
        ->and($library->scan_interval_minutes)->toBe($original['scan_interval_minutes'])
        ->and($library->last_scan_requested_at)->toBeNull()
        ->and($library->paths()->sole()->id)->toBe($path->id);
    Queue::assertNothingPushed();
});

it('does not treat another owners media settings as global defaults', function (): void {
    $library = Library::factory()->create();
    Setting::factory()->for($library, 'settingable')->create(['key' => 'media.video_codec', 'value' => 'av1']);
    $preferences = app(MediaPreferences::class);

    expect($preferences->globalSettings()->videoCodec)->toBe(TargetVideoCodec::HEVC)
        ->and($preferences->forLibrary($library)->videoCodec)->toBe(TargetVideoCodec::AV1);

    $preferences->saveGlobal(new MediaSettings);
    expect($preferences->forLibrary($library)->videoCodec)->toBe(TargetVideoCodec::AV1);
});

it('preserves unrelated library settings when replacing media overrides', function (): void {
    $library = Library::factory()->create();
    $otherSetting = $library->settings()->create(['key' => 'display.order', 'value' => 'name']);
    $preferences = app(MediaPreferences::class);
    $preferences->saveLibrary($library, new MediaSettings(videoCodec: TargetVideoCodec::AV1), ['video_codec']);
    expect($preferences->overrides($library))->toBe(['video_codec' => 'av1']);

    $preferences->saveLibrary($library, new MediaSettings, []);
    expect($preferences->overrides($library))->toBe([])
        ->and($library->settings()->sole()->id)->toBe($otherSetting->id);
});

it('rolls back a global save if one setting cannot be persisted', function (): void {
    $preferences = app(MediaPreferences::class);
    $preferences->saveGlobal(new MediaSettings);
    Setting::saving(function (Setting $setting): void {
        if ($setting->key === 'media.audio_codec') {
            throw new RuntimeException('Unable to save audio settings.');
        }
    });

    expect(fn () => $preferences->saveGlobal(new MediaSettings(videoCodec: TargetVideoCodec::AV1)))
        ->toThrow(RuntimeException::class, 'Unable to save audio settings.');
    expect(Setting::valueOf('media.video_codec'))->toBe('hevc');
});
