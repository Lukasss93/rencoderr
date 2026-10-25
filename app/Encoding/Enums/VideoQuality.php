<?php

namespace App\Encoding\Enums;

enum VideoQuality: string
{
    case HIGH = 'high';

    case BALANCED = 'balanced';

    case SMALLER = 'smaller';

    /** @return list<string> */
    public function arguments(VideoEncoder $encoder): array
    {
        $level = match ($this) {
            self::HIGH => 0, self::BALANCED => 1, self::SMALLER => 2
        };
        $quality = (string) [20, 23, 26][$level];

        return match ($encoder) {
            VideoEncoder::LIBX265 => ['-crf', $quality],
            VideoEncoder::LIBSVTAV1 => ['-crf', (string) [24, 28, 32][$level]],
            VideoEncoder::HEVC_NVENC, VideoEncoder::AV1_NVENC => ['-rc', 'vbr', '-b:v', '0', '-cq', $quality],
            VideoEncoder::HEVC_QSV, VideoEncoder::AV1_QSV => ['-global_quality', $quality],
            VideoEncoder::HEVC_VAAPI => ['-rc_mode', 'CQP', '-global_quality', $quality],
            VideoEncoder::AV1_VAAPI => ['-rc_mode', 'CQP', '-global_quality', (string) [80, 96, 112][$level]],
        };
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
