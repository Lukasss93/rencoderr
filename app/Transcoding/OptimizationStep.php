<?php

namespace App\Transcoding;

/** @phpstan-import-type SearchPoint from OptimizationSearch */
class OptimizationStep
{
    /** @param array<int, SearchPoint> $points
     * @return array{quality: ?int, reason: string, quality_projection?: ?float, size_projection?: ?float}
     */
    public function next(OptimizationSearchPolicy $policy, array $points): array
    {
        if ($points === []) {
            return ['quality' => $policy->start->value, 'reason' => 'starting_quality'];
        }
        ksort($points, SORT_NUMERIC);
        $values = array_values($points);
        $feasible = array_values(array_filter($values, $policy->accepts(...)));
        if (! $this->monotonic($values)) {
            $ranked = $feasible ?: $values;
            usort($ranked, fn (array $a, array $b): int => [$a['bytes'], -$a['vmaf']] <=> [$b['bytes'], -$b['vmaf']]);

            return $this->pick($policy, $points, (float) $ranked[0]['quality'], 0, $policy->maximumQuality(), 'nonmonotonic_neighbours');
        }
        $sizeFit = array_values(array_filter($values, fn (array $p): bool => $p['bytes'] <= $policy->maximumBytes));
        $sizeFail = array_values(array_filter($values, fn (array $p): bool => $p['bytes'] > $policy->maximumBytes));
        $qualityPass = array_values(array_filter($values, fn (array $p): bool => $p['vmaf'] >= $policy->minimumVmaf));
        if ($feasible === []) {
            foreach ($sizeFail as $a) {
                foreach ($sizeFit as $b) {
                    if ($b['quality'] === $a['quality'] + 1 && $b['vmaf'] < $policy->minimumVmaf) {
                        return ['quality' => null, 'reason' => 'quality_size_conflict'];
                    }
                }
            }
            if ($qualityPass === [] && isset($points[0])) {
                return ['quality' => null, 'reason' => 'quality_range_exhausted'];
            }
        }
        $qualityRoot = $this->project($values, fn (array $p): float => $p['vmaf'], $policy->minimumVmaf);
        $sizeRoot = $this->project($values, fn (array $p): float => log(max(1, $p['bytes'] - $policy->fixedBytes)),
            log($policy->maximumBytes - $policy->fixedBytes));
        $metadata = ['quality_projection' => $qualityRoot, 'size_projection' => $sizeRoot];
        $probe = $policy->probeStep();
        $cap = $probe * 4;

        if ($feasible !== []) {
            $base = $feasible[array_key_last($feasible)];
            $low = $base['quality'] + 1;
            $high = $policy->maximumQuality();
            foreach ($values as $point) {
                if ($point['quality'] > $base['quality'] && $point['vmaf'] < $policy->minimumVmaf) {
                    $high = min($high, $point['quality'] - 1);
                }
            }
            if ($low > $high) {
                return ['quality' => null, 'reason' => 'boundary_refined', ...$metadata];
            }
            $step = count($points) === 1 && abs($base['vmaf'] - $policy->minimumVmaf) <= 1 ? 1 : $probe * 2 ** min(2, count($points) - 1);
            $target = $qualityRoot ?? $base['quality'] + $step;

            return [...$this->pick($policy, $points, $target, $low, min($high, $base['quality'] + $cap), 'quality_projection'), ...$metadata];
        }

        if ($sizeFit === [] || ($sizeRoot !== null && $qualityRoot !== null && $sizeRoot > $qualityRoot)
            || ($qualityPass !== [] && $sizeFail !== [])) {
            if ($sizeFail !== [] && $sizeFit !== []) {
                $low = $sizeFail[array_key_last($sizeFail)]['quality'] + 1;
                $high = $sizeFit[0]['quality'] - 1;

                return [...$this->pick($policy, $points, $sizeRoot ?? ($low + $high) / 2, $low, $high, 'size_boundary'), ...$metadata];
            }
            $base = $sizeFit === [] ? $values[array_key_last($values)] : $values[0];
            $target = $sizeRoot ?? $base['quality'] + $probe * 2 ** min(2, count($points) - 1);
            $low = $sizeFit === [] ? $base['quality'] + 1 : max(0, $base['quality'] - $cap);

            return [...$this->pick($policy, $points, $target, $low, min($policy->maximumQuality(), $base['quality'] + $cap), 'size_projection'), ...$metadata];
        }

        $base = $values[0];
        $step = count($points) === 1 && abs($base['vmaf'] - $policy->minimumVmaf) <= 1 ? 1 : $probe * 2 ** min(2, count($points) - 1);
        $target = $qualityRoot ?? $base['quality'] - $step;

        return [...$this->pick($policy, $points, $target, max(0, $base['quality'] - $cap), $base['quality'] - 1, 'quality_projection'), ...$metadata];
    }

    /** @param list<SearchPoint> $points */
    private function monotonic(array $points): bool
    {
        for ($i = 1; $i < count($points); $i++) {
            if ($points[$i]['vmaf'] > $points[$i - 1]['vmaf'] + 0.25 || $points[$i]['bytes'] > $points[$i - 1]['bytes'] * 1.05) {
                return false;
            }
        }

        return true;
    }

    /** @param list<SearchPoint> $points
     * @param  \Closure(SearchPoint): float  $value
     */
    private function project(array $points, \Closure $value, float $target): ?float
    {
        usort($points, fn (array $a, array $b): int => abs($value($a) - $target) <=> abs($value($b) - $target));
        $a = $points[0];
        foreach (array_slice($points, 1) as $b) {
            $difference = $value($b) - $value($a);
            if (abs($difference) > 0.000001 && ($b['quality'] - $a['quality']) * $difference < 0) {
                $root = $a['quality'] + ($target - $value($a)) * ($b['quality'] - $a['quality']) / $difference;

                return is_finite($root) ? $root : null;
            }
        }

        return null;
    }

    /** @param array<int, SearchPoint> $points
     * @return array{quality: ?int, reason: string}
     */
    private function pick(OptimizationSearchPolicy $policy, array $points, float $target, int $low, int $high, string $reason): array
    {
        $low = max(0, $low);
        $high = min($policy->maximumQuality(), $high);
        if ($low > $high) {
            return ['quality' => null, 'reason' => 'range_exhausted'];
        }
        $target = max((float) $low, min((float) $high, $target));
        $available = array_values(array_filter(range($low, $high), fn (int $q): bool => ! isset($points[$q])));
        usort($available, fn (int $a, int $b): int => [abs($a - $target), -$a] <=> [abs($b - $target), -$b]);

        return ['quality' => $available[0] ?? null, 'reason' => $available === [] ? 'range_exhausted' : $reason];
    }
}
