<?php

namespace App\Encoding\Data;

/** @phpstan-type EncoderCheckData array{successful: bool, message: string} */
readonly class EncoderCheck
{
    public function __construct(public bool $successful, public string $message) {}

    /** @return EncoderCheckData */
    public function toArray(): array
    {
        return ['successful' => $this->successful, 'message' => $this->message];
    }

    /** @param EncoderCheckData $data */
    public static function fromArray(array $data): self
    {
        return new self(...$data);
    }
}
