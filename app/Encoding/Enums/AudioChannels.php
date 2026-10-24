<?php

namespace App\Encoding\Enums;

enum AudioChannels: string
{
    case PRESERVE = 'preserve';

    case STEREO = 'stereo';

    public function label(): string
    {
        return match ($this) {
            self::PRESERVE => 'Preserve',
            self::STEREO => 'Stereo',
        };
    }
}
