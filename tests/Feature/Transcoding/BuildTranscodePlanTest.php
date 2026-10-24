<?php

use App\Encoding\AccelerationPreference;
use App\Encoding\Data\Backend;
use App\Encoding\Data\Detection;
use App\Encoding\Data\Encoder;
use App\Encoding\Data\MediaSettings;
use App\Encoding\EncoderDetector;
use App\Encoding\Enums\AudioChannels;
use App\Encoding\Enums\AudioCodec;
use App\Encoding\Enums\EncoderType;
use App\Encoding\Enums\HdrMode;
use App\Encoding\Enums\TargetVideoCodec;
use App\Encoding\Enums\VideoEncoder;
use App\Models\LibraryPath;
use App\Models\MediaFile;
use App\Models\MediaProbe;
use App\Models\MediaStream;
use App\Tasks\Enums\TaskStatus;
use App\Transcoding\Actions\BuildTranscodePlan;
use App\Transcoding\SkippedTranscode;
use App\Transcoding\TranscodePlan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Process;

/** @param list<string> $names */
function planEncoders(array $names = ['libx265', 'libsvtav1', 'aac', 'libopus']): void
{
    $encoders = [];
    $backends = [];
    foreach ($names as $name) {
        $video = VideoEncoder::tryFrom($name);
        $backend = $video?->backend();
        $encoders[] = new Encoder($name, $video?->targetCodec()->value ?? $name, $video === null ? EncoderType::AUDIO : EncoderType::VIDEO, $name, $backend);
        if ($backend !== null) {
            $backends[$backend->value] = new Backend($backend->value, $backend->label(), 1, true, 'Available');
        }
    }
    app(EncoderDetector::class)->store(new Detection(now()->toImmutable(), version: '7.1', encoders: $encoders, backends: $backends));
    Process::preventStrayProcesses();
}

/**
 * @param  list<array<string, mixed>>  $extraStreams
 * @param  array<string, mixed>  $video
 * @param  array<string, mixed>  $rawVideo
 */
function planningFile(array $extraStreams = [], array $video = [], array $rawVideo = []): MediaFile
{
    $directory = mediaDirectory(['source.mkv' => 'source-data']);
    $path = LibraryPath::factory()->create(['path' => $directory]);
    $file = MediaFile::factory()->for($path)->atPath('source.mkv')->create([
        'size_bytes' => 11, 'filesystem_modified_at' => CarbonImmutable::createFromTimestamp((int) filemtime($directory.'/source.mkv')),
    ]);
    $probe = MediaProbe::factory()->for($file)->create([
        'status' => TaskStatus::COMPLETED, 'source_size_bytes' => $file->size_bytes, 'source_modified_at' => $file->filesystem_modified_at,
        'metadata' => ['streams' => [['index' => 0, 'field_order' => 'progressive', ...$rawVideo]]],
    ]);
    MediaStream::factory()->for($probe, 'probe')->create([
        'stream_index' => 0, 'codec_type' => 'video', 'codec_name' => 'h264', 'pixel_format' => 'yuv420p',
        'width' => 1920, 'height' => 1080, 'average_frame_rate' => '24000/1001', ...$video,
    ]);
    foreach ($extraStreams as $stream) {
        MediaStream::factory()->for($probe, 'probe')->create($stream);
    }

    return $file;
}

