<?php

namespace App\QualityControl;

use InvalidArgumentException;

class SampleWindows
{
    /**
     * @param  array<array-key, mixed>  $positions
     * @return list<array{start: float, end: float}>
     */
    public function forDuration(float $duration, string $mode, array $positions, float $seconds): array
    {
        if (! is_finite($duration) || $duration <= 0 || ! in_array($mode, ['sampled', 'full'], true)
            || ! is_finite($seconds) || $seconds <= 0 || $positions === []) {
            throw new InvalidArgumentException('Quality control requires a positive duration, sample length and a supported sampling mode.');
        }
        foreach ($positions as $position) {
            if (! is_numeric($position) || ! is_finite((float) $position) || $position < 0 || $position > 1) {
                throw new InvalidArgumentException('Sample positions must be between zero and one.');
            }
        }
        if ($mode === 'full' || $duration <= count($positions) * $seconds) {
            return [['start' => 0.0, 'end' => $duration]];
        }
        sort($positions, SORT_NUMERIC);
        $windows = [];
        foreach ($positions as $position) {
            $start = max(0.0, min($duration * $position, $duration - $seconds));
            $end = min($duration, $start + $seconds);
            $last = array_key_last($windows);
            if ($last !== null && $start <= $windows[$last]['end']) {
                $windows[$last]['end'] = max($end, $windows[$last]['end']);
            } else {
                $windows[] = ['start' => $start, 'end' => $end];
            }
        }

        return $windows;
    }
}
