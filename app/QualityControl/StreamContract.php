<?php

namespace App\QualityControl;

use App\Encoding\Data\MediaSettings;
use App\Encoding\Enums\AudioChannels;
use App\Encoding\Enums\HdrMode;
use RuntimeException;

class StreamContract
{
    /**
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $output
     * @param  list<array{index: int, type: string, action: string}>  $decisions
     * @return array{source_video: array<string, mixed>, output_video: array<string, mixed>, expected: list<array<string, mixed>>, hdr_view: string}
     */
    public function verify(array $source, array $output, array $decisions, MediaSettings $settings): array
    {
        $sources = $this->streams($source);
        $outputs = array_values($this->streams($output));
        if (count($decisions) !== count($sources) || count(array_unique(array_column($decisions, 'index'))) !== count($sources)) {
            throw new RuntimeException('The saved stream decisions do not describe every source stream.');
        }
        foreach ($decisions as $decision) {
            if (! isset($sources[$decision['index']]) || ($sources[$decision['index']]['codec_type'] ?? null) !== $decision['type']
                || ! in_array($decision['action'], ['copy', 'encode', 'drop'], true)) {
                throw new RuntimeException('A saved stream decision does not match the source.');
            }
        }
        $retained = array_values(array_filter($decisions, fn (array $decision): bool => $decision['action'] !== 'drop'));
        if (count($outputs) !== count($retained)) {
            throw new QualityRejected('stream_count', 'The staged file has missing or unexpected streams.');
        }
        $expected = [];
        $sourceVideo = $outputVideo = null;
        $hdrView = 'sdr';
        foreach ($retained as $index => $decision) {
            $before = $sources[$decision['index']] ?? null;
            if ($before === null || ($before['codec_type'] ?? null) !== $decision['type']) {
                throw new RuntimeException('A saved stream decision does not match the source.');
            }
            $after = $outputs[$index];
            $fields = ['codec_type' => $before['codec_type'], 'codec_name' => $before['codec_name'] ?? null];
            if ($decision['type'] === 'video' && $decision['action'] === 'encode') {
                if ($sourceVideo !== null) {
                    throw new RuntimeException('Exactly one encoded video stream is required.');
                }
                $sourceVideo = $before;
                $outputVideo = $after;
                $hdr = in_array($before['color_transfer'] ?? null, ['smpte2084', 'arib-std-b67'], true);
                $toneMap = $hdr && $settings->hdrMode === HdrMode::TONE_MAP;
                $hdrView = $hdr ? 'tone_mapped_sdr' : 'sdr';
                $fields['codec_name'] = $settings->videoCodec->value;
                foreach (['width', 'height', 'pix_fmt'] as $field) {
                    if (! isset($before[$field])) {
                        throw new RuntimeException('The source video format is incomplete.');
                    }
                    $fields[$field] = $before[$field];
                }
                foreach (['color_primaries', 'color_transfer', 'color_space', 'color_range'] as $field) {
                    $value = $toneMap ? ($field === 'color_range' ? 'tv' : 'bt709') : ($before[$field] ?? null);
                    if ($value !== null && ! in_array($value, ['unknown', 'unspecified'], true)) {
                        $fields[$field] = $value;
                    }
                }
                if (! $hdr && in_array($after['color_transfer'] ?? null, ['smpte2084', 'arib-std-b67'], true)) {
                    throw new QualityRejected('hdr_metadata', 'The output unexpectedly advertises HDR.');
                }
                if (abs($this->rotation($before) - $this->rotation($after)) > 0.001) {
                    throw new QualityRejected('rotation', 'The video display rotation changed.');
                }
                $sar = self::fraction($before['sample_aspect_ratio'] ?? null);
                if ($sar !== null && $sar > 0 && abs($sar - (self::fraction($after['sample_aspect_ratio'] ?? null) ?? 0)) > 0.000001) {
                    throw new QualityRejected('aspect_ratio', 'The video sample aspect ratio changed.');
                }
            } elseif ($decision['type'] === 'audio') {
                $fields['channels'] = $before['channels'] ?? null;
                if ($decision['action'] === 'encode') {
                    $fields['codec_name'] = $settings->audioCodec->value;
                    if ($settings->audioChannels === AudioChannels::STEREO) {
                        $fields['channels'] = min(2, (int) $fields['channels']);
                    }
                }
            }
            foreach ($fields as $field => $value) {
                if ($value === null) {
                    throw new RuntimeException('The expected '.$decision['type'].' '.$field.' is unknown.');
                }
                if (($after[$field] ?? null) !== $value) {
                    throw new QualityRejected('stream_format', 'Output stream '.$index.' does not preserve the expected '.$field.'.');
                }
            }
            $this->metadata($before, $after, $index);
            $expected[] = ['source_index' => $decision['index'], 'output_index' => $after['index'], 'action' => $decision['action'], ...$fields];
        }
        if ($sourceVideo === null || $outputVideo === null) {
            throw new RuntimeException('The saved decisions contain no encoded video.');
        }
        $this->chapters($source['chapters'] ?? [], $output['chapters'] ?? []);

        return ['source_video' => $sourceVideo, 'output_video' => $outputVideo, 'expected' => $expected, 'hdr_view' => $hdrView];
    }