it('plans exact source maps, timing and metadata without applying acceptance thresholds', function (): void {
    planEncoders();
    $file = planningFile([
        ['stream_index' => 2, 'codec_type' => 'audio', 'codec_name' => 'truehd', 'channels' => 8, 'language' => 'eng', 'is_default' => true],
        ['stream_index' => 3, 'codec_type' => 'subtitle', 'codec_name' => 'ass', 'language' => 'eng'],
        ['stream_index' => 4, 'codec_type' => 'attachment', 'codec_name' => 'ttf'],
    ]);
    $settings = new MediaSettings(audioCodec: AudioCodec::OPUS, audioChannels: AudioChannels::STEREO, minimumSavingsPercent: 100, minimumVmaf: 100);

    $plan = app(BuildTranscodePlan::class)->handle($file, $settings);

    expect($plan)->toBeInstanceOf(TranscodePlan::class);
    if (! $plan instanceof TranscodePlan) {
        throw new RuntimeException($plan->message);
    }
    expect($plan->encoder)->toBe(VideoEncoder::LIBX265);
    expect($plan->settings)->toBe($settings);
    expect($plan->sourceSizeBytes)->toBe(11);
    expect($plan->sourceModifiedAt->equalTo($file->filesystem_modified_at))->toBeTrue();
    expect($plan->streamDecisions)->toBe([
        ['index' => 0, 'type' => 'video', 'action' => 'encode'], ['index' => 2, 'type' => 'audio', 'action' => 'copy'],
        ['index' => 3, 'type' => 'subtitle', 'action' => 'copy'], ['index' => 4, 'type' => 'attachment', 'action' => 'copy'],
    ]);
    expect(implode(' ', $plan->arguments))->toContain('-map_metadata 0 -map_chapters 0', '-map 0:2 -c:1 copy', '-fps_mode:v:0 passthrough -enc_time_base:v:0 demux', '-pix_fmt:v:0 +yuv420p');
    expect(implode(' ', $plan->arguments))->not->toContain('-ac:', '-s ', '-r ');
    expect(array_slice($plan->arguments, -2))->toBe(['-f', 'matroska']);
});

it('filters audio before lossless preservation and computes bitrate from output channels', function (AudioCodec $codec, AudioChannels $channels, string $bitrate, string $count): void {
    planEncoders();
    $file = planningFile([
        ['stream_index' => 1, 'codec_type' => 'audio', 'codec_name' => 'flac', 'channels' => 8, 'language' => 'ita'],
        ['stream_index' => 2, 'codec_type' => 'audio', 'codec_name' => 'ac3', 'channels' => 6, 'language' => 'eng'],
    ]);

    $plan = app(BuildTranscodePlan::class)->handle($file, new MediaSettings(audioCodec: $codec, audioChannels: $channels, audioLanguages: ['eng']));

    expect($plan)->toBeInstanceOf(TranscodePlan::class);
    if (! $plan instanceof TranscodePlan) {
        throw new RuntimeException($plan->message);
    }
    expect(implode(' ', $plan->arguments))->toContain('-map 0:2 -c:1 '.$codec->encoder(), '-ac:1 '.$count.' -b:1 '.$bitrate);
    expect(implode(' ', $plan->arguments))->not->toContain('-map 0:1');
})->with([
    [AudioCodec::AAC, AudioChannels::STEREO, '128k', '2'], [AudioCodec::OPUS, AudioChannels::PRESERVE, '384k', '6'],
]);

it('keeps forced subtitles independently and applies language filters to ordinary subtitles', function (): void {
    planEncoders();
    $file = planningFile([
        ['stream_index' => 1, 'codec_type' => 'subtitle', 'codec_name' => 'subrip', 'language' => 'ita', 'is_forced' => true],
        ['stream_index' => 2, 'codec_type' => 'subtitle', 'codec_name' => 'ass', 'language' => 'ita'],
        ['stream_index' => 3, 'codec_type' => 'subtitle', 'codec_name' => 'ass', 'language' => 'eng'],
    ]);

    $plan = app(BuildTranscodePlan::class)->handle($file, new MediaSettings(subtitleLanguages: ['eng']));

    expect($plan)->toBeInstanceOf(TranscodePlan::class);
    if (! $plan instanceof TranscodePlan) {
        throw new RuntimeException($plan->message);
    }
    expect(implode(' ', $plan->arguments))->toContain('-map 0:1', '-disposition:1 forced', '-map 0:3');
    expect(implode(' ', $plan->arguments))->not->toContain('-map 0:2');
});

