<?php

namespace App\Transcoding;

use App\Encoding\Data\MediaSettings;
use App\Encoding\Enums\HdrMode;
use App\Encoding\Enums\TargetVideoCodec;
use App\Models\MediaStream;

class VideoStreamOptions
{
    /**
     * @param  array<string, mixed>  $raw
     * @return array{arguments: list<string>, filter: string, software_only: bool}|SkippedTranscode
     */
    public function handle(MediaStream $video, array $raw, MediaSettings $settings, ?string $ffmpegVersion): array|SkippedTranscode
    {
        $formats = $this->formats($settings->videoCodec);
        if (! in_array($video->pixel_format, $formats, true) || $video->width < 1 || $video->height < 1
            || (str_starts_with($video->pixel_format ?? '', 'yuv420') && ($video->width % 2 !== 0 || $video->height % 2 !== 0))
            || (str_starts_with($video->pixel_format ?? '', 'yuv422') && $video->width % 2 !== 0)
            || $video->average_frame_rate === null || ($raw['field_order'] ?? 'progressive') !== 'progressive') {
            return new SkippedTranscode('unsupported_video_format', 'The source resolution, frame timing, bit depth, chroma, or scan type cannot be preserved safely.');
        }
        $hdr = in_array($video->color_transfer, ['smpte2084', 'arib-std-b67'], true);
        $dynamic = false;
        $sides = $raw['side_data_list'] ?? [];
        $sides = is_array($sides) ? $sides : [];
        foreach ($sides as $side) {
            if (is_array($side) && preg_match('/dovi|dolby|dynamic.*hdr|hdr10\+/i', (string) ($side['side_data_type'] ?? ''))) {
                $dynamic = true;
                $hdr = true;
            }
        }
        if ($hdr && $settings->hdrMode === HdrMode::SKIP) {
            return new SkippedTranscode('hdr_policy', 'HDR sources are excluded by the media settings.');
        }
        if ($dynamic && $settings->hdrMode === HdrMode::PRESERVE) {
            return new SkippedTranscode('unsupported_dynamic_hdr', 'Dynamic HDR metadata cannot be preserved by the selected encoding pipeline.');
        }
        if ($hdr && ($video->color_primaries !== 'bt2020' || $video->color_space !== 'bt2020nc'
            || ! in_array($video->color_transfer, ['smpte2084', 'arib-std-b67'], true))) {
            return new SkippedTranscode('unsupported_hdr_color', 'HDR conversion requires known BT.2020 primaries, matrix, and PQ or HLG transfer.');
        }
        $arguments = [];
        $filter = '';
        if ($hdr && $settings->hdrMode === HdrMode::TONE_MAP) {
            $filter = 'zscale=t=linear:npl=100,format=gbrpf32le,zscale=p=bt709,tonemap=tonemap=hable:desat=0,zscale=t=bt709:m=bt709:r=tv,format='.$video->pixel_format
                .',sidedata=mode=delete:type=MASTERING_DISPLAY_METADATA,sidedata=mode=delete:type=CONTENT_LIGHT_LEVEL'
                .',sidedata=mode=delete:type=DYNAMIC_HDR_PLUS,sidedata=mode=delete:type=DOVI_RPU_BUFFER,sidedata=mode=delete:type=DOVI_METADATA,sidedata=mode=delete:type=DYNAMIC_HDR_VIVID';
            $arguments = ['-color_primaries:v:0', 'bt709', '-color_trc:v:0', 'bt709', '-colorspace:v:0', 'bt709', '-color_range:v:0', 'tv'];
            // Container tags from the source must not advertise obsolete HDR mastering data.
            foreach (['mastering-display', 'max-cll', 'HDR_FORMAT', 'DURATION', 'NUMBER_OF_FRAMES', 'NUMBER_OF_BYTES', 'BPS'] as $tag) {
                $arguments = [...$arguments, '-metadata:s:v:0', $tag.'='];
            }
        } else {
            foreach (['color_primaries' => 'color_primaries', 'color_transfer' => 'color_trc', 'color_space' => 'colorspace', 'color_range' => 'color_range'] as $property => $option) {
                $value = $video->{$property};
                if (is_string($value) && $value !== 'unknown' && $value !== 'unspecified') {
                    $arguments = [...$arguments, '-'.$option.':v:0', $value];
                }
            }
            $params = [];
            foreach ($sides as $side) {
                if (! is_array($side)) {
                    continue;
                }
                $type = $side['side_data_type'] ?? null;
                if ($type === 'Mastering display metadata') {
                    if ($settings->videoCodec === TargetVideoCodec::AV1) {
                        if (! $this->supportsAv1Mastering($ffmpegVersion)) {
                            return new SkippedTranscode('unsupported_hdr_mastering', 'Preserving AV1 mastering display metadata requires FFmpeg 7.1 or later.');
                        }

                        continue;
                    }
                    $mastering = $this->mastering($side);
                    if ($mastering === null) {
                        return new SkippedTranscode('incomplete_hdr_mastering', 'The mastering display metadata is incomplete.');
                    }
                    $params[] = 'master-display='.$mastering;
                } elseif ($type === 'Content light level metadata') {
                    if ($settings->videoCodec === TargetVideoCodec::AV1 && $this->supportsAv1Mastering($ffmpegVersion)) {
                        continue;
                    }
                    if ($settings->videoCodec !== TargetVideoCodec::HEVC || ! isset($side['max_content'], $side['max_average'])) {
                        return new SkippedTranscode('unsupported_hdr_mastering', 'The content light metadata cannot be preserved by the selected pipeline.');
                    }
                    $params[] = 'max-cll='.(int) $side['max_content'].','.(int) $side['max_average'];
                }
            }
            if ($params !== []) {
                $arguments = [...$arguments, '-x265-params', implode(':', $params)];
            }
        }

        return ['arguments' => $arguments, 'filter' => $filter,
            'software_only' => $hdr || $video->width > 4096 || $video->height > 4096 || ! in_array($video->pixel_format, ['yuv420p', 'yuv420p10le'], true)];
    }

    private function supportsAv1Mastering(?string $version): bool
    {
        return $version !== null && preg_match('/^(?:n)?(\d+\.\d+(?:\.\d+)?)/', $version, $parts) === 1
            && version_compare($parts[1], '7.1', '>=');
    }

    /** @return list<string> */
    private function formats(TargetVideoCodec $codec): array
    {
        return match ($codec) {
            TargetVideoCodec::HEVC => ['yuv420p', 'yuv420p10le', 'yuv420p12le', 'yuv422p', 'yuv422p10le', 'yuv422p12le', 'yuv444p', 'yuv444p10le', 'yuv444p12le'],
            TargetVideoCodec::AV1 => ['yuv420p', 'yuv420p10le'],
        };
    }

    /** @param array<array-key, mixed> $side */
    private function mastering(array $side): ?string
    {
        $values = [];
        foreach (['green_x', 'green_y', 'blue_x', 'blue_y', 'red_x', 'red_y', 'white_point_x', 'white_point_y', 'max_luminance', 'min_luminance'] as $key) {
            $fraction = $side[$key] ?? null;
            if (! is_string($fraction) || ! preg_match('/^(\d+)\/([1-9]\d*)$/D', $fraction, $parts)) {
                return null;
            }
            $scale = str_contains($key, 'luminance') ? 10000 : 50000;
            $values[] = (int) round((int) $parts[1] / (int) $parts[2] * $scale);
        }

        return sprintf('G(%d,%d)B(%d,%d)R(%d,%d)WP(%d,%d)L(%d,%d)', ...$values);
    }
}
