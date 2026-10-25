<?php

namespace App\Encoding\Enums;

enum EncodingSpeed: string
{
    case FAST = 'fast';

    case BALANCED = 'balanced';

    case SLOW = 'slow';

    /** @return list<string> */
    public function arguments(VideoEncoder $encoder): array
    {
        $level = match ($this) {
            self::FAST => 0, self::BALANCED => 1, self::SLOW => 2
        };

        return match ($encoder) {
            VideoEncoder::LIBX265, VideoEncoder::HEVC_QSV, VideoEncoder::AV1_QSV => ['-preset', ['fast', 'medium', 'slow'][$level]],
            VideoEncoder::LIBSVTAV1 => ['-preset', (string) [8, 6, 4][$level]],
            VideoEncoder::HEVC_NVENC, VideoEncoder::AV1_NVENC => ['-preset', ['p3', 'p5', 'p7'][$level]],
            VideoEncoder::HEVC_VAAPI, VideoEncoder::AV1_VAAPI => ['-compression_level', (string) [7, 4, 1][$level]],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::FAST => 'Fast',
            self::BALANCED => 'Balanced',
            self::SLOW => 'Slow',
        };
    }
}
