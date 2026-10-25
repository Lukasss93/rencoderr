<?php

namespace App\Encoding\Enums;

use App\Transcoding\EncoderQuality;

enum VideoQuality: string
{
    case HIGH = 'high';

    case BALANCED = 'balanced';

    case SMALLER = 'smaller';

    /** @return list<string> */
    public function arguments(VideoEncoder $encoder): array
    {
        return EncoderQuality::starting($encoder, $this)->arguments();
    }

    public function label(): string
    {
        return match ($this) {
            self::HIGH => 'High',
            self::BALANCED => 'Balanced',
            self::SMALLER => 'Smaller files',
        };
    }
}
