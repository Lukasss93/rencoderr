<?php

namespace App\Encoding\Enums;

enum VideoQuality: string
{
    case HIGH = 'high';

    case BALANCED = 'balanced';

    case SMALLER = 'smaller';

    public function label(): string
    {
        return match ($this) {
            self::HIGH => 'High',
            self::BALANCED => 'Balanced',
            self::SMALLER => 'Smaller files',
        };
    }
}
