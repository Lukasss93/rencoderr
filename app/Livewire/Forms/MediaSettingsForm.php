<?php

namespace App\Livewire\Forms;

use App\Encoding\Data\MediaSettings;
use App\Encoding\Enums\AudioChannels;
use App\Encoding\Enums\AudioCodec;
use App\Encoding\Enums\EncodingSpeed;
use App\Encoding\Enums\HdrMode;
use App\Encoding\Enums\MediaPreset;
use App\Encoding\Enums\TargetVideoCodec;
use App\Encoding\Enums\VideoQuality;
use App\Encoding\MediaPreferences;
use App\Models\Library;
use Illuminate\Validation\Rule;
use Livewire\Form;

/** @phpstan-import-type MediaSettingsData from MediaSettings */
class MediaSettingsForm extends Form
{
    /** @var array<string, mixed> */
    public array $values = [];

    /** @var array<string, bool> */
    public array $overridden = [];

    public string $selectedPreset = '';

    /** @param list<string>|null $overriddenFields */
    public function fillFrom(MediaSettings $settings, ?array $overriddenFields = null): void
    {
        $this->values = $settings->toArray();
        $this->overridden = $overriddenFields === null ? [] : array_fill_keys(array_keys($this->values), false);

        foreach ($overriddenFields ?? [] as $field) {
            $this->overridden[$field] = true;
        }

        $this->selectedPreset = '';
        $this->resetValidation();
    }

    public function isInherited(string $field): bool
    {
        return ($this->overridden[$field] ?? true) === false;
    }

    public function applyPreset(bool $libraryMode): void
    {
        $this->validate(['selectedPreset' => ['required', Rule::enum(MediaPreset::class)]]);

        foreach (MediaPreset::from($this->selectedPreset)->values() as $field => $value) {
            $this->values[$field] = $value;

            if ($libraryMode) {
                $this->overridden[$field] = true;
            }

            $this->resetValidation('values.'.$field);
        }
    }

    public function setOverride(string $field, bool $enabled, MediaSettings $globalSettings): void
    {
        $globals = $globalSettings->toArray();

        if (! array_key_exists($field, $globals)) {
            $this->addError('overridden', 'Choose a valid media setting.');

            return;
        }

        $this->overridden[$field] = $enabled;
        $this->values[$field] = $globals[$field];
        $this->resetValidation('values.'.$field);
        $this->resetValidation('overridden');
    }

    public function inheritAll(MediaSettings $globalSettings): void
    {
        $this->fillFrom($globalSettings, []);
    }

    public function saveGlobal(MediaPreferences $preferences): void
    {
        $preferences->saveGlobal($this->validatedSettings(false));
        $this->fillFrom($preferences->globalSettings());
    }

    public function saveLibrary(Library $library, MediaPreferences $preferences): void
    {
        $settings = $this->validatedSettings(true);
        $fields = array_keys(array_filter($this->overridden));
        $preferences->saveLibrary($library, $settings, $fields);
        $this->fillFrom($preferences->forLibrary($library), array_keys($preferences->overrides($library)));
    }

    private function validatedSettings(bool $libraryMode): MediaSettings
    {
        foreach (['audio_languages', 'subtitle_languages'] as $field) {
            if (is_array($this->values[$field] ?? null)) {
                $this->values[$field] = array_map(
                    fn (mixed $value): mixed => is_string($value) ? strtolower(trim($value)) : $value,
                    $this->values[$field],
                );
            }
        }

        $defaults = (new MediaSettings)->toArray();
        $keys = implode(',', array_keys($defaults));
        $rules = [
            'values' => ['required', 'array:'.$keys, 'required_array_keys:'.$keys],
            'values.video_codec' => ['required', Rule::enum(TargetVideoCodec::class)],
            'values.video_quality' => ['required', Rule::enum(VideoQuality::class)],
            'values.encoding_speed' => ['required', Rule::enum(EncodingSpeed::class)],
            'values.hdr_mode' => ['required', Rule::enum(HdrMode::class)],
            'values.audio_codec' => ['required', Rule::enum(AudioCodec::class)],
            'values.audio_channels' => ['required', Rule::enum(AudioChannels::class)],
            'values.audio_bitrate_per_channel' => ['required', 'integer', 'between:32,256'],
            'values.minimum_savings_percent' => ['required', 'integer', 'between:0,99'],
            'values.minimum_vmaf' => ['required', 'numeric', 'between:0,100'],
            'values.audio_languages' => ['present', 'array', 'list', 'max:100'],
            'values.audio_languages.*' => ['required', 'string', 'regex:/\A[a-z]{3}\z/'],
            'values.subtitle_languages' => ['present', 'array', 'list', 'max:100'],
            'values.subtitle_languages.*' => ['required', 'string', 'regex:/\A[a-z]{3}\z/'],
            'values.skip_video_codecs' => ['present', 'array', 'list', 'max:100'],
            'values.skip_video_codecs.*' => ['required', 'string', Rule::in(['h264', 'hevc', 'av1'])],
        ];

        foreach ($defaults as $field => $default) {
            if (is_bool($default)) {
                $rules['values.'.$field] = ['required', 'boolean'];
            }
        }

        if ($libraryMode) {
            $rules['overridden'] = ['required', 'array:'.$keys, 'required_array_keys:'.$keys];
            $rules['overridden.*'] = ['required', 'boolean'];
        }

        $attributes = [];

        foreach (array_keys($defaults) as $field) {
            $attributes['values.'.$field] = str_replace('_', ' ', $field);
        }

        $validated = $this->validate($rules, attributes: $attributes);
        $values = $validated['values'];

        foreach ($defaults as $field => $default) {
            $values[$field] = match (true) {
                is_bool($default) => (bool) $values[$field],
                is_int($default) => (int) $values[$field],
                is_float($default) => (float) $values[$field],
                is_array($default) => array_values(array_unique($values[$field])),
                default => $values[$field],
            };
        }

        /** @var MediaSettingsData $values */
        return MediaSettings::fromArray($values);
    }
}
