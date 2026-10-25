<?php

namespace App\Transcoding;

use RuntimeException;

/** @phpstan-type SampleMeasurement array{video_bytes: int, minimum_vmaf: float, windows: list<array<string, mixed>>, dispersion: float, encode_seconds: float} */
class OptimizationMeasurements
{
    public bool $confirming = false;

    public ?string $termination = null;

    /** @var array<int, SampleMeasurement> */
    private array $samples = [];

    /** @param SampleMeasurement $measurement */
    public function record(int $quality, array $measurement): void
    {
        $this->samples[$quality] = $measurement;
    }

    /** @return SampleMeasurement */
    public function forQuality(int $quality): array
    {
        return $this->samples[$quality] ?? throw new RuntimeException('Confirmation has no matching sample measurements.');
    }
}
