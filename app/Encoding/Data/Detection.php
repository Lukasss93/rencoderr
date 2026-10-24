<?php

namespace App\Encoding\Data;

use App\Encoding\Enums\EncoderType;
use Carbon\CarbonImmutable;

/**
 * @phpstan-import-type EncoderData from Encoder
 * @phpstan-import-type BackendData from Backend
 * @phpstan-import-type EncoderCheckData from EncoderCheck
 *
 * @phpstan-type DetectionData array{detected_at: string, version: ?string, error: ?string, encoders: list<EncoderData>, backends: array<string, BackendData>, acceleration_error: ?string, checks: array<string, EncoderCheckData>}
 */
readonly class Detection
{
    /**
     * @param  list<Encoder>  $encoders
     * @param  array<string, Backend>  $backends
     * @param  array<string, EncoderCheck>  $checks
     */
    public function __construct(
        public CarbonImmutable $detectedAt,
        public ?string $version = null,
        public ?string $error = null,
        public array $encoders = [],
        public array $backends = [],
        public ?string $accelerationError = null,
        public array $checks = [],
    ) {}

    public function encoder(string $name): ?Encoder
    {
        return array_find($this->encoders, fn (Encoder $encoder): bool => $encoder->name === $name);
    }

    public function isBackendAvailable(string $name): bool
    {
        return $this->backends[$name]->available ?? false;
    }

    public function isAvailable(Encoder $encoder): bool
    {
        return ($encoder->backend === null || $this->isBackendAvailable($encoder->backend->value))
            && ($this->checks[$encoder->name]->successful ?? true);
    }

    public function availableCount(EncoderType $type): int
    {
        return count(array_filter($this->encoders, fn (Encoder $encoder): bool => $encoder->type === $type && $this->isAvailable($encoder)));
    }

    public function withCheck(Encoder $encoder, EncoderCheck $check): self
    {
        $backends = $this->backends;
        $backend = $encoder->backend?->value;

        if ($check->successful && $backend !== null && isset($backends[$backend])) {
            $backends[$backend] = $backends[$backend]->markAvailable('Encoded a generated frame with '.$encoder->name.'.');
        }

        return new self($this->detectedAt, $this->version, $this->error, $this->encoders, $backends,
            $this->accelerationError, [...$this->checks, $encoder->name => $check]);
    }

    /** @return array<string, array<string, array<string, list<Encoder>>>> */
    public function encodersByCategory(): array
    {
        $groups = [];

        foreach (EncoderType::cases() as $type) {
            $groups[$type->label()] = ['Hardware' => [], 'Software' => [], 'Supported' => []];
        }

        foreach ($this->encoders as $encoder) {
            $category = match (true) {
                ! $encoder->isHardware() => 'Software',
                $this->isAvailable($encoder) => 'Hardware',
                default => 'Supported',
            };
            $groups[$encoder->type->label()][$category][$encoder->codec][] = $encoder;
        }

        return $groups;
    }

    /** @return DetectionData */
    public function toArray(): array
    {
        return [
            'detected_at' => $this->detectedAt->toIso8601String(),
            'version' => $this->version,
            'error' => $this->error,
            'encoders' => array_map(fn (Encoder $encoder): array => $encoder->toArray(), $this->encoders),
            'backends' => array_map(fn (Backend $backend): array => $backend->toArray(), $this->backends),
            'acceleration_error' => $this->accelerationError,
            'checks' => array_map(fn (EncoderCheck $check): array => $check->toArray(), $this->checks),
        ];
    }

    /** @param DetectionData $data */
    public static function fromArray(array $data): self
    {
        return new self(
            CarbonImmutable::parse($data['detected_at']),
            $data['version'],
            $data['error'],
            array_map(Encoder::fromArray(...), $data['encoders']),
            array_map(Backend::fromArray(...), $data['backends']),
            $data['acceleration_error'],
            array_map(EncoderCheck::fromArray(...), $data['checks']),
        );
    }
}
