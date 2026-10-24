<?php

namespace App\Encoding\Enums;

use App\Ffmpeg\Enums\HardwareBackend;

enum VideoEncoder: string
{
    case LIBX265 = 'libx265';
    case LIBSVTAV1 = 'libsvtav1';
    case HEVC_NVENC = 'hevc_nvenc';
    case AV1_NVENC = 'av1_nvenc';
    case HEVC_QSV = 'hevc_qsv';
    case AV1_QSV = 'av1_qsv';
    case HEVC_VAAPI = 'hevc_vaapi';
    case AV1_VAAPI = 'av1_vaapi';

    public function targetCodec(): TargetVideoCodec
    {
        return match ($this) {
            self::LIBX265, self::HEVC_NVENC, self::HEVC_QSV, self::HEVC_VAAPI => TargetVideoCodec::HEVC,
            default => TargetVideoCodec::AV1,
        };
    }

    public function backend(): ?HardwareBackend
    {
        return match ($this) {
            self::HEVC_NVENC, self::AV1_NVENC => HardwareBackend::NVENC,
            self::HEVC_QSV, self::AV1_QSV => HardwareBackend::QSV,
            self::HEVC_VAAPI, self::AV1_VAAPI => HardwareBackend::VAAPI,
            default => null,
        };
    }
}
