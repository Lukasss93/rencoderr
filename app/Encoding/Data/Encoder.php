<?php

namespace App\Encoding\Data;

use App\Encoding\Enums\EncoderType;
use App\Ffmpeg\Enums\HardwareBackend;

/** @phpstan-type EncoderData array{name: string, codec: string, type: string, description: string, backend: ?string} */
readonly class Encoder
{
    public function __construct(
        public string $name,
        public string $codec,
        public EncoderType $type,
        public string $description,
        public ?HardwareBackend $backend = null,
    ) {}

    public function isHardware(): bool
    {
        return $this->backend !== null;
    }

    public function isTestable(): bool
    {
        return $this->backend?->hasTestableEncoders() === true
            && preg_match('/^(h264|hevc|av1)_'.$this->backend->value.'$/D', $this->name) === 1;
    }

    /** @return EncoderData */
    public function toArray(): array
    {
        return [
            'name' => $this->name, 'codec' => $this->codec, 'type' => $this->type->value,
            'description' => $this->description, 'backend' => $this->backend?->value,
        ];
    }

    /** @param EncoderData $data */
    public static function fromArray(array $data): self
    {
        return new self($data['name'], $data['codec'], EncoderType::from($data['type']), $data['description'],
            $data['backend'] === null ? null : HardwareBackend::from($data['backend']));
    }
}
