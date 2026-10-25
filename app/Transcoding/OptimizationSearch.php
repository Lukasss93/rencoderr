<?php

namespace App\Transcoding;

use Closure;
use InvalidArgumentException;

class OptimizationSearch
{
    /**
     * @param  Closure(int): array{quality: int, bytes: int, passes: bool}  $measure
     * @param  (Closure(string): void)|null  $finished
     * @return array{quality: int, bytes: int, passes: bool}|null
     */
    public function run(int $start, int $minimum, int $maximum, int $limit, Closure $measure, ?Closure $finished = null): ?array
    {
        if ($minimum > $start || $start > $maximum || $limit < 1 || $limit > 32) {
            throw new InvalidArgumentException('Invalid optimization search bounds.');
        }
        $best = null;
        $quality = $start;
        for ($attempt = 0; $attempt < $limit && $minimum <= $maximum; $attempt++) {
            $result = $measure($quality);
            if ($result['passes']) {
                if ($best === null || $result['bytes'] < $best['bytes']) {
                    $best = $result;
                }
                $minimum = $quality + 1;
            } else {
                $maximum = $quality - 1;
            }
            $quality = intdiv($minimum + $maximum, 2);
        }

        $finished?->__invoke($minimum <= $maximum ? 'candidate_limit' : 'boundary_explored');

        return $best;
    }
}
