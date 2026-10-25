<?php

namespace App\Ffmpeg\Enums;

enum HardwareBackend: string
{
    case NVENC = 'nvenc';
    case CUDA = 'cuda';
    case QSV = 'qsv';
    case VAAPI = 'vaapi';
    case AMF = 'amf';
    case VIDEOTOOLBOX = 'videotoolbox';
    case VDPAU = 'vdpau';
    case VULKAN = 'vulkan';
    case OPENCL = 'opencl';
    case DRM = 'drm';
    case DXVA2 = 'dxva2';
    case D3D11VA = 'd3d11va';
    case D3D12VA = 'd3d12va';
    case V4L2M2M = 'v4l2m2m';
    case OMX = 'omx';
    case MEDIACODEC = 'mediacodec';
    case MF = 'mf';

    public static function labelFor(string $name): string
    {
        return self::tryFrom($name)?->label() ?? strtoupper($name);
    }

    public function label(): string
    {
        return match ($this) {
            self::NVENC => 'NVIDIA NVENC',
            self::CUDA => 'NVIDIA CUDA',
            self::QSV => 'Intel Quick Sync (QSV)',
            self::VAAPI => 'VAAPI',
            self::AMF => 'AMD AMF',
            self::VIDEOTOOLBOX => 'Apple VideoToolbox',
            self::VDPAU => 'VDPAU',
            self::VULKAN => 'Vulkan',
            self::OPENCL => 'OpenCL',
            self::DRM => 'DRM',
            self::DXVA2 => 'DirectX DXVA2',
            self::D3D11VA => 'Direct3D 11',
            self::D3D12VA => 'Direct3D 12',
            self::V4L2M2M => 'V4L2 M2M',
            self::OMX => 'OpenMAX',
            self::MEDIACODEC => 'Android MediaCodec',
            self::MF => 'Media Foundation',
        };
    }

    public function priority(): int
    {
        return match ($this) {
            self::NVENC => 0,
            self::QSV => 1,
            self::VAAPI => 2,
            self::AMF => 3,
            self::VIDEOTOOLBOX => 4,
            self::VULKAN => 5,
            self::V4L2M2M => 6,
            self::OMX => 7,
            self::MEDIACODEC => 8,
            self::MF => 9,
            default => PHP_INT_MAX,
        };
    }

    /**
     * These backends have no device of their own to open, so availability is checked by encoding a frame.
     */
    public function checksWithEncoder(): bool
    {
        return in_array($this, [self::NVENC, self::AMF, self::VIDEOTOOLBOX, self::V4L2M2M, self::OMX, self::MEDIACODEC, self::MF], true);
    }

    public function statusDevice(?string $device = null): ?string
    {
        return match ($this) {
            self::NVENC => 'cuda=status'.($device !== null ? ':'.$device : ''),
            self::QSV => 'qsv=status:hw'.($device !== null ? ',child_device='.$device : ''),
            self::VAAPI => 'vaapi=status'.($device !== null ? ':'.$device : ''),
            self::OPENCL => 'opencl=status:,device_type=gpu',
            self::AMF, self::VIDEOTOOLBOX, self::V4L2M2M, self::OMX, self::MEDIACODEC, self::MF => null,
            default => $this->value.'=status',
        };
    }

    /** @return list<string> */
    public function hardwareOnlyArguments(?string $device = null): array
    {
        return match ($this) {
            self::NVENC => $device !== null ? ['-gpu', $device] : [],
            self::MF => ['-hw_encoding', '1'],
            self::VIDEOTOOLBOX => ['-allow_sw', '0', '-require_sw', '0'],
            default => [],
        };
    }

    public function hasTestableEncoders(): bool
    {
        return in_array($this, [self::NVENC, self::VAAPI, self::QSV], true);
    }

    public function devicePattern(): ?string
    {
        return match ($this) {
            self::NVENC => '/^(0|[1-9][0-9]*)$/D',
            self::QSV, self::VAAPI => '/^(\/dev\/dri\/renderD[0-9]+|0|[1-9][0-9]*)$/D',
            default => null,
        };
    }

    /**
     * They go before the input.
     *
     * @return list<string>
     */
    public function encodingDeviceArguments(?string $device = null): array
    {
        return match ($this) {
            self::VAAPI => ['-init_hw_device', 'vaapi=hw'.($device !== null ? ':'.$device : ''), '-filter_hw_device', 'hw'],
            self::QSV => ['-init_hw_device', 'qsv=hw:hw'.($device !== null ? ',child_device='.$device : '')],
            default => [],
        };
    }

    public function uploadFilter(): ?string
    {
        return $this === self::VAAPI ? 'hwupload' : null;
    }
}
