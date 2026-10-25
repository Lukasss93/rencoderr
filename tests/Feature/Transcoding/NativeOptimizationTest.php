<?php

use App\Encoding\Data\MediaSettings;
use App\Encoding\Enums\VideoEncoder;
use App\Transcoding\Actions\OptimizeTranscode;
use App\Transcoding\OptimizationMedia;
use App\Transcoding\OptimizationPolicy;
use App\Transcoding\OptimizationSamples;
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
        ->and(count($audit['optimization']['candidates']))->toBeGreaterThanOrEqual(1)
        ->and($audit['optimization']['candidates'][0]['quality'])->toBe($audit['optimization']['policy']['starting_quality'][$encoder->value])
        ->and($audit['optimization']['policy']['strategy'])->toBe('adaptive_quality');
})->with(['sdr', 'variable', 'offset', 'hdr', 'av1'])->skip(fn (): bool => getenv('QUALITY_CONTROL_NATIVE_TESTS') !== '1', 'Run inside the production image.');

it('converts downloaded GPU surface layouts into lossless planar references without changing pixels', function (string $surface, string $pixel): void {
    $directory = mediaDirectory([]);
    $source = $directory.'/surface.raw';
    (new Process(['ffmpeg', '-v', 'error', '-f', 'lavfi', '-i', 'testsrc2=size=64x64:rate=8:duration=0.5,format='.$surface,
        '-c:v', 'rawvideo', '-f', 'rawvideo', $source], timeout: 20))->mustRun();
    $stat = stat($source);
    if ($stat === false) {
        throw new RuntimeException('Missing surface fixture');
    }
    $plan = new TranscodePlan(VideoEncoder::LIBX265, new MediaSettings, $source, $stat['size'], CarbonImmutable::createFromTimestamp($stat['mtime']), [], []);
    $media = app(OptimizationMedia::class);
    $media->configure($directory, microtime(true) + 30, 20 * 1024 * 1024, fn (): bool => false, fn () => null);
    $inputOptions = ['-f', 'rawvideo', '-pixel_format', $surface, '-video_size', '64x64', '-framerate', '8'];
    $fixtureMedia = Mockery::mock($media);
    $fixtureMedia->shouldReceive('execute')->andReturnUsing(function (array $command, ?Closure $consume = null, bool $strict = true) use ($media, $source, $inputOptions): void {
        $input = array_search($source, $command, true);
        if (is_int($input)) {
            array_splice($command, $input - 1, 0, $inputOptions);
        }
        $media->execute(array_values($command), $consume, $strict);
    });
    app()->instance(OptimizationMedia::class, $fixtureMedia);
    $samples = app(OptimizationSamples::class)->prepare(app(OptimizationMedia::class), $plan,
        ['index' => 0, 'codec_name' => 'rawvideo', 'pix_fmt' => $pixel], [['start' => 0.0, 'end' => 0.5]], $directory, 'reference');
    $hashes = [];
    foreach ([$source, $samples[0]['path']] as $path) {
        $decoded = new Process(['ffmpeg', '-v', 'error', ...($path === $source ? $inputOptions : []), '-i', $path, '-map', '0:v:0', '-pix_fmt', $pixel, '-f', 'rawvideo', 'pipe:1'], timeout: 20);
        $decoded->mustRun();
        $hashes[] = hash('sha256', $decoded->getOutput());
    }
    expect($hashes[1])->toBe($hashes[0])->and($samples[0]['probe']['streams'][0]['pix_fmt'])->toBe($pixel);
})->with([['nv12', 'yuv420p'], ['p010le', 'yuv420p10le']])
    ->skip(fn (): bool => getenv('QUALITY_CONTROL_NATIVE_TESTS') !== '1', 'Run inside the production image.');