it('selects available hardware and falls back to the same codec software encoder', function (string $preference, TargetVideoCodec $codec, VideoEncoder $expected): void {
    planEncoders(['libx265', 'libsvtav1', 'hevc_nvenc', 'hevc_qsv', 'hevc_vaapi']);
    app(AccelerationPreference::class)->save($preference);
    $file = planningFile();

    $plan = app(BuildTranscodePlan::class)->handle($file, new MediaSettings(videoCodec: $codec));

    expect($plan)->toBeInstanceOf(TranscodePlan::class);
    if (! $plan instanceof TranscodePlan) {
        throw new RuntimeException($plan->message);
    }
    expect($plan->encoder)->toBe($expected);
    if ($expected === VideoEncoder::HEVC_VAAPI) {
        expect(implode(' ', $plan->arguments))->toContain('-init_hw_device vaapi=hw -filter_hw_device hw', '-filter:v:0 scale=iw:ih:flags=bitexact,format=nv12,hwupload');
    }
})->with([
    ['auto', TargetVideoCodec::HEVC, VideoEncoder::HEVC_NVENC], ['qsv', TargetVideoCodec::HEVC, VideoEncoder::HEVC_QSV],
    ['vaapi', TargetVideoCodec::HEVC, VideoEncoder::HEVC_VAAPI], ['software', TargetVideoCodec::HEVC, VideoEncoder::LIBX265],
    ['nvenc', TargetVideoCodec::AV1, VideoEncoder::LIBSVTAV1], ['amf', TargetVideoCodec::AV1, VideoEncoder::LIBSVTAV1],
]);

it('fails when a required video or converted audio encoder is missing', function (array $encoders, MediaSettings $settings, string $missing): void {
    planEncoders(array_values($encoders));
    $file = planningFile([['stream_index' => 1, 'codec_type' => 'audio', 'codec_name' => 'ac3', 'channels' => 6]]);

    expect(fn () => app(BuildTranscodePlan::class)->handle($file, $settings))->toThrow(RuntimeException::class, $missing);
})->with([
    [[], new MediaSettings, 'libx265'], [['libx265'], new MediaSettings(audioCodec: AudioCodec::OPUS), 'libopus'],
]);

it('skips excluded or unsafe streams with an explicit reason', function (array $streams, array $video, array $raw, MediaSettings $settings, string $reason): void {
    planEncoders();
    $file = planningFile(array_values($streams), $video, $raw);

    $plan = app(BuildTranscodePlan::class)->handle($file, $settings);

    expect($plan)->toBeInstanceOf(SkippedTranscode::class);
    if (! $plan instanceof SkippedTranscode) {
        throw new RuntimeException('Expected a skipped transcode.');
    }
    expect($plan->reason)->toBe($reason);
})->with([
    'excluded codec' => [[], ['codec_name' => 'hevc'], [], new MediaSettings, 'video_codec_policy'],
    'unknown pixel format' => [[], ['pixel_format' => 'rgb48le'], [], new MediaSettings, 'unsupported_video_format'],
    'interlaced video' => [[], [], ['field_order' => 'tt'], new MediaSettings, 'unsupported_video_format'],
    'unsupported AV1 chroma' => [[], ['pixel_format' => 'yuv444p'], [], new MediaSettings(videoCodec: TargetVideoCodec::AV1), 'unsupported_video_format'],
    'data track' => [[['stream_index' => 1, 'codec_type' => 'data', 'codec_name' => 'bin_data']], [], [], new MediaSettings, 'unsupported_stream'],
    'second video' => [[['stream_index' => 1, 'codec_type' => 'video', 'codec_name' => 'h264']], [], [], new MediaSettings, 'unsupported_video_streams'],
    'excluded all audio' => [[['stream_index' => 1, 'codec_type' => 'audio', 'codec_name' => 'flac', 'language' => 'ita']], [], [], new MediaSettings(audioLanguages: ['eng']), 'no_audio_matches'],
    'unknown converted channels' => [[['stream_index' => 1, 'codec_type' => 'audio', 'codec_name' => 'aac']], [], [], new MediaSettings(audioCodec: AudioCodec::AAC), 'unknown_audio_channels'],
    'HDR policy' => [[], ['pixel_format' => 'yuv420p10le', 'color_transfer' => 'smpte2084'], [], new MediaSettings(hdrMode: HdrMode::SKIP), 'hdr_policy'],
    'dynamic HDR' => [[], ['color_transfer' => 'smpte2084'], ['side_data_list' => [['side_data_type' => 'DOVI configuration record']]], new MediaSettings, 'unsupported_dynamic_hdr'],
]);

