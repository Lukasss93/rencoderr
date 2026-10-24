<?php

namespace App\Encoding\Enums;

enum EncodingSpeed: string
{
    case FAST = 'fast';

    case BALANCED = 'balanced';

    case SLOW = 'slow';

    public function label(): string
    {
        return match ($this) {
            self::FAST => 'Fast',
            self::BALANCED => 'Balanced',
            self::SLOW => 'Slow',
        };
    }
}
