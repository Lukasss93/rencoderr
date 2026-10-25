<?php

namespace App\Ffmpeg;

use App\Ffmpeg\Enums\HardwareBackend;
use InvalidArgumentException;

readonly class HardwareDecoding
{
    public function __construct(public ?HardwareBackend $backend = null, public ?string $device = null)
    {
        if ($backend !== null && ! in_array($backend, [HardwareBackend::VAAPI, HardwareBackend::QSV, HardwareBackend::NVENC], true)) {
            throw new InvalidArgumentException('Unsupported hardware decoding backend.');
        }
        if ($device !== null && ($backend === null || preg_match($backend->devicePattern() ?? '//', $device) !== 1)) {
            throw new InvalidArgumentException('Invalid hardware decoding device.');
        }
    }

    /** @param array<string, mixed> $policy */
    public static function fromPolicy(array $policy): self
    {
        if (! array_key_exists('decoding', $policy)) {
            return new self;
        }
        $context = $policy['decoding'];
        if (! is_array($context) || ! array_key_exists('backend', $context) || ! array_key_exists('device', $context)
            || ($context['backend'] !== null && ! is_string($context['backend']))
            || ($context['device'] !== null && ! is_string($context['device']))) {
            throw new InvalidArgumentException('The saved hardware decoding policy is invalid.');
        }
        $backend = $context['backend'] === null ? null : HardwareBackend::tryFrom($context['backend']);
        if ($context['backend'] !== null && $backend === null) {
            throw new InvalidArgumentException('The saved hardware decoding backend is invalid.');
        }

        return new self($backend, $context['device']);
    }

    /** @return array{backend: ?string, device: ?string} */
    public function toArray(): array
    {
        return ['backend' => $this->backend?->value, 'device' => $this->device];
    }

    /** @return list<string> */
    public function deviceArguments(): array
    {
        if ($this->backend === null) {
            return [];
        }
        $device = match ($this->backend) {
            HardwareBackend::VAAPI => 'vaapi=decode'.($this->device !== null ? ':'.$this->device : ''),
            HardwareBackend::QSV => 'qsv=decode:hw'.($this->device !== null ? ',child_device='.$this->device : ''),
            default => 'cuda=decode'.($this->device !== null ? ':'.$this->device : ''),
        };

        return ['-init_hw_device', $device];
    }

    /** @param array<string, mixed> $video
     * @return list<string>
     */
    public function inputArguments(array $video, string $deviceName = 'decode'): array
    {
        $codec = $video['codec_name'] ?? null;
        $pixel = $video['pix_fmt'] ?? null;
        $index = $video['index'] ?? null;
        if ($this->backend === null || ! is_int($index) || $index < 0
            || ! in_array($codec, ['h264', 'hevc', 'av1'], true)
            || ! in_array($pixel, ['yuv420p', 'yuv420p10le'], true)
            || ($codec === 'h264' && $pixel !== 'yuv420p')) {
            return [];
        }
        $api = $this->backend === HardwareBackend::NVENC ? 'cuda' : $this->backend->value;
        $decoder = $codec.($this->backend === HardwareBackend::QSV ? '_qsv' : '');

        // Download into the native surface layout; filters then repack planes without changing depth.
        return ['-hwaccel:'.$index, $api, '-hwaccel_device:'.$index, $deviceName,
            '-hwaccel_output_format:'.$index, $pixel === 'yuv420p10le' ? 'p010le' : 'nv12', '-c:'.$index, $decoder];
    }
}