    public static function fraction(mixed $value): ?float
    {
        if (! is_string($value) || ! preg_match('/^(-?\d+)[\/:]([1-9]\d*)$/D', $value, $parts)) {
            return null;
        }

        return (float) $parts[1] / (float) $parts[2];
    }

    /** @param array<string, mixed> $probe
     * @return array<int, array<string, mixed>>
     */
    private function streams(array $probe): array
    {
        $streams = $probe['streams'] ?? null;
        if (! is_array($streams) || $streams === []) {
            throw new RuntimeException('The media probe returned no streams.');
        }
        $indexed = [];
        foreach ($streams as $stream) {
            if (! is_array($stream) || ! isset($stream['index']) || ! is_int($stream['index']) || isset($indexed[$stream['index']])) {
                throw new RuntimeException('The media probe returned an invalid stream index.');
            }
            $indexed[$stream['index']] = $stream;
        }

        return $indexed;
    }

    /** @param array<string, mixed> $before
     * @param  array<string, mixed>  $after
     */
    private function metadata(array $before, array $after, int $index): void
    {
        $sourceTags = array_change_key_case($before['tags'] ?? [], CASE_LOWER);
        $outputTags = array_change_key_case($after['tags'] ?? [], CASE_LOWER);
        if ($this->language($sourceTags['language'] ?? null) !== $this->language($outputTags['language'] ?? null)) {
            throw new QualityRejected('stream_metadata', 'Output stream '.$index.' did not retain its language.');
        }
        foreach (['title', 'filename', 'mimetype'] as $tag) {
            if (isset($sourceTags[$tag]) && $sourceTags[$tag] !== ($outputTags[$tag] ?? null)) {
                throw new QualityRejected('stream_metadata', 'Output stream '.$index.' did not retain its '.$tag.'.');
            }
        }
        foreach (($before['disposition'] ?? []) as $flag => $value) {
            if ((int) $value !== (int) ($after['disposition'][$flag] ?? 0)) {
                throw new QualityRejected('stream_disposition', 'Output stream '.$index.' changed its '.$flag.' disposition.');
            }
        }
    }

    private function language(?string $language): ?string
    {
        $language = strtolower(trim($language ?? ''));

        return in_array($language, ['', 'und'], true) ? null : $language;
    }

    /** @param array<string, mixed> $stream */
    private function rotation(array $stream): float
    {
        foreach ($stream['side_data_list'] ?? [] as $side) {
            if (($side['side_data_type'] ?? null) === 'Display Matrix' && isset($side['rotation'])) {
                return fmod((float) $side['rotation'] + 360, 360);
            }
        }

        return fmod((float) ($stream['tags']['rotate'] ?? 0) + 360, 360);
    }

    /** @param list<array<string, mixed>> $before
     * @param  list<array<string, mixed>>  $after
     */
    private function chapters(array $before, array $after): void
    {
        if (count($before) !== count($after)) {
            throw new QualityRejected('chapters', 'The chapter count changed.');
        }
        foreach ($before as $index => $chapter) {
            foreach (['start_time', 'end_time'] as $field) {
                if (! isset($chapter[$field], $after[$index][$field]) || abs((float) $chapter[$field] - (float) $after[$index][$field]) > 0.002) {
                    throw new QualityRejected('chapters', 'Chapter timing changed.');
                }
            }
            if (($chapter['tags']['title'] ?? null) !== ($after[$index]['tags']['title'] ?? null)) {
                throw new QualityRejected('chapters', 'A chapter title changed.');
            }
        }
    }
}
