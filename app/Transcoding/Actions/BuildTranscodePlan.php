<?php

namespace App\Transcoding\Actions;

use App\Encoding\AccelerationPreference;
use App\Encoding\Data\Detection;
use App\Encoding\Data\MediaSettings;
use App\Encoding\EncoderDetector;
use App\Encoding\Enums\AudioChannels;
use App\Encoding\Enums\AudioCodec;
use App\Encoding\Enums\VideoEncoder;
use App\Ffmpeg\HardwareDecoding;
use App\Library\MediaFilePath;
use App\Models\MediaFile;
use App\Models\MediaStream;
use App\Transcoding\SkippedTranscode;
use App\Transcoding\TranscodePlan;
use App\Transcoding\VideoStreamOptions;
use InvalidArgumentException;
use RuntimeException;

class BuildTranscodePlan
{
    public function __construct(
        private MediaFilePath $paths,
        private EncoderDetector $detector,
        private AccelerationPreference $acceleration,
        private VideoStreamOptions $videoOptions,
    ) {}

    public function handle(MediaFile $file, MediaSettings $settings): TranscodePlan|SkippedTranscode
    {
        $file->loadMissing('probe.streams');

        if (! $file->hasCurrentProbe()) {
            return new SkippedTranscode('stale_probe', 'A completed probe matching the current file is required.');
        }

        try {
            $path = $this->paths->resolve($file);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return new SkippedTranscode('source_unavailable', $exception->getMessage());
        }

        if (! $this->paths->isUnchanged($file, $path)) {
            return new SkippedTranscode('source_changed', 'The source changed after it was probed.');
        }

        $probe = $file->probe;
        assert($probe !== null);
        $videos = $probe->streams->filter(fn (MediaStream $stream): bool => $stream->codec_type === 'video' && ! $stream->is_attached_picture);

        if ($videos->count() !== 1) {
            return new SkippedTranscode('unsupported_video_streams', 'Exactly one substantive video stream is supported.');
        }

        $video = $videos->first();
        assert($video !== null);

        if (in_array($video->codec_name, $settings->skipVideoCodecs, true)) {
            return new SkippedTranscode('video_codec_policy', 'The source video codec is excluded by the media settings.');
        }

        $metadata = $probe->getAttribute('metadata');
        $metadata = is_array($metadata) ? $metadata : [];
        $raw = $this->rawStream($metadata, $video->stream_index);
        $detection = $this->detector->current();
        $options = $this->videoOptions->handle($video, $raw, $settings);

        if ($options instanceof SkippedTranscode) {
            return $options;
        }

        $encoder = $this->encoder($detection, $settings, $options['software_only']);
        $device = $detection->backends[$encoder->backend()->value ?? '']->device ?? null;
        $encodingDevice = $encoder->backend()?->encodingDeviceArguments($device) ?? [];
        $decoding = new HardwareDecoding($encoder->backend(), $device);
        $decodeDevice = $encodingDevice !== [] ? 'hw' : 'decode';
        $decodeInput = $decoding->inputArguments(['index' => $video->stream_index, 'codec_name' => $video->codec_name, 'pix_fmt' => $video->pixel_format], $decodeDevice);
        $inputArguments = ['-nostdin', '-hide_banner', '-noautorotate', ...$encodingDevice];
        $arguments = [...$inputArguments, ...($decodeInput !== [] && $decodeDevice === 'decode' ? $decoding->deviceArguments() : []), ...$decodeInput, '-i', $path,
            '-map_metadata', '0', '-map_chapters', '0', '-c', 'copy'];
        $decisions = [];
        $output = 0;
        $audioCount = 0;
        $sourceAudioCount = 0;

        foreach ($videos->concat($probe->streams->reject(fn (MediaStream $stream): bool => $stream->is($video))) as $stream) {
            $action = 'copy';
            $codec = 'copy';
            $extra = [];

            if ($stream->codec_type === 'video' && ! $stream->is_attached_picture) {
                $action = 'encode';
                $codec = $encoder->value;
            } elseif ($stream->codec_type === 'audio') {
                $sourceAudioCount++;
                if (! $this->languageMatches($stream->language, $settings->audioLanguages)) {
                    $action = 'drop';
                } else {
                    $audioCount++;
                    if ($settings->audioCodec !== AudioCodec::COPY && ! ($settings->preserveLosslessAudio && $this->isLossless($stream))) {
                        if (! is_int($stream->channels) || $stream->channels < 1) {
                            return new SkippedTranscode('unknown_audio_channels', 'The channel count of a retained audio stream is unknown.');
                        }
                        $channels = $settings->audioChannels === AudioChannels::STEREO ? min(2, $stream->channels) : $stream->channels;
                        if ($channels > 8) {
                            return new SkippedTranscode('unsupported_audio_layout', 'The retained audio layout cannot be encoded safely.');
                        }
                        $codec = $settings->audioCodec->encoder();
                        $this->requireEncoder($detection, $codec);
                        $action = 'encode';
                        $extra = ['-ac:'.$output, (string) $channels, '-b:'.$output, ($settings->audioBitratePerChannel * $channels).'k'];
                    }
                }
            } elseif ($stream->codec_type === 'subtitle') {
                $retain = $settings->retainSubtitles && ($this->languageMatches($stream->language, $settings->subtitleLanguages)
                    || ($settings->preserveForcedSubtitles && $stream->is_forced));
                $action = $retain ? 'copy' : 'drop';
                if ($retain && ! in_array($stream->codec_name, ['subrip', 'ass', 'ssa', 'webvtt', 'hdmv_pgs_subtitle', 'dvd_subtitle', 'dvb_subtitle'], true)) {
                    return new SkippedTranscode('unsupported_subtitle', 'A retained subtitle codec cannot be copied to Matroska.');
                }
            } elseif ($stream->codec_type !== 'attachment' && $stream->codec_type !== 'video') {
                return new SkippedTranscode('unsupported_stream', 'The source contains a retained stream that cannot be copied safely.');
            }

            $decisions[] = ['index' => $stream->stream_index, 'type' => $stream->codec_type, 'action' => $action];
            if ($action === 'drop') {
                continue;
            }
            if ($stream->codec_name === null) {
                return new SkippedTranscode('unknown_codec', 'A retained stream has an unknown codec.');
            }

            if ($stream->codec_type === 'audio' && $codec === 'copy' && ! $this->copyableAudio($stream)) {
                return new SkippedTranscode('unsupported_audio_codec', 'A retained audio codec cannot be copied to Matroska safely.');
            }

            $arguments = [...$arguments, '-map', '0:'.$stream->stream_index, '-c:'.$output, $codec,
                '-map_metadata:s:'.$output, '0:s:'.$stream->stream_index, ...$extra];
            $rawStream = $this->rawStream($metadata, $stream->stream_index);
            $dispositions = [];
            foreach (($rawStream['disposition'] ?? []) as $name => $enabled) {
                if ($enabled === 1 && preg_match('/^[a-z_]+$/D', (string) $name)) {
                    $dispositions[] = $name;
                }
            }
            if ($dispositions === []) {
                if ($stream->is_default) {
                    $dispositions[] = 'default';
                }
                if ($stream->is_forced) {
                    $dispositions[] = 'forced';
                }
                if ($stream->is_attached_picture) {
                    $dispositions[] = 'attached_pic';
                }
            }
            $arguments = [...$arguments, '-disposition:'.$output, implode('+', $dispositions) ?: '0'];
            $output++;
        }

        if ($sourceAudioCount > 0 && $audioCount === 0) {
            return new SkippedTranscode('no_audio_matches', 'The audio language filter excludes every source audio stream.');
        }

        $filter = $options['filter'];
        $pixelFormat = match (true) {
            $encoder->backend()?->uploadFilter() !== null => 'vaapi',
            $encoder->backend() !== null && $video->pixel_format === 'yuv420p10le' => 'p010le',
            $encoder->backend() !== null => 'nv12',
            default => (string) $video->pixel_format,
        };
        if ($encoder->backend() !== null) {
            $format = $video->pixel_format === 'yuv420p10le' ? 'p010le' : 'nv12';
            $filter = implode(',', array_filter([$filter, 'scale=iw:ih:flags=bitexact', 'format='.$format, $encoder->backend()->uploadFilter()]));
        }
        $prefix = $arguments;
        $arguments = [...$settings->encodingSpeed->arguments($encoder),
            ...($encoder->backend()?->hardwareOnlyArguments($device) ?? []),
            '-fps_mode:v:0', 'passthrough', '-enc_time_base:v:0', 'demux', '-pix_fmt:v:0', '+'.$pixelFormat,
            ...$options['arguments']];
        if ($filter !== '') {
            $arguments = [...$arguments, '-filter:v:0', $filter];
        }
        $arguments = [...$arguments, '-f', 'matroska'];

        return new TranscodePlan($encoder, $settings, $path, $file->size_bytes, $file->filesystem_modified_at,
            [...$prefix, ...$settings->videoQuality->arguments($encoder), ...$arguments], $decisions, $prefix, $inputArguments, $arguments, decoding: $decoding);
    }

