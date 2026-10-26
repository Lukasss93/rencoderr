<?php

use App\Encoding\Data\MediaSettings;
use App\Encoding\Enums\VideoEncoder;
use App\Ffmpeg\Enums\HardwareBackend;
use App\Ffmpeg\HardwareDecoding;
use App\Transcoding\OptimizationMedia;
use App\Transcoding\OptimizationSamples;
use App\Transcoding\TranscodePlan;

it('uses hardware decoding for extraction and explicitly preserves the lossless reference format', function (): void {
    $directory = mediaDirectory([]);
    $decoding = new HardwareDecoding(HardwareBackend::VAAPI, '/dev/dri/renderD129');
    $plan = new TranscodePlan(VideoEncoder::HEVC_VAAPI, new MediaSettings, '/source.mp4', 1000, now()->toImmutable(), [], [], decoding: $decoding);
    $media = Mockery::mock(OptimizationMedia::class);
    $media->shouldReceive('binary')->andReturn('/ffmpeg');
    $media->shouldReceive('execute')->once()->withArgs(function (array $command): bool {
        $input = array_search('/source.mp4', $command, true);
        expect($input)->not->toBeFalse();
        if (! is_int($input)) {
            throw new RuntimeException('Missing reference input');
        }
        expect(array_slice($command, 0, $input))->toContain('-hwaccel:3', 'p010le', 'vaapi=decode:/dev/dri/renderD129')
            ->and(implode(' ', array_slice($command, $input + 1)))->toContain('-c:v ffv1', '-filter:v:0 scale=iw:ih:flags=bitexact,format=yuv420p10le', '-pix_fmt +yuv420p10le', '-fps_mode passthrough');

        return true;
    });
    $media->shouldReceive('probe')->once()->andReturn(['streams' => [['index' => 0, 'avg_frame_rate' => '24/1']]]);
    $media->shouldReceive('execute')->once()->withArgs(function (array $command, Closure $consume): bool {
        expect($command)->toContain('-show_frames');
        expect(in_array('-hwaccel:0', $command, true))->toBeFalse();
        $consume("frames.frame.0.best_effort_timestamp_time=\"0\"\nframes.frame.0.duration_time=\"0.041666667\"\n");

        return true;
    });
    app()->instance(OptimizationMedia::class, $media);
    $samples = app(OptimizationSamples::class)->prepare(app(OptimizationMedia::class), $plan,
        ['index' => 3, 'codec_name' => 'hevc', 'pix_fmt' => 'yuv420p10le'], [['start' => 0.0, 'end' => 0.041666667]], $directory);
    expect($samples)->toHaveCount(1)->and($samples[0]['timeline']->duration())->toEqualWithDelta(1 / 24, 0.000001);
});
