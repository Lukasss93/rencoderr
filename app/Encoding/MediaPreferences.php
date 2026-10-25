<?php

namespace App\Encoding;

use App\Encoding\Data\MediaSettings;
use App\Models\Library;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/** @phpstan-import-type MediaSettingsData from MediaSettings */
class MediaPreferences
{
    private const string PREFIX = 'media.';

    public function globalSettings(): MediaSettings
    {
        $values = (new MediaSettings)->toArray();
        $keys = array_map(fn (string $field): string => self::PREFIX.$field, array_keys($values));

        foreach (Setting::query()->global()->whereIn('key', $keys)->get() as $setting) {
            $values[substr($setting->key, strlen(self::PREFIX))] = $setting->value;
        }

        /** @var MediaSettingsData $values */
        return MediaSettings::fromArray($values);
    }

    public function forLibrary(Library $library): MediaSettings
    {
        /** @var MediaSettingsData $values */
        $values = array_replace($this->globalSettings()->toArray(), $this->overrides($library));

        return MediaSettings::fromArray($values);
    }

    public function saveGlobal(MediaSettings $settings): void
    {
        DB::transaction(function () use ($settings): void {
            foreach ($settings->toArray() as $field => $value) {
                Setting::put(self::PREFIX.$field, $value);
            }
        });
    }

    /** @return array<string, mixed> */
    public function overrides(Library $library): array
    {
        $library->loadMissing('settings');
        $values = [];
        $defaults = (new MediaSettings)->toArray();

        foreach ($library->settings as $setting) {
            if (str_starts_with($setting->key, self::PREFIX)) {
                $field = substr($setting->key, strlen(self::PREFIX));

                if (array_key_exists($field, $defaults)) {
                    $values[$field] = $setting->value;
                }
            }
        }

        return $values;
    }

    /** @param list<string> $overriddenFields */
    public function saveLibrary(Library $library, MediaSettings $settings, array $overriddenFields): void
    {
        $overrides = array_intersect_key($settings->toArray(), array_flip($overriddenFields));
        $keys = array_map(fn (string $field): string => self::PREFIX.$field, array_keys($settings->toArray()));
        $rows = [];

        foreach ($overrides as $field => $value) {
            $rows[] = (new Setting(['key' => self::PREFIX.$field, 'value' => $value]))->getAttributes();
        }

        DB::transaction(function () use ($library, $keys, $rows): void {
            $library->settings()->whereIn('key', $keys)->whereNotIn('key', array_column($rows, 'key'))->delete();
            $library->settings()->upsert($rows, ['settingable_type', 'settingable_id', 'key'], ['value']);
        });

        $library->unsetRelation('settings');
    }
}
