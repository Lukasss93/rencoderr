<?php

namespace App\Transcoding;

use App\Encoding\Data\MediaSettings;
use App\Encoding\Enums\VideoEncoder;
use RuntimeException;

class OptimizationPolicy
{
    /** @return array{strategy: string, starting_quality: array<string, int>, enabled: bool, quality_floor: float, maximum_candidates: int, seconds: int, temporary_bytes: int, video_reserve: float, mux_reserve: float, positions: list<float>, sample_seconds: int} */
    public function snapshot(MediaSettings $settings): array
    {
        $limits = config('transcoding.optimization');
        foreach (['maximum_candidates' => 32, 'seconds' => 86400, 'temporary_bytes' => PHP_INT_MAX] as $key => $maximum) {
            if (! is_int($limits[$key]) || $limits[$key] < 1 || $limits[$key] > $maximum) {
                throw new RuntimeException('Invalid optimization limit: '.$key);
            }
        }

        $starts = $limits['starting_quality'];
        foreach (VideoEncoder::cases() as $encoder) {
            $value = $starts[$encoder->value] ?? null;
            if (! is_int($value) || $value < 0 || $value > EncoderQuality::maximum($encoder)) {
                throw new RuntimeException('Invalid starting quality for '.$encoder->value.'.');
            }
        }

        return ['strategy' => 'adaptive_quality', 'starting_quality' => $starts, 'enabled' => $settings->requireSmallerOutput && $settings->verifyVideoQuality,
            'quality_floor' => min(100.0, $settings->minimumVmaf + 1),
            'maximum_candidates' => $limits['maximum_candidates'], 'seconds' => $limits['seconds'],
            'temporary_bytes' => $limits['temporary_bytes'], 'video_reserve' => 0.10, 'mux_reserve' => 0.01,
            'positions' => [0.1, 0.3, 0.5, 0.7, 0.9], 'sample_seconds' => 15];
    }
}
