<?php

namespace App\Encoding\Enums;

enum TargetVideoCodec: string
{
    case HEVC = 'hevc';

    case AV1 = 'av1';

    public function softwareEncoder(): VideoEncoder
    {
        return match ($this) {
            self::HEVC => VideoEncoder::LIBX265,
            self::AV1 => VideoEncoder::LIBSVTAV1,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::HEVC => 'H.265 (HEVC)',
            self::AV1 => 'AV1',
        };
    }
}
