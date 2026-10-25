<?php

use App\Encoding\Data\MediaSettings;
use App\Encoding\Enums\VideoEncoder;
use App\Transcoding\Actions\OptimizeTranscode;
use App\Transcoding\OptimizationPolicy;
use App\Transcoding\TranscodePlan;
use Carbon\CarbonImmutable;
use Symfony\Component\Process\Process;

it('searches real container encodes and confirms the selected quality before a full encode', function (string $scenario): void {
    $directory = mediaDirectory([]);
    $source = $directory.'/source.mkv';
    $pixel = $scenario === 'hdr' ? 'yuv420p10le' : 'yuv420p';
    $filter = 'testsrc2=size=64x64:rate=8:duration=2,format='.$pixel;
    $filter .= match ($scenario) {
        'variable' => ',setpts=PTS+mod(N\\,2)*0.02',
        'offset' => ',setpts=PTS+80',
        'hdr' => ',setparams=color_primaries=bt2020:color_trc=smpte2084:colorspace=bt2020nc:range=limited',
        default => '',
    };
    (new Process(['ffmpeg', '-v', 'error', '-f', 'lavfi', '-i', $filter, '-fps_mode', 'passthrough', '-c:v', 'ffv1', $source], timeout: 30))->mustRun();
    $size = filesize($source);
    $mtime = filemtime($source);
    if ($size === false || $mtime === false) {
        throw new RuntimeException('Fixture is unavailable.');
    }
    $encoder = $scenario === 'av1' ? VideoEncoder::LIBSVTAV1 : VideoEncoder::LIBX265;
    $settings = new MediaSettings(videoCodec: $encoder->targetCodec(), minimumVmaf: 70);
    $prefix = ['-nostdin', '-i', $source, '-map', '0:0', '-c:0', $encoder->value];
    $codecOptions = $scenario === 'av1' ? ['-preset', '8', '-svtav1-params', 'lp=1'] : ['-preset', 'ultrafast', '-x265-params', 'pools=1:frame-threads=1:log-level=error'];
    $video = [...$codecOptions, '-pix_fmt:v:0', '+'.$pixel, '-fps_mode:v:0', 'passthrough', '-enc_time_base:v:0', 'demux', '-f', 'matroska'];
    $plan = new TranscodePlan($encoder, $settings, $source, $size, CarbonImmutable::createFromTimestamp($mtime),
        [...$prefix, '-crf', '20', ...$video], [['index' => 0, 'type' => 'video', 'action' => 'encode']], $prefix, ['-nostdin'], $video);
    $quality = ['mode' => 'sampled', 'positions' => [0.15, 0.5, 0.85], 'sample_seconds' => 30, 'model_path' => config('quality_control.model_path'),
        'width' => 320, 'height' => 180, 'duration_tolerance_seconds' => 0.5, 'media' => $settings->toArray(), 'threads' => 1];
    $audit = [];
    $result = app(OptimizeTranscode::class)->handle([$plan], app(OptimizationPolicy::class)->snapshot($settings), $quality,
        $directory.'/preparation', microtime(true) + 120, fn (): bool => false, function (array $update) use (&$audit): void {
            $audit = array_replace($audit, $update);
        });
    if (! $result instanceof TranscodePlan) {
        throw new RuntimeException($result->message);
    }
    expect($result)->toBeInstanceOf(TranscodePlan::class)
        ->and($result->quality)->not->toBeNull()
        ->and($audit['optimization']['winner']['confirmed'])->toBeTrue()
        ->and(count($audit['optimization']['candidates']))->toBeGreaterThan(1);
})->with(['sdr', 'variable', 'offset', 'hdr', 'av1'])->skip(fn (): bool => getenv('QUALITY_CONTROL_NATIVE_TESTS') !== '1', 'Run inside the production image.');