it('requires current probe and unchanged source files', function (string $change, string $reason): void {
    planEncoders();
    $file = planningFile();
    if ($change === 'probe') {
        $file->probe()->update(['status' => TaskStatus::PENDING]);
    } elseif ($change === 'missing') {
        unlink($file->libraryPath()->firstOrFail()->path.'/source.mkv');
    } else {
        file_put_contents($file->libraryPath()->firstOrFail()->path.'/source.mkv', 'changed source data');
    }

    $plan = app(BuildTranscodePlan::class)->handle($file->refresh(), new MediaSettings);

    expect($plan)->toBeInstanceOf(SkippedTranscode::class);
    if (! $plan instanceof SkippedTranscode) {
        throw new RuntimeException('Expected a skipped transcode.');
    }
    expect($plan->reason)->toBe($reason);
})->with([['probe', 'stale_probe'], ['missing', 'source_unavailable'], ['changed', 'source_changed']]);

it('preserves HLG color and uses software without changing bit depth', function (): void {
    planEncoders(['libx265', 'hevc_nvenc']);
    $file = planningFile(video: ['pixel_format' => 'yuv420p10le', 'color_transfer' => 'arib-std-b67', 'color_primaries' => 'bt2020', 'color_space' => 'bt2020nc']);

    $plan = app(BuildTranscodePlan::class)->handle($file, new MediaSettings);

    expect($plan)->toBeInstanceOf(TranscodePlan::class);
    if (! $plan instanceof TranscodePlan) {
        throw new RuntimeException($plan->message);
    }
    expect($plan->encoder)->toBe(VideoEncoder::LIBX265);
    expect(implode(' ', $plan->arguments))->toContain('-color_trc:v:0 arib-std-b67', '-color_primaries:v:0 bt2020', '-pix_fmt:v:0 +yuv420p10le');
});

it('tone maps PQ with Hable, removes HDR side data and writes SDR color tags', function (): void {
    planEncoders();
    $file = planningFile(video: ['pixel_format' => 'yuv420p10le', 'color_transfer' => 'smpte2084', 'color_primaries' => 'bt2020', 'color_space' => 'bt2020nc']);

    $plan = app(BuildTranscodePlan::class)->handle($file, new MediaSettings(hdrMode: HdrMode::TONE_MAP));

    expect($plan)->toBeInstanceOf(TranscodePlan::class);
    if (! $plan instanceof TranscodePlan) {
        throw new RuntimeException($plan->message);
    }
    expect(implode(' ', $plan->arguments))->toContain('zscale=t=linear:npl=100', 'tonemap=tonemap=hable', 'sidedata=mode=delete:type=MASTERING_DISPLAY_METADATA', '-color_trc:v:0 bt709', '-color_range:v:0 tv');
    expect(implode(' ', $plan->arguments))->not->toContain('-color_trc:v:0 smpte2084');
});

it('converts mastering display rational values into x265 signaling', function (): void {
    planEncoders();
    $mastering = ['side_data_type' => 'Mastering display metadata', 'red_x' => '34000/50000', 'red_y' => '16000/50000',
        'green_x' => '13250/50000', 'green_y' => '34500/50000', 'blue_x' => '7500/50000', 'blue_y' => '3000/50000',
        'white_point_x' => '15635/50000', 'white_point_y' => '16450/50000', 'min_luminance' => '50/10000', 'max_luminance' => '10000000/10000'];
    $file = planningFile(video: ['pixel_format' => 'yuv420p10le', 'color_transfer' => 'smpte2084', 'color_primaries' => 'bt2020', 'color_space' => 'bt2020nc'],
        rawVideo: ['side_data_list' => [$mastering, ['side_data_type' => 'Content light level metadata', 'max_content' => 1000, 'max_average' => 400]]]);

    $plan = app(BuildTranscodePlan::class)->handle($file, new MediaSettings);

    expect($plan)->toBeInstanceOf(TranscodePlan::class);
    if (! $plan instanceof TranscodePlan) {
        throw new RuntimeException($plan->message);
    }
    expect(implode(' ', $plan->arguments))->toContain('-x265-params master-display=G(13250,34500)B(7500,3000)R(34000,16000)WP(15635,16450)L(10000000,50):max-cll=1000,400');
});

