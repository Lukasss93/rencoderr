<?php

namespace App\Transcoding;

class ContainerStreams
{
    /** @param array<string, mixed> $stream
     * @param  array<string, mixed>  $probe
     */
    public static function isChapterTrack(array $stream, array $probe): bool
    {
        return in_array('mp4', explode(',', $probe['format']['format_name'] ?? ''), true)
            && ($probe['chapters'] ?? []) !== []
            && ($stream['codec_type'] ?? null) === 'data'
            && ($stream['codec_name'] ?? null) === 'bin_data'
            && ($stream['codec_tag_string'] ?? null) === 'text'
            && ($stream['tags']['handler_name'] ?? null) === 'SubtitleHandler';
    }
}
