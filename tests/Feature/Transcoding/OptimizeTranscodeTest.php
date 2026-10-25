<?php

use App\Encoding\Data\MediaSettings;
use App\Encoding\Enums\VideoEncoder;
use App\Transcoding\Actions\OptimizeTranscode;
use App\Transcoding\OptimizationLimit;
use App\Transcoding\OptimizationMedia;
use App\Transcoding\OptimizationPolicy;
use App\Transcoding\OptimizationSamples;
use App\Transcoding\SkippedTranscode;
use App\Transcoding\StagingArea;
use App\Transcoding\TranscodePlan;

it('enforces preparation deadlines before launching a process', function (): void {
    $media = app(OptimizationMedia::class);
    $media->configure(storage_path('framework/testing'), microtime(true) - 1, PHP_INT_MAX, fn (): bool => false, fn () => null);
    expect(fn () => $media->guard())->toThrow(OptimizationLimit::class);
});

it('stops preparation when temporary files exceed the budget', function (): void {
    $directory = mediaDirectory(['reference.mkv' => str_repeat('x', 100)]);
    $media = app(OptimizationMedia::class);
    $media->configure($directory, microtime(true) + 60, 50, fn (): bool => false, fn () => null);
    expect(fn () => $media->guard())->toThrow(OptimizationLimit::class);
});

it('refuses a full encode when staging cannot hold its estimate and reserve', function (): void {
    $directory = mediaDirectory([]);
    expect(fn () => app(StagingArea::class)->ensureSpace($directory, PHP_INT_MAX))->toThrow(RuntimeException::class);
});

/** @return array{non-empty-list<TranscodePlan>, array<string, mixed>, array<string, mixed>, string} */
function optimizationFixture(Closure $measure, bool $audio = false): array
{
    $directory = mediaDirectory(['source.mkv' => str_repeat('x', 1000000), 'model.json' => '{}']);
    $settings = new MediaSettings;
    $plans = [];
    foreach ([VideoEncoder::HEVC_VAAPI, VideoEncoder::LIBX265] as $encoder) {
        $prefix = ['-i', $directory.'/source.mkv', '-c:0', $encoder->value];
        $decisions = [['index' => 0, 'type' => 'video', 'action' => 'encode']];
        if ($audio) {
            $prefix = [...$prefix, '-b:1', '128k'];
            $decisions[] = ['index' => 1, 'type' => 'audio', 'action' => 'encode'];
        }
        $plans[] = new TranscodePlan($encoder, $settings, $directory.'/source.mkv', 1000000,
            now()->toImmutable(), $prefix, $decisions, $prefix, [], []);
    }
    $media = Mockery::mock(OptimizationMedia::class);
    $media->shouldReceive('configure')->once();
    $media->shouldReceive('deadline');
    $media->shouldReceive('binary')->andReturn('/mock/ffmpeg');
    $media->shouldReceive('read')->andReturn('test binary version');
    $media->shouldReceive('probe')->andReturn(['streams' => [['index' => 0, 'duration' => 10]], 'format' => ['duration' => 10]]);
    $media->shouldReceive('inventory')->andReturn([0 => 900000, 1 => 100000]);
    if ($audio) {
        $media->shouldReceive('audioDuration')->once()->with($directory.'/source.mkv', 1)->andReturn(100.0);
    }
    app()->instance(OptimizationMedia::class, $media);
    $samples = Mockery::mock(OptimizationSamples::class);
    $samples->shouldReceive('prepare')->andReturn([]);
    $samples->shouldReceive('measure')->andReturnUsing($measure);
    app()->instance(OptimizationSamples::class, $samples);
    $quality = ['version' => 2, 'width' => 320, 'height' => 180, 'duration_tolerance_seconds' => 0.5,
        'model_path' => $directory.'/model.json', 'media' => $settings->toArray(), 'threads' => 1];

    return [$plans, app(OptimizationPolicy::class)->snapshot($settings), $quality, $directory.'/preparation'];
}

it('compares passing hardware with software and confirms both before choosing storage savings', function (): void {
    $seen = [];
    [$plans, $policy, $quality, $directory] = optimizationFixture(function ($media, $plan) use (&$seen): array {
        $seen[] = $plan->encoder->value;

        return ['video_bytes' => $plan->encoder->value === 'libx265' ? 400000 : 700000, 'minimum_vmaf' => 97, 'windows' => [], 'dispersion' => 1.0, 'encode_seconds' => 0.1];
    });
    $audit = [];
    $result = app(OptimizeTranscode::class)->handle($plans, $policy, $quality, $directory,
        microtime(true) + 60, fn (): bool => false, function (array $update) use (&$audit): void {
            $audit = array_replace($audit, $update);
        });
    expect($result)->toBeInstanceOf(TranscodePlan::class);
    if (! $result instanceof TranscodePlan) {
        throw new RuntimeException($result->message);
    }
    expect($result->encoder)->toBe(VideoEncoder::LIBX265)
        ->and($seen)->toContain('libx265', 'hevc_vaapi')
        ->and($audit['optimization']['winner']['confirmed'])->toBeTrue();
});

