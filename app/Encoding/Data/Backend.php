<?php

namespace App\Encoding\Data;

/**
 * The name can be one HardwareBackend does not know yet.
 *
 * @phpstan-type BackendData array{name: string, label: string, encoders: int, available: bool, message: string}
 */
readonly class Backend
{
    public function __construct(
        public string $name,
        public string $label,
        public int $encoders,
        public bool $available,
        public string $message,
    ) {}

    public function markAvailable(string $message): self
    {
        return new self($this->name, $this->label, $this->encoders, true, $message);
    }

    /** @return BackendData */
    public function toArray(): array
    {
        return ['name' => $this->name, 'label' => $this->label, 'encoders' => $this->encoders, 'available' => $this->available, 'message' => $this->message];
    }

    /** @param BackendData $data */
    public static function fromArray(array $data): self
    {
        return new self(...$data);
    }
}
