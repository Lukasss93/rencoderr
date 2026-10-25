<?php

namespace App\Encoding\Enums;

enum HdrMode: string
{
    case PRESERVE = 'preserve';

    case TONE_MAP = 'tone_map';

    case SKIP = 'skip';

    public function label(): string
    {
        return match ($this) {
            self::PRESERVE => 'Preserve HDR',
            self::TONE_MAP => 'Convert to SDR',
            self::SKIP => 'Skip HDR files',
        };
    }
}
