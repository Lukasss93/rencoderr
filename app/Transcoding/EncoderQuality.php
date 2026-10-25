<?php

namespace App\Transcoding;

use App\Encoding\Enums\VideoEncoder;
use App\Encoding\Enums\VideoQuality;
use InvalidArgumentException;

readonly class EncoderQuality
{
    public function __construct(public VideoEncoder $encoder, public int $value)
    {
        if ($value < 0 || $value > self::maximum($encoder)) {
            throw new InvalidArgumentException('Quality is outside the encoder native range.');
        }
    }

    public static function maximum(VideoEncoder $encoder): int
    {
        return match ($encoder) {
            VideoEncoder::AV1_VAAPI => 255,
            VideoEncoder::LIBSVTAV1 => 63,
            default => 51,
        };
    }

    public static function starting(VideoEncoder $encoder, VideoQuality $hint): self
    {
        $level = match ($hint) {
            VideoQuality::HIGH => 0, VideoQuality::BALANCED => 1, VideoQuality::SMALLER => 2
        };

        return new self($encoder, match ($encoder) {
            VideoEncoder::AV1_VAAPI => [80, 96, 112][$level],
            VideoEncoder::LIBSVTAV1 => [24, 28, 32][$level],
            default => [20, 23, 26][$level],
        });
    }

    /** @return list<string> */
    public function arguments(): array
    {
        $quality = (string) $this->value;

        return match ($this->encoder) {
            VideoEncoder::LIBX265, VideoEncoder::LIBSVTAV1 => ['-crf', $quality],
            VideoEncoder::HEVC_NVENC, VideoEncoder::AV1_NVENC => ['-rc', 'vbr', '-b:v', '0', '-cq', $quality],
            VideoEncoder::HEVC_QSV, VideoEncoder::AV1_QSV => ['-global_quality', $quality],
            VideoEncoder::HEVC_VAAPI, VideoEncoder::AV1_VAAPI => ['-rc_mode', 'CQP', '-global_quality', $quality],
        };
    }
}
