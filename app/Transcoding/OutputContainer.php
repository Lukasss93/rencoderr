<?php

namespace App\Transcoding;

use App\Models\MediaFile;

enum OutputContainer: string
{
    case MP4 = 'mp4';
    case MKV = 'mkv';

    public static function forFile(MediaFile $file): self
    {
        return self::forSource($file->relative_path, $file->probe?->format);
    }

    public static function forSource(string $path, ?string $format): self
    {
        return strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'mp4'
            && in_array('mp4', explode(',', $format ?? ''), true) ? self::MP4 : self::MKV;
    }

    public function muxer(): string
    {
        return $this === self::MP4 ? 'mp4' : 'matroska';
    }

    public function matchesFormat(?string $format): bool
    {
        return in_array($this->muxer(), explode(',', $format ?? ''), true);
    }

    public function filename(bool $partial = false): string
    {
        return 'output'.($partial ? '.partial' : '').'.'.$this->value;
    }

    public function supports(string $type, string $codec): bool
    {
        if ($type === 'subtitle') {
            return in_array($codec, $this === self::MP4 ? ['mov_text', 'ttml']
                : ['subrip', 'ass', 'ssa', 'webvtt', 'hdmv_pgs_subtitle', 'dvd_subtitle', 'dvb_subtitle'], true);
        }
        if ($this === self::MKV) {
            return true;
        }

        return match ($type) {
            'audio' => in_array($codec, ['aac', 'ac3', 'eac3', 'mp2', 'mp3', 'dts', 'alac', 'flac', 'opus',
                'pcm_s16le', 'pcm_s16be', 'pcm_s24le', 'pcm_s24be', 'pcm_s32le', 'pcm_s32be',
                'pcm_f32le', 'pcm_f32be', 'pcm_f64le', 'pcm_f64be'], true),
            'video' => in_array($codec, ['hevc', 'av1', 'mjpeg', 'png', 'bmp'], true),
            default => false,
        };
    }
}
