<?php

namespace App\Transcoding;

use Closure;
use RuntimeException;

/** @phpstan-type SearchPoint array{quality: int, bytes: int, vmaf: float, encode_seconds: float} */
class OptimizationSearch
{
    public function __construct(private OptimizationStep $steps = new OptimizationStep) {}

    /** @param Closure(int): SearchPoint $measure
     * @param  (Closure(array<string, mixed>): void)|null  $trace
     *
     * @param-immediately-invoked-callable $measure
     * @param-immediately-invoked-callable $trace
     *
     * @return array{winner: SearchPoint|null, termination: string}
     */
    public function run(OptimizationSearchPolicy $policy, Closure $measure, ?Closure $trace = null): array
    {
        $points = [];
        $best = null;
        while (true) {
            $next = $this->steps->next($policy, $points);
            if ($next['quality'] === null) {
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
                $reason = 'preparation_limit';
                break;
            }
            $points[$point['quality']] = $point;
            if ($policy->accepts($point) && ($best === null || [$point['bytes'], -$point['vmaf'], $point['encode_seconds']] < [$best['bytes'], -$best['vmaf'], $best['encode_seconds']])) {
                $best = $point;
            }
            $trace?->__invoke(['phase' => 'measured', 'closest' => $this->closest($policy, $points), 'winner' => $best]);
        }

        return ['winner' => $best, 'termination' => $reason];
    }

    /** @param SearchPoint $point
     * @return SearchPoint
     */
    private function validate(array $point, int $quality): array
    {
        if ($point['quality'] !== $quality || $point['bytes'] < 1 || ! is_finite($point['vmaf'])) {
            throw new RuntimeException('An optimization measurement is incomplete or does not match the requested quality.');
        }

        return ['quality' => $quality, 'bytes' => $point['bytes'], 'vmaf' => (float) $point['vmaf'], 'encode_seconds' => $point['encode_seconds']];
    }

    /** @param non-empty-array<int, SearchPoint> $points
     * @return array{quality: int, bytes: int, vmaf: float, encode_seconds: float, within_size_budget: bool, quality_gap: float}
     */
    private function closest(OptimizationSearchPolicy $policy, array $points): array
    {
        $fits = array_values(array_filter($points, fn (array $p): bool => $p['bytes'] <= $policy->maximumBytes));
        $ranked = $fits ?: array_values($points);
        usort($ranked, fn (array $a, array $b): int => $fits !== []
            ? [-$a['vmaf'], $a['bytes']] <=> [-$b['vmaf'], $b['bytes']]
            : [$a['bytes'], -$a['vmaf']] <=> [$b['bytes'], -$b['vmaf']]);
        $point = $ranked[0];

        return [...$point, 'within_size_budget' => $point['bytes'] <= $policy->maximumBytes,
            'quality_gap' => max(0.0, $policy->minimumVmaf - $point['vmaf'])];
    }
}