it('preserves HDR10 through native SVT metadata forwarding only on supported FFmpeg versions', function (string $version, bool $supported): void {
    planEncoders();
    $detection = app(EncoderDetector::class)->current();
    app(EncoderDetector::class)->store(new Detection($detection->detectedAt, $version, encoders: $detection->encoders));
    $file = planningFile(video: ['pixel_format' => 'yuv420p10le', 'color_transfer' => 'smpte2084', 'color_primaries' => 'bt2020', 'color_space' => 'bt2020nc'],
        rawVideo: ['side_data_list' => [['side_data_type' => 'Mastering display metadata'], ['side_data_type' => 'Content light level metadata', 'max_content' => 1000, 'max_average' => 400]]]);

    $plan = app(BuildTranscodePlan::class)->handle($file, new MediaSettings(videoCodec: TargetVideoCodec::AV1));

    if ($supported) {
        expect($plan)->toBeInstanceOf(TranscodePlan::class);
        if (! $plan instanceof TranscodePlan) {
            throw new RuntimeException($plan->message);
        }
        expect($plan->encoder)->toBe(VideoEncoder::LIBSVTAV1);
        expect(implode(' ', $plan->arguments))->toContain('-color_trc:v:0 smpte2084');
        expect($plan->arguments)->not->toContain('-filter:v:0', '-x265-params');
    } else {
        expect($plan)->toBeInstanceOf(SkippedTranscode::class);
        if (! $plan instanceof SkippedTranscode) {
            throw new RuntimeException('Expected an unsupported mastering skip.');
        }
        expect($plan->reason)->toBe('unsupported_hdr_mastering');
    }
})->with([['7.1', true], ['8.0.1', true], ['6.1', false], ['N-unknown', false]]);

it('keeps copied audio channels even when stereo conversion is configured', function (): void {
    planEncoders();
    $file = planningFile([['stream_index' => 1, 'codec_type' => 'audio', 'codec_name' => 'aac', 'channels' => 6]]);

    $plan = app(BuildTranscodePlan::class)->handle($file, new MediaSettings(audioChannels: AudioChannels::STEREO));

    expect($plan)->toBeInstanceOf(TranscodePlan::class);
    if (! $plan instanceof TranscodePlan) {
        throw new RuntimeException($plan->message);
    }
    expect(implode(' ', $plan->arguments))->toContain('-map 0:1 -c:1 copy');
    expect(implode(' ', $plan->arguments))->not->toContain('-ac:', '-b:1');
});

it('converts lossless audio only when preservation is disabled', function (): void {
    planEncoders();
    $file = planningFile([['stream_index' => 1, 'codec_type' => 'audio', 'codec_name' => 'flac', 'channels' => 6]]);

    $plan = app(BuildTranscodePlan::class)->handle($file, new MediaSettings(audioCodec: AudioCodec::OPUS, preserveLosslessAudio: false));

    expect($plan)->toBeInstanceOf(TranscodePlan::class);
    if (! $plan instanceof TranscodePlan) {
        throw new RuntimeException($plan->message);
    }
    expect(implode(' ', $plan->arguments))->toContain('-c:1 libopus', '-ac:1 6 -b:1 384k');
});