    private function encoder(Detection $detection, MediaSettings $settings, bool $softwareOnly): VideoEncoder
    {
        $backend = $softwareOnly ? null : $this->acceleration->resolve($detection, $settings->videoCodec->value);
        foreach (VideoEncoder::cases() as $encoder) {
            if ($backend !== null && $encoder->backend() === $backend && $encoder->targetCodec() === $settings->videoCodec) {
                $candidate = $detection->encoder($encoder->value);
                if ($candidate !== null && $detection->isAvailable($candidate)) {
                    return $encoder;
                }
            }
        }
        $encoder = $settings->videoCodec->softwareEncoder();
        $this->requireEncoder($detection, $encoder->value);

        return $encoder;
    }

    private function requireEncoder(Detection $detection, string $name): void
    {
        $encoder = $detection->encoder($name);
        if ($detection->error !== null || $encoder === null || ! $detection->isAvailable($encoder)) {
            throw new RuntimeException('The required FFmpeg encoder '.$name.' is unavailable. Refresh encoder detection or install an FFmpeg build that provides it.');
        }
    }

    /** @param list<string> $languages */
    private function languageMatches(?string $language, array $languages): bool
    {
        return $languages === [] || in_array(strtolower($language ?? 'und'), $languages, true);
    }

    /**
     * @param  array<array-key, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function rawStream(array $metadata, int $index): array
    {
        $streams = $metadata['streams'] ?? [];
        if (is_array($streams)) {
            foreach ($streams as $stream) {
                if (is_array($stream) && ($stream['index'] ?? null) === $index) {
                    return array_filter($stream, is_string(...), ARRAY_FILTER_USE_KEY);
                }
            }
        }

        return [];
    }

    private function copyableAudio(MediaStream $stream): bool
    {
        return $this->isLossless($stream)
            || in_array($stream->codec_name, ['aac', 'ac3', 'eac3', 'dts', 'mp2', 'mp3', 'opus', 'vorbis', 'cook', 'ra_144', 'ra_288', 'sipr', 'atrac3'], true);
    }

    private function isLossless(MediaStream $stream): bool
    {
        return in_array($stream->codec_name, ['flac', 'alac', 'truehd', 'mlp', 'wavpack', 'ape', 'tta'], true)
            || str_starts_with($stream->codec_name ?? '', 'pcm_')
            || ($stream->codec_name === 'dts' && in_array($stream->profile, ['DTS-HD MA', 'DTS-HD MA + DTS:X'], true));
    }
}
