<?php

namespace App\Encoding\Enums;

enum MediaPreset: string
{
    case HEVC_HIGH_QUALITY = 'hevc_high_quality';

    case AV1_HIGH_QUALITY = 'av1_high_quality';

    public function label(): string
    {
        return match ($this) {
            self::HEVC_HIGH_QUALITY => 'H.265 — High quality',
            self::AV1_HIGH_QUALITY => 'AV1 — High quality',
        };
    }

    /** @return array{video_codec: string, video_quality: string, encoding_speed: string} */
    public function values(): array
    {
        return [
            'video_codec' => match ($this) {
                self::HEVC_HIGH_QUALITY => TargetVideoCodec::HEVC->value,
                self::AV1_HIGH_QUALITY => TargetVideoCodec::AV1->value,
            },
            'video_quality' => VideoQuality::HIGH->value,
            'encoding_speed' => EncodingSpeed::BALANCED->value,
        ];
    }
}
