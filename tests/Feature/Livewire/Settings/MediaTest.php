<?php

use App\Encoding\MediaPreferences;
use App\Livewire\Settings\Media;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\postJson;

it('renders an enabled global form with a submit button and draft-only preset action', function (): void {
    $document = new DOMDocument;
    $document->loadHTML(Livewire::test(Media::class)->html(), LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
    $xpath = new DOMXPath($document);

    expect($xpath->evaluate('count(//select[@*[name()="wire:model"]="form.values.video_codec"]/@disabled)'))->toBe(0.0)
        ->and($xpath->evaluate('count(//form[@*[name()="wire:submit"]="save"]//button[@type="submit"])'))->toBe(1.0)
        ->and($xpath->evaluate('string(//button[@*[name()="wire:click"]="applyPreset"]/@type)'))->toBe('button')
        ->and($xpath->evaluate('count(//input[@id="override-video_codec"])'))->toBe(0.0);
});

it('protects media settings and supports local access', function (): void {
    get(route('settings.media'))->assertRedirectToRoute('login');
    Config::set('auth.enabled', false);
    get(route('settings.media'))->assertOk()->assertSee('Media settings')->assertSee('Settings navigation');
    Livewire::test(Media::class)->set('form.values.video_codec', 'av1')->call('save')->assertHasNoErrors();
    expect(Setting::valueOf('media.video_codec'))->toBe('av1');
});

it('rejects a missing settings field without writing a partial configuration', function (): void {
    $component = Livewire::test(Media::class);
    $values = $component->get('form.values');
    unset($values['video_codec']);

    $component->set('form.values', $values)->call('save')->assertHasErrors('form.values.video_codec');
    expect(Setting::query()->count())->toBe(0);
});

it('saves normalized media settings without running media processes', function (): void {
    Queue::fake();
    Process::preventStrayProcesses();
    Process::fake();

    Livewire::test(Media::class)
        ->set('form.values.video_codec', 'av1')->set('form.values.video_quality', 'smaller')
        ->set('form.values.encoding_speed', 'slow')->set('form.values.hdr_mode', 'tone_map')
        ->set('form.values.audio_codec', 'opus')->set('form.values.audio_channels', 'stereo')
        ->set('form.values.audio_bitrate_per_channel', '80')->set('form.values.preserve_lossless_audio', false)
        ->set('form.values.audio_languages', [' ENG ', 'ita', 'eng', 'UND'])
        ->set('form.values.retain_subtitles', false)->set('form.values.subtitle_languages', [' ITA '])
        ->set('form.values.preserve_forced_subtitles', false)->set('form.values.skip_video_codecs', [])
        ->set('form.values.require_smaller_output', false)->set('form.values.minimum_savings_percent', '0')
        ->set('form.values.verify_video_quality', false)->set('form.values.minimum_vmaf', '96.5')
        ->call('save')->assertHasNoErrors()->assertDispatched('ts-ui:toast', title: 'Media settings saved.');

    expect(app(MediaPreferences::class)->globalSettings()->toArray())->toBe([
        'video_codec' => 'av1', 'video_quality' => 'smaller', 'encoding_speed' => 'slow',
        'hdr_mode' => 'tone_map', 'audio_codec' => 'opus', 'audio_channels' => 'stereo',
        'audio_bitrate_per_channel' => 80, 'preserve_lossless_audio' => false, 'audio_languages' => ['eng', 'ita', 'und'],
        'retain_subtitles' => false, 'subtitle_languages' => ['ita'], 'preserve_forced_subtitles' => false,
        'skip_video_codecs' => [], 'require_smaller_output' => false, 'minimum_savings_percent' => 0,
        'verify_video_quality' => false, 'minimum_vmaf' => 96.5,
    ]);
    Livewire::test(Media::class)->assertSet('form.values.audio_languages', ['eng', 'ita', 'und'])
        ->assertSet('form.values.minimum_vmaf', 96.5);
    Queue::assertNothingPushed();
    Process::assertNothingRan();
});

it('rejects invalid values without changing saved settings', function (string $field, mixed $value): void {
    $saved = Setting::factory()->create(['key' => 'media.video_codec', 'value' => 'av1']);

    Livewire::test(Media::class)->set('form.values.'.$field, $value)->call('save')
        ->assertHasErrors()->assertNotDispatched('ts-ui:toast');

    expect($saved->refresh()->value)->toBe('av1')
        ->and(Setting::query()->count())->toBe(1);
})->with([
    'unknown codec' => ['video_codec', 'h264'], 'missing codec' => ['video_codec', ''],
    'quality' => ['video_quality', 'lossless'], 'speed' => ['encoding_speed', 'instant'],
    'HDR' => ['hdr_mode', 'discard'], 'audio codec' => ['audio_codec', 'flac'],
    'channels' => ['audio_channels', '7.1'], 'boolean' => ['retain_subtitles', 'false'],
    'low bitrate' => ['audio_bitrate_per_channel', 31], 'high bitrate' => ['audio_bitrate_per_channel', 257],
    'fractional bitrate' => ['audio_bitrate_per_channel', 64.5], 'text bitrate' => ['audio_bitrate_per_channel', 'many'],
    'negative savings' => ['minimum_savings_percent', -1], 'excess savings' => ['minimum_savings_percent', 100],
    'low VMAF' => ['minimum_vmaf', -1], 'high VMAF' => ['minimum_vmaf', 101],
    'non-list languages' => ['audio_languages', ['first' => 'eng']],
    'invalid language' => ['audio_languages', ['en']], 'nested language' => ['audio_languages', [['eng']]],
    'too many languages' => ['subtitle_languages', array_fill(0, 101, 'eng')],
    'invalid subtitle language' => ['subtitle_languages', ['english']],
    'unknown skip codec' => ['skip_video_codecs', ['vp9']], 'unknown key' => ['arbitrary', 'value'],
]);

it('applies video presets only to draft video fields', function (string $preset, string $codec): void {
    $component = Livewire::test(Media::class)->set('form.values.video_quality', 'smaller')
        ->set('form.values.encoding_speed', 'fast')->set('form.values.hdr_mode', 'skip')
        ->set('form.values.audio_codec', 'aac')->set('form.values.skip_video_codecs', ['av1']);
    $before = $component->get('form.values');

    $component->set('form.selectedPreset', $preset)->call('applyPreset')->assertHasNoErrors()
        ->assertSet('form.values', array_replace($before, [
            'video_codec' => $codec, 'video_quality' => 'high', 'encoding_speed' => 'balanced',
        ]));
    expect(Setting::query()->count())->toBe(0);

    $component->set('form.values.encoding_speed', 'slow')->call('save')->assertHasNoErrors();
    expect(Setting::valueOf('media.encoding_speed'))->toBe('slow');
})->with(['HEVC' => ['hevc_high_quality', 'hevc'], 'AV1' => ['av1_high_quality', 'av1']]);

it('ignores unapplied presets and rejects unknown presets without changing the draft', function (): void {
    $component = Livewire::test(Media::class)->set('form.selectedPreset', 'av1_high_quality')->call('save');
    expect(Setting::valueOf('media.video_codec'))->toBe('hevc');
    $before = $component->get('form.values');

    $component->set('form.selectedPreset', 'unknown')->call('applyPreset')
        ->assertHasErrors('form.selectedPreset')->assertSet('form.values', $before);
});

it('preserves draft edits on refresh and clears validation after correction', function (): void {
    Livewire::test(Media::class)->set('form.values.audio_bitrate_per_channel', 1)->call('save')
        ->assertHasErrors('form.values.audio_bitrate_per_channel')->assertSee('between 32 and 256')
        ->set('form.values.audio_bitrate_per_channel', 32)->call('$refresh')
        ->assertSet('form.values.audio_bitrate_per_channel', 32)->call('save')->assertHasNoErrors();
});

it('prevents saving global media settings after logout', function (): void {
    $response = actingAs(User::factory()->create())->get(route('settings.media'))->assertOk();
    auth()->logout();
    session()->invalidate();
    Auth::forgetGuards();

    postJson(Livewire::getUpdateUri(), livewireUpdatePayload($response, ['form.values.video_codec' => 'av1'], [
        ['method' => 'save', 'params' => []],
    ]), ['X-Livewire' => 'true'])->assertUnauthorized();
    expect(Setting::query()->count())->toBe(0);
});
