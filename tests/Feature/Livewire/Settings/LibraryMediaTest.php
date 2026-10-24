<?php

use App\Encoding\Data\MediaSettings;
use App\Encoding\Enums\TargetVideoCodec;
use App\Encoding\MediaPreferences;
use App\Livewire\Settings\Libraries;
use App\Livewire\Settings\LibraryMedia;
use App\Models\Library;
use App\Models\LibraryPath;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\postJson;

it('renders inherited controls disabled and wires the override checkbox to the correct field', function (): void {
    $library = Library::factory()->create();
    $component = Livewire::test(LibraryMedia::class, ['library' => $library]);
    $document = new DOMDocument;
    $document->loadHTML($component->html(), LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
    $xpath = new DOMXPath($document);

    expect($xpath->evaluate('count(//select[@*[name()="wire:model"]="form.values.video_codec"]/@disabled)'))->toBe(1.0)
        ->and($xpath->evaluate('count(//input[@id="override-video_codec"]/@checked)'))->toBe(0.0)
        ->and($xpath->evaluate('string(//input[@id="override-video_codec"]/@*[name()="wire:change"])'))
        ->toBe("setOverride('video_codec', \$event.target.checked)");

    $component->call('setOverride', 'video_codec', true);
    $document->loadHTML($component->html(), LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
    $xpath = new DOMXPath($document);
    expect($xpath->evaluate('count(//select[@*[name()="wire:model"]="form.values.video_codec"]/@disabled)'))->toBe(0.0)
        ->and($xpath->evaluate('count(//input[@id="override-video_codec"]/@checked)'))->toBe(1.0);
});

it('protects library settings and supports local access and missing libraries', function (): void {
    $library = Library::factory()->create();
    get(route('settings.libraries.media', $library))->assertRedirectToRoute('login');
    Config::set('auth.enabled', false);
    get(route('settings.libraries.media', $library))->assertOk()->assertSee($library->name);
    get(route('settings.libraries.media', 999999))->assertNotFound();
    Livewire::test(LibraryMedia::class, ['library' => $library])->call('setOverride', 'video_codec', true)
        ->set('form.values.video_codec', 'av1')->call('save')->assertHasNoErrors();
    expect($library->settings()->pluck('value', 'key')->all())->toEqual(['media.video_codec' => 'av1']);
});

it('remounts language controls when inheritance changes to refresh their Alpine lock state', function (string $field): void {
    $library = Library::factory()->create();
    $component = Livewire::test(LibraryMedia::class, ['library' => $library]);
    $key = function () use ($component, $field): string {
        $document = new DOMDocument;
        $document->loadHTML($component->html(), LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);

        return (string) (new DOMXPath($document))->evaluate(
            'string(//div[starts-with(@*[name()="wire:key"], "media-setting-'.$field.'")]/@*[name()="wire:key"])',
        );
    };
    $inheritedKey = $key();

    $component->call('setOverride', $field, true);
    expect($key())->not->toBe($inheritedKey);

    $component->call('inheritAll');
    expect($key())->toBe($inheritedKey);
})->with(['audio_languages', 'subtitle_languages']);

it('rejects a missing inheritance flag instead of silently dropping an override', function (): void {
    $library = Library::factory()->withSettings(['media.video_codec' => 'av1'])->create();
    $component = Livewire::test(LibraryMedia::class, ['library' => $library]);
    $flags = $component->get('form.overridden');
    unset($flags['video_codec']);

    $component->set('form.overridden', $flags)->call('save')->assertHasErrors('form.overridden');
    expect($library->settings()->pluck('value', 'key')->all())->toEqual(['media.video_codec' => 'av1']);
});

it('saves only overrides and refreshes inheritance after saving a stale form', function (): void {
    $library = Library::factory()->create();
    $component = Livewire::test(LibraryMedia::class, ['library' => $library])
        ->call('setOverride', 'preserve_lossless_audio', true)->set('form.values.preserve_lossless_audio', false)
        ->call('setOverride', 'minimum_savings_percent', true)->set('form.values.minimum_savings_percent', 0)
        ->call('setOverride', 'audio_languages', true)->set('form.values.audio_languages', []);
    app(MediaPreferences::class)->saveGlobal(new MediaSettings(videoCodec: TargetVideoCodec::AV1, audioLanguages: ['ita']));
    Queue::fake();

    $component->call('save')->assertHasNoErrors()->assertSet('form.values.video_codec', 'av1')
        ->assertSet('form.values.audio_languages', []);

    expect($library->settings()->pluck('value', 'key')->all())->toEqual([
        'media.preserve_lossless_audio' => false, 'media.audio_languages' => [], 'media.minimum_savings_percent' => 0,
    ]);
    Queue::assertNothingPushed();
});

it('applies presets as three overrides without changing other inheritance', function (string $preset, string $codec): void {
    $library = Library::factory()->withSettings(['media.hdr_mode' => 'skip'])->create();
    $component = Livewire::test(LibraryMedia::class, ['library' => $library])
        ->set('form.selectedPreset', $preset)->call('applyPreset')
        ->assertSet('form.overridden.video_codec', true)->assertSet('form.overridden.video_quality', true)
        ->assertSet('form.overridden.encoding_speed', true)->assertSet('form.overridden.hdr_mode', true)
        ->assertSet('form.overridden.audio_codec', false)->assertSet('form.values.hdr_mode', 'skip');
    expect($library->settings()->pluck('value', 'key')->all())->toEqual(['media.hdr_mode' => 'skip']);

    $component->call('save')->assertHasNoErrors();
    expect($library->settings()->pluck('value', 'key')->all())->toEqual([
        'media.video_codec' => $codec, 'media.video_quality' => 'high', 'media.encoding_speed' => 'balanced', 'media.hdr_mode' => 'skip',
    ]);
})->with(['HEVC' => ['hevc_high_quality', 'hevc'], 'AV1' => ['av1_high_quality', 'av1']]);

it('resets fields and all overrides only when saved', function (): void {
    $library = Library::factory()->withSettings(['media.video_codec' => 'av1', 'media.minimum_savings_percent' => 20])->create();
    $component = Livewire::test(LibraryMedia::class, ['library' => $library])
        ->set('form.values.video_codec', 'invalid')->call('save')->assertHasErrors('form.values.video_codec')
        ->call('setOverride', 'video_codec', false)->assertHasNoErrors('form.values.video_codec')
        ->assertSet('form.values.video_codec', 'hevc');
    expect($library->settings()->pluck('value', 'key')->all())->toEqual(['media.video_codec' => 'av1', 'media.minimum_savings_percent' => 20]);

    $component->call('save')->assertHasNoErrors();
    expect($library->settings()->pluck('value', 'key')->all())->toEqual(['media.minimum_savings_percent' => 20]);

    $component->call('inheritAll')->assertSet('form.values.minimum_savings_percent', 5);
    expect($library->settings()->pluck('value', 'key')->all())->toEqual(['media.minimum_savings_percent' => 20]);
    $component->call('save')->assertHasNoErrors();
    expect($library->settings()->pluck('value', 'key')->all())->toEqual([]);
});

it('rejects invalid inheritance flags without changing the library', function (string $field, mixed $value): void {
    $library = Library::factory()->create();
    Livewire::test(LibraryMedia::class, ['library' => $library])->set('form.overridden.'.$field, $value)
        ->call('save')->assertHasErrors();
    expect($library->settings()->pluck('value', 'key')->all())->toEqual([]);
})->with(['unknown field' => ['unknown', true], 'invalid boolean' => ['video_codec', 'yes']]);

it('rejects an unknown inheritance field without changing the draft', function (): void {
    $library = Library::factory()->create();
    $component = Livewire::test(LibraryMedia::class, ['library' => $library]);
    $before = $component->get('form.values');
    $component->call('setOverride', 'unknown', true)->assertHasErrors('form.overridden')->assertSet('form.values', $before);
    expect($library->settings()->pluck('value', 'key')->all())->toEqual([]);
});

it('does not allow changing the library identity', function (): void {
    $library = Library::factory()->create();
    $other = Library::factory()->create();
    Livewire::test(LibraryMedia::class, ['library' => $library])->set('libraryId', $other->id);
})->throws(CannotUpdateLockedPropertyException::class);

it('does not recreate a library deleted while its settings page was open', function (): void {
    $library = Library::factory()->create();
    $component = Livewire::test(LibraryMedia::class, ['library' => $library]);
    $library->delete();
    $component->call('save')->assertNotFound();
    expect(Library::query()->count())->toBe(0)->and(Setting::query()->count())->toBe(0);
});

it('preserves media overrides when editing normal library settings', function (): void {
    Queue::fake();
    $path = LibraryPath::factory()->create();
    $library = $path->library()->firstOrFail();
    $library->settings()->create(['key' => 'media.video_codec', 'value' => 'av1']);

    Livewire::test(Libraries::class)->call('edit', $library->id)->set('form.name', 'Renamed library')
        ->call('save')->assertHasNoErrors();

    expect($library->refresh()->name)->toBe('Renamed library')
        ->and($library->settings()->pluck('value', 'key')->all())->toEqual(['media.video_codec' => 'av1']);
});

it('prevents saving library settings after logout', function (): void {
    $library = Library::factory()->create();
    $response = actingAs(User::factory()->create())->get(route('settings.libraries.media', $library))->assertOk();
    auth()->logout();
    session()->invalidate();
    Auth::forgetGuards();

    postJson(Livewire::getUpdateUri(), livewireUpdatePayload($response, [], [
        ['method' => 'save', 'params' => []],
    ]), ['X-Livewire' => 'true'])->assertUnauthorized();
    expect($library->settings()->pluck('value', 'key')->all())->toEqual([]);
});
