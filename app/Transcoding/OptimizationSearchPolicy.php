<?php

namespace App\Transcoding;

use InvalidArgumentException;

readonly class OptimizationSearchPolicy
{
    public function __construct(
        public EncoderQuality $start,
        public int $maximumCandidates,
        public float $minimumVmaf,
        public int $maximumBytes,
        public int $fixedBytes = 0,
    ) {
        if ($maximumCandidates < 1 || $maximumCandidates > 32 || ! is_finite($minimumVmaf)
            || $minimumVmaf < 0 || $minimumVmaf > 100 || $fixedBytes < 0 || $maximumBytes <= $fixedBytes) {
            throw new InvalidArgumentException('Invalid adaptive search policy.');
        }
    }

    /** @param array{quality: int, bytes: int, vmaf: float} $point */
    public function accepts(array $point): bool
    {
        return $point['vmaf'] >= $this->minimumVmaf && $point['bytes'] <= $this->maximumBytes;
    }

    public function maximumQuality(): int
    {
        return EncoderQuality::maximum($this->start->encoder);
    }

    public function probeStep(): int
    {
        return max(1, (int) ceil($this->maximumQuality() / 32));
    }
}