it('drops every subtitle when both retention policies are disabled', function (): void {
    planEncoders();
    $file = planningFile([
        ['stream_index' => 1, 'codec_type' => 'subtitle', 'codec_name' => 'subrip', 'is_forced' => true],
        ['stream_index' => 2, 'codec_type' => 'subtitle', 'codec_name' => 'ass'],
    ]);

    $plan = app(BuildTranscodePlan::class)->handle($file, new MediaSettings(retainSubtitles: false, preserveForcedSubtitles: false));

    expect($plan)->toBeInstanceOf(TranscodePlan::class);
    if (! $plan instanceof TranscodePlan) {
        throw new RuntimeException($plan->message);
    }
    expect(implode(' ', $plan->arguments))->not->toContain('-map 0:1', '-map 0:2');
});

it('places the substantive video first and preserves attached pictures and all dispositions', function (): void {
    planEncoders();
    $file = planningFile([
        ['stream_index' => 0, 'codec_type' => 'video', 'codec_name' => 'mjpeg', 'is_attached_picture' => true],
        ['stream_index' => 1, 'codec_type' => 'audio', 'codec_name' => 'aac', 'channels' => 2],
    ], ['stream_index' => 3], ['index' => 3, 'disposition' => ['default' => 1, 'original' => 1, 'comment' => 0]]);

    $plan = app(BuildTranscodePlan::class)->handle($file, new MediaSettings);

    expect($plan)->toBeInstanceOf(TranscodePlan::class);
    if (! $plan instanceof TranscodePlan) {
        throw new RuntimeException($plan->message);
    }
    expect($plan->streamDecisions[0])->toBe(['index' => 3, 'type' => 'video', 'action' => 'encode']);
    expect(implode(' ', $plan->arguments))->toContain('-map 0:3 -c:0 libx265', '-disposition:0 default+original', '-map 0:0 -c:1 copy', '-disposition:1 attached_pic');
});

it('requests hardware-compatible buffers without reducing ten-bit SDR video', function (string $backend, string $pixelFormat): void {
    planEncoders(['libx265', 'hevc_qsv', 'hevc_nvenc', 'hevc_vaapi']);
    app(AccelerationPreference::class)->save($backend);
    $file = planningFile(video: ['pixel_format' => 'yuv420p10le']);

    $plan = app(BuildTranscodePlan::class)->handle($file, new MediaSettings);

    expect($plan)->toBeInstanceOf(TranscodePlan::class);
    if (! $plan instanceof TranscodePlan) {
        throw new RuntimeException($plan->message);
    }
    expect(implode(' ', $plan->arguments))->toContain('-pix_fmt:v:0 +'.$pixelFormat);
    if ($backend === 'vaapi') {
        expect(implode(' ', $plan->arguments))->toContain('format=p010le,hwupload');
    }
})->with([['qsv', 'p010le'], ['nvenc', 'p010le'], ['vaapi', 'vaapi']]);

it('drops forced subtitles too when subtitle retention is disabled', function (): void {
    planEncoders();
    $file = planningFile([
        ['stream_index' => 1, 'codec_type' => 'subtitle', 'codec_name' => 'subrip', 'is_forced' => true],
    ]);
    $plan = app(BuildTranscodePlan::class)->handle($file, new MediaSettings(retainSubtitles: false));
    expect($plan)->toBeInstanceOf(TranscodePlan::class);
    if (! $plan instanceof TranscodePlan) {
        throw new RuntimeException('The source must be planned.');
    }
    expect(implode(' ', $plan->arguments))->not->toContain('-map 0:1');
});

it('forbids FFmpeg silently substituting a lower bit depth or chroma format', function (): void {
    planEncoders();
    $file = planningFile(video: ['pixel_format' => 'yuv444p12le']);
    $plan = app(BuildTranscodePlan::class)->handle($file, new MediaSettings);
    expect($plan)->toBeInstanceOf(TranscodePlan::class);
    if (! $plan instanceof TranscodePlan) {
        throw new RuntimeException('The source must be planned.');
    }
    expect(implode(' ', $plan->arguments))->toContain('-pix_fmt:v:0 +yuv444p12le');
});
