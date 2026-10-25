<?php

namespace App\Transcoding;

use Closure;
use RuntimeException;

/** @phpstan-type SearchPoint array{quality: int, bytes: int, vmaf: float} */
class OptimizationSearch
{
    public function __construct(private OptimizationStep $steps = new OptimizationStep) {}

    /** @param Closure(int): SearchPoint $measure
     * @param  Closure(SearchPoint): SearchPoint  $confirm
     * @param  (Closure(string): void)|null  $finished
     * @param  (Closure(array<string, mixed>): void)|null  $trace
     *
     * @param-immediately-invoked-callable $measure
     * @param-immediately-invoked-callable $confirm
     * @param-immediately-invoked-callable $finished
     * @param-immediately-invoked-callable $trace
     *
     * @return SearchPoint|null
     */
    public function run(OptimizationSearchPolicy $policy, Closure $measure, Closure $confirm, ?Closure $finished = null, ?Closure $trace = null): ?array
    {
        $points = [];
        $training = [];
        $checked = [];
        $best = null;
        $validating = false;
        $limited = false;
        $reason = 'candidate_limit';
        $explorationLimit = max(1, $policy->maximumCandidates - 2);
        while (true) {
            if (! $validating && count($points) >= $explorationLimit) {
                $validating = true;
            }
            if ($validating) {
                $pending = array_values(array_filter($points, fn (array $p): bool => $policy->accepts($p)
                    && ! isset($checked[$p['quality']]) && ($best === null || $p['bytes'] <= $best['bytes'])));
                usort($pending, fn (array $a, array $b): int => [$a['bytes'], -$a['vmaf']] <=> [$b['bytes'], -$b['vmaf']]);
                if ($pending !== []) {
                    $point = $pending[0];
                    try {
                        $confirmed = $this->validate($confirm($point), $point['quality']);
                    } catch (OptimizationLimit $limit) {
                        $trace?->__invoke(['phase' => 'limit', 'message' => $limit->getMessage()]);
                        $reason = 'preparation_limit';
                        break;
                    }
                    $point = ['quality' => $point['quality'], 'bytes' => max($point['bytes'], $confirmed['bytes']),
                        'vmaf' => min($point['vmaf'], $confirmed['vmaf'])];
                    $points[$point['quality']] = $point;
                    $checked[$point['quality']] = true;
                    if ($policy->accepts($point) && ($best === null || [$point['bytes'], -$point['vmaf']] < [$best['bytes'], -$best['vmaf']])) {
                        $best = $point;
                    }
                    $trace?->__invoke(['phase' => 'measured', 'closest' => $this->closest($policy, $points, $checked)]);

                    continue;
                }
                if ($limited) {
                    $reason = 'preparation_limit';
                    break;
                }
            }
            $next = $this->steps->next($policy, $this->predictions($training, $points, $checked));
            if ($next['quality'] === null) {
                if (! $validating) {
                    $validating = true;

                    continue;
                }
                $reason = $next['reason'];
                break;
            }
            if (count($points) >= $policy->maximumCandidates) {
                $reason = 'candidate_limit';
                break;
            }
            $trace?->__invoke(['phase' => 'search', ...$next]);
            try {
                $point = $this->validate($measure($next['quality']), $next['quality']);
            } catch (OptimizationLimit $limit) {
                $trace?->__invoke(['phase' => 'limit', 'message' => $limit->getMessage()]);
                $limited = true;
                $validating = true;

                continue;
            }
            $points[$point['quality']] = $point;
            $training[$point['quality']] = $point;
            $trace?->__invoke(['phase' => 'measured', 'closest' => $this->closest($policy, $points, $checked)]);
        }
        $finished?->__invoke($reason);

        return $best;
    }

    /** @param array<int, SearchPoint> $training
     * @param  array<int, SearchPoint>  $points
     * @param  array<int, bool>  $checked
     * @return array<int, SearchPoint>
     */
    private function predictions(array $training, array $points, array $checked): array
    {
        $penalty = 0.0;
        foreach ($checked as $quality => $ignored) {
            $penalty = max($penalty, $training[$quality]['vmaf'] - $points[$quality]['vmaf']);
        }

        // Holdout difficulty adjusts predictions uniformly; it never becomes a measured pass.
        return array_map(function (array $point) use ($points, $checked, $penalty): array {
            if (isset($checked[$point['quality']])) {
                return $points[$point['quality']];
            }
            $confirmed = array_values(array_filter($points, fn (array $p): bool => isset($checked[$p['quality']])));
            usort($confirmed, fn (array $a, array $b): int => abs($a['quality'] - $point['quality']) <=> abs($b['quality'] - $point['quality']));
            if (count($confirmed) >= 2) {
                [$a, $b] = $confirmed;
                $slope = ($b['vmaf'] - $a['vmaf']) / ($b['quality'] - $a['quality']);
                if ($slope < 0) {
                    return [...$point, 'vmaf' => min($point['vmaf'], $a['vmaf'] + $slope * ($point['quality'] - $a['quality']))];
                }
            }

            return [...$point, 'vmaf' => $point['vmaf'] - $penalty];
        }, $training);
    }

    /** @param SearchPoint $point
     * @return SearchPoint
     */
    private function validate(array $point, int $quality): array
    {
        if ($point['quality'] !== $quality || $point['bytes'] < 1 || ! is_finite($point['vmaf'])) {
            throw new RuntimeException('An optimization measurement is incomplete or does not match the requested quality.');
        }

        return ['quality' => $quality, 'bytes' => $point['bytes'], 'vmaf' => (float) $point['vmaf']];
    }

    /** @param array<int, SearchPoint> $points
     * @param  array<int, bool>  $checked
     * @return array{quality: int, bytes: int, vmaf: float, within_size_budget: bool, quality_gap: float, confirmed: bool}|null
     */
    private function closest(OptimizationSearchPolicy $policy, array $points, array $checked): ?array
    {
        $fits = array_values(array_filter($points, fn (array $p): bool => $p['bytes'] <= $policy->maximumBytes));
        $ranked = $fits ?: array_values($points);
        if ($ranked === []) {
            return null;
        }
        usort($ranked, fn (array $a, array $b): int => $fits !== []
            ? [-$a['vmaf'], $a['bytes']] <=> [-$b['vmaf'], $b['bytes']]
            : [$a['bytes'], -$a['vmaf']] <=> [$b['bytes'], -$b['vmaf']]);
        $point = $ranked[0];

        return [...$point, 'within_size_budget' => $point['bytes'] <= $policy->maximumBytes,
            'quality_gap' => max(0.0, $policy->minimumVmaf - $point['vmaf']),
            'confirmed' => isset($checked[$point['quality']]) && $policy->accepts($point)];
    }
}
