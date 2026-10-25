<?php

namespace App\Encoding\Enums;

enum AudioCodec: string
{
    case COPY = 'copy';

    case AAC = 'aac';

    case OPUS = 'opus';

    public function encoder(): string
    {
        return $this === self::OPUS ? 'libopus' : $this->value;
    }

    public function label(): string
    {
        return match ($this) {
            self::COPY => 'Copy original',
            self::AAC => 'AAC',
            self::OPUS => 'Opus',
        };
    }
}