it('retains a confirmed hardware winner if software confirmation fails or its deadline expires', function (string $failure): void {
    $seen = [];
    [$plans, $policy, $quality, $directory] = optimizationFixture(function ($media, $plan) use (&$seen, $failure): array {
        $key = $plan->encoder->value.':'.$plan->quality->value;
        $confirmation = isset($seen[$key]);
        $seen[$key] = true;
        $software = $plan->encoder->value === 'libx265';
        if ($software && $failure === 'deadline') {
            throw new OptimizationLimit('Budget expired');
        }

        return ['video_bytes' => $software ? 400000 : 700000, 'minimum_vmaf' => $software && $confirmation ? 80 : 97, 'windows' => [], 'dispersion' => 1.0, 'encode_seconds' => 0.1];
    });
    $result = app(OptimizeTranscode::class)->handle($plans, $policy, $quality, $directory,
        microtime(true) + 60, fn (): bool => false, fn () => null);
    if (! $result instanceof TranscodePlan) {
        throw new RuntimeException($result->message);
    }
    expect($result->encoder)->toBe(VideoEncoder::HEVC_VAAPI);
})->with(['confirmation', 'deadline']);

it('reserves encoded audio for its complete packet span rather than video duration', function (): void {
    [$plans, $policy, $quality, $directory] = optimizationFixture(function (): array {
        throw new RuntimeException('Sampling must not start');
    }, audio: true);
    $audit = [];
    $result = app(OptimizeTranscode::class)->handle($plans, $policy, $quality, $directory,
        microtime(true) + 60, fn (): bool => false, function (array $update) use (&$audit): void {
            $audit = array_replace($audit, $update);
        });
    expect($result)->toBeInstanceOf(SkippedTranscode::class);
    if (! $result instanceof SkippedTranscode) {
        throw new RuntimeException('Expected the stream budget to prevent encoding');
    }
    expect($result->reason)->toBe('retained_streams_exceed_budget')
        ->and($audit['optimization']['retained_non_video_bytes'])->toBe(1760001);
});

it('uses confirmation failures to refine toward higher quality and records the actual winner', function (): void {
    $seen = [];
    [$plans, $policy, $quality, $directory] = optimizationFixture(function ($media, $plan) use (&$seen): array {
        $value = $plan->quality->value;
        $confirming = in_array($value, $seen, true);
        $seen[] = $value;
        $score = $confirming && $value >= 38 ? 94.0 : 100 - $value * 0.1;

        return ['video_bytes' => 1000000 - $value * 10000, 'minimum_vmaf' => $score, 'windows' => [], 'dispersion' => 1.0, 'encode_seconds' => 0.1];
    });
    $audit = [];
    $result = app(OptimizeTranscode::class)->handle([$plans[0]], $policy, $quality, $directory,
        microtime(true) + 60, fn (): bool => false, function (array $update) use (&$audit): void {
            $audit = array_replace($audit, $update);
        });
    expect($seen[0])->toBe(28)->and($seen)->not->toContain(51)
        ->and(count($audit['optimization']['candidates']))->toBeLessThanOrEqual(8)
        ->and($audit['optimization']['winner']['quality'])->toBeLessThan(38)
        ->and(array_column($audit['optimization']['confirmations'], 'passes'))->toContain(false, true);
    expect($result)->toBeInstanceOf(TranscodePlan::class);
});

it('investigates stronger compression when a quality pass is oversized and skips unqualified results', function (): void {
    $seen = [];
    [$plans, $policy, $quality, $directory] = optimizationFixture(function ($media, $plan) use (&$seen): array {
        $value = $plan->quality->value;
        $seen[] = $value;

        return ['video_bytes' => 950000, 'minimum_vmaf' => $value <= 35 ? 97.0 : 80.0, 'windows' => [], 'dispersion' => 1.0, 'encode_seconds' => 0.1];
    });
    $result = app(OptimizeTranscode::class)->handle([$plans[0]], $policy, $quality, $directory,
        microtime(true) + 60, fn (): bool => false, fn () => null);
    expect($seen[0])->toBe(28)->and(count($seen))->toBeLessThanOrEqual(8)
        ->and($seen[1])->toBeGreaterThan($seen[0])->and($result)->toBeInstanceOf(SkippedTranscode::class);
});

it('reports the closest measured candidate when adjacent quality and size limits do not overlap', function (): void {
    [$plans, $policy, $quality, $directory] = optimizationFixture(fn ($media, $plan): array => match ($plan->quality->value) {
        28 => ['video_bytes' => 600000, 'minimum_vmaf' => 92.0, 'windows' => [], 'dispersion' => 1.0, 'encode_seconds' => 0.1],
        26 => ['video_bytes' => 800000, 'minimum_vmaf' => 95.0, 'windows' => [], 'dispersion' => 1.0, 'encode_seconds' => 0.1],
        25 => ['video_bytes' => 900000, 'minimum_vmaf' => 95.5, 'windows' => [], 'dispersion' => 1.0, 'encode_seconds' => 0.1],
        default => throw new RuntimeException('Unnecessary distant probe'),
    });
    $audit = [];
    $result = app(OptimizeTranscode::class)->handle([$plans[0]], $policy, $quality, $directory,
        microtime(true) + 60, fn (): bool => false, function (array $update) use (&$audit): void {
            $audit = array_replace($audit, $update);
        });
    if (! $result instanceof SkippedTranscode) {
        throw new RuntimeException('Expected a measured conflict');
    }
    expect($result->reason)->toBe('quality_size_conflict')
        ->and($audit['optimization']['closest']['quality'])->toBe(26)
        ->and($audit['optimization']['closest']['quality_gap'])->toBe(1.0)
        ->and($audit['optimization']['search_decisions'])->not->toBeEmpty();
});
