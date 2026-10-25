<?php

namespace App\QualityControl;

use App\Encoding\Data\MediaSettings;
use App\Models\MediaTranscode;
use RuntimeException;

/** @phpstan-import-type MediaSettingsData from MediaSettings */
class QualityPolicy
{
    /** @param array<string, mixed> $policy */
    public function settings(array $policy): MediaSettings
    {
        $media = $policy['media'] ?? null;
        if (! is_array($media) || array_diff_key((new MediaSettings)->toArray(), $media) !== []) {
            throw new RuntimeException('The transcode has no complete saved media policy.');
        }
        foreach (['width', 'height'] as $dimension) {
            if (! isset($policy[$dimension]) || ! is_int($policy[$dimension]) || $policy[$dimension] < 2 || $policy[$dimension] % 2 !== 0) {
                throw new RuntimeException('Quality-control dimensions must be positive even integers.');
            }
        }
        $tolerance = $policy['duration_tolerance_seconds'] ?? null;
        if (! is_numeric($tolerance) || ! is_finite((float) $tolerance) || $tolerance <= 0) {
            throw new RuntimeException('The duration tolerance must be positive and finite.');
        }
        /** @var MediaSettingsData $media */
        $settings = MediaSettings::fromArray($media);
        if (! is_finite($settings->minimumVmaf) || $settings->minimumVmaf < 0 || $settings->minimumVmaf > 100
            || $settings->minimumSavingsPercent < 0 || $settings->minimumSavingsPercent > 99) {
            throw new RuntimeException('The saved quality thresholds are invalid.');
        }

        return $settings;
    }

    /** @param array<string, mixed> $policy
     * @param  list<float>  $segments
     */
    public function passes(array $policy, float $mean, array $segments, float $minimum): bool
    {
        return $mean >= $minimum && (($policy['version'] ?? 1) < 2 || ($segments !== [] && min($segments) >= $minimum));
    }

    /** @return array<string, mixed> */
    public function snapshot(MediaTranscode $transcode): array
    {
        $saved = $transcode->optimization['quality_policy'] ?? null;
        if (is_array($saved)) {
            $this->settings($saved);

            return $saved;
        }
        $settings = $transcode->settings()->get()->mapWithKeys(fn ($setting): array => [str($setting->key)->after('media.')->toString() => $setting->value])->all();

        return ['version' => 2, 'threads' => (int) config('quality_control.threads'), 'execution_limit_seconds' => (int) config('quality_control.timeout_seconds'), 'mode' => config('quality_control.mode'), 'positions' => config('quality_control.sample_positions'),
            'sample_seconds' => config('quality_control.sample_seconds'), 'model_path' => config('quality_control.model_path'),
            'width' => config('quality_control.width'), 'height' => config('quality_control.height'),
            'duration_tolerance_seconds' => config('quality_control.duration_tolerance_seconds'), 'media' => $settings];
    }

    /** @return array<string, mixed> */
    public function forEncoding(MediaTranscode $transcode): array
    {
        $policy = $this->snapshot($transcode);
        if ($this->settings($policy)->verifyVideoQuality) {
            $path = $policy['model_path'];
            if (! is_string($path) || ! is_readable($path) || ($hash = hash_file('sha256', $path)) === false) {
                throw new RuntimeException('The configured VMAF model is unavailable before encoding.');
            }
            $policy['model_sha256'] = $hash;
        }

        return $policy;
    }
}
