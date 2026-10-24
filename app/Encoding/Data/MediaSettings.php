<?php

namespace App\Encoding\Data;

use App\Encoding\Enums\AudioChannels;
use App\Encoding\Enums\AudioCodec;
use App\Encoding\Enums\EncodingSpeed;
use App\Encoding\Enums\HdrMode;
use App\Encoding\Enums\TargetVideoCodec;
use App\Encoding\Enums\VideoQuality;

/**
 * @phpstan-type MediaSettingsData array{video_codec: string, video_quality: string, encoding_speed: string, hdr_mode: string, audio_codec: string, audio_channels: string, audio_bitrate_per_channel: int, preserve_lossless_audio: bool, audio_languages: list<string>, retain_subtitles: bool, subtitle_languages: list<string>, preserve_forced_subtitles: bool, skip_video_codecs: list<string>, require_smaller_output: bool, minimum_savings_percent: int, verify_video_quality: bool, minimum_vmaf: float}
 */
readonly class MediaSettings
{
    /**
     * @param  list<string>  $audioLanguages
     * @param  list<string>  $subtitleLanguages
     * @param  list<string>  $skipVideoCodecs
     */
    public function __construct(
        public TargetVideoCodec $videoCodec = TargetVideoCodec::HEVC,
        public VideoQuality $videoQuality = VideoQuality::HIGH,
        public EncodingSpeed $encodingSpeed = EncodingSpeed::BALANCED,
        public HdrMode $hdrMode = HdrMode::PRESERVE,
        public AudioCodec $audioCodec = AudioCodec::COPY,
        public AudioChannels $audioChannels = AudioChannels::PRESERVE,
        public int $audioBitratePerChannel = 64,
        public bool $preserveLosslessAudio = true,
        public array $audioLanguages = [],
        public bool $retainSubtitles = true,
        public array $subtitleLanguages = [],
        public bool $preserveForcedSubtitles = true,
        public array $skipVideoCodecs = ['hevc', 'av1'],
        public bool $requireSmallerOutput = true,
        public int $minimumSavingsPercent = 5,
        public bool $verifyVideoQuality = true,
        public float $minimumVmaf = 95.0,
    ) {}

    /** @return MediaSettingsData */
    public function toArray(): array
    {
        return [
            'video_codec' => $this->videoCodec->value,
            'video_quality' => $this->videoQuality->value,
            'encoding_speed' => $this->encodingSpeed->value,
            'hdr_mode' => $this->hdrMode->value,
            'audio_codec' => $this->audioCodec->value,
            'audio_channels' => $this->audioChannels->value,
            'audio_bitrate_per_channel' => $this->audioBitratePerChannel,
            'preserve_lossless_audio' => $this->preserveLosslessAudio,
            'audio_languages' => $this->audioLanguages,
            'retain_subtitles' => $this->retainSubtitles,
            'subtitle_languages' => $this->subtitleLanguages,
            'preserve_forced_subtitles' => $this->preserveForcedSubtitles,
            'skip_video_codecs' => $this->skipVideoCodecs,
            'require_smaller_output' => $this->requireSmallerOutput,
            'minimum_savings_percent' => $this->minimumSavingsPercent,
            'verify_video_quality' => $this->verifyVideoQuality,
            'minimum_vmaf' => $this->minimumVmaf,
        ];
    }

    /** @param MediaSettingsData $data */
    public static function fromArray(array $data): self
    {
        return new self(
            TargetVideoCodec::from($data['video_codec']), VideoQuality::from($data['video_quality']),
            EncodingSpeed::from($data['encoding_speed']), HdrMode::from($data['hdr_mode']),
            AudioCodec::from($data['audio_codec']), AudioChannels::from($data['audio_channels']),
            $data['audio_bitrate_per_channel'], $data['preserve_lossless_audio'], $data['audio_languages'],
            $data['retain_subtitles'], $data['subtitle_languages'], $data['preserve_forced_subtitles'],
            $data['skip_video_codecs'], $data['require_smaller_output'], $data['minimum_savings_percent'],
            $data['verify_video_quality'], $data['minimum_vmaf'],
        );
    }
}
