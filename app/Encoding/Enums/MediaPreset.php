<?php

namespace App\Encoding\Enums;

enum MediaPreset: string
{
    case HEVC_SAVE_STORAGE = 'hevc_save_storage';

    case AV1_SAVE_STORAGE = 'av1_save_storage';

    public function label(): string
    {
        return match ($this) {
            self::HEVC_SAVE_STORAGE => 'H.265 — Save storage',
            self::AV1_SAVE_STORAGE => 'AV1 — Save storage',
        };
    }

    /** @return array{video_codec: string, video_quality: string, encoding_speed: string} */
    public function values(): array
    {
        return [
            'video_codec' => match ($this) {
                self::HEVC_SAVE_STORAGE => TargetVideoCodec::HEVC->value,
                self::AV1_SAVE_STORAGE => TargetVideoCodec::AV1->value,
            },
            'video_quality' => VideoQuality::SMALLER->value,
            'encoding_speed' => EncodingSpeed::SLOW->value,
        ];
    }
}
