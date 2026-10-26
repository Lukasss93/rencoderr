<?php

use App\Encoding\Data\MediaSettings;
use App\Encoding\Enums\VideoEncoder;
use App\Ffmpeg\MediaProcessInterrupted;
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

/** @return array{TranscodePlan, array<string, mixed>, array<string, mixed>, string} */
function optimizationFixture(Closure $measure, bool $audio = false, VideoEncoder $encoder = VideoEncoder::HEVC_VAAPI): array
{
    $directory = mediaDirectory(['source.mkv' => str_repeat('x', 1000000), 'model.json' => '{}']);
    $settings = new MediaSettings;
    $prefix = ['-i', $directory.'/source.mkv', '-c:0', $encoder->value];
    $decisions = [['index' => 0, 'type' => 'video', 'action' => 'encode']];
    if ($audio) {
        $prefix = [...$prefix, '-b:1', '128k'];
        $decisions[] = ['index' => 1, 'type' => 'audio', 'action' => 'encode'];
    }
    $plan = new TranscodePlan($encoder, $settings, $directory.'/source.mkv', 1000000,
        now()->toImmutable(), $prefix, $decisions, $prefix, [], []);
    $media = Mockery::mock(OptimizationMedia::class);
    $media->shouldReceive('configure')->once();
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

    return [$plan, app(OptimizationPolicy::class)->snapshot($settings), $quality, $directory.'/preparation'];
}

it('optimizes the selected encoder without switching hardware or software', function (VideoEncoder $encoder): void {
    $seen = [];
    [$plan, $policy, $quality, $directory] = optimizationFixture(function ($media, $candidate) use (&$seen): array {
        $seen[] = $candidate->encoder;

        return ['video_bytes' => 400000, 'minimum_vmaf' => 97, 'windows' => [], 'dispersion' => 1.0, 'encode_seconds' => 0.1];
    }, encoder: $encoder);
    $policy['maximum_candidates'] = 1;
    $audit = [];

    $result = app(OptimizeTranscode::class)->handle($plan, $policy, $quality, $directory,
        microtime(true) + 60, fn (): bool => false, function (array $update) use (&$audit): void {
            $audit = array_replace($audit, $update);
        });

    expect($result)->toBeInstanceOf(TranscodePlan::class);
    if (! $result instanceof TranscodePlan) {
        throw new RuntimeException($result->message);
    }
    expect($result->encoder)->toBe($encoder);
    expect($seen)->toBe([$encoder]);
    expect($audit['optimization']['winner']['bytes'])->toBe(450001);
})->with([VideoEncoder::HEVC_VAAPI, VideoEncoder::LIBX265]);

it('retains a passing candidate when later sampling fails quality or hits a limit', function (string $failure): void {
    $calls = 0;
    [$plan, $policy, $quality, $directory] = optimizationFixture(function () use (&$calls, $failure): array {
        if (++$calls > 1 && $failure === 'limit') {
            throw new OptimizationLimit('Budget expired');
        }

        return ['video_bytes' => $calls === 1 ? 700000 : 400000, 'minimum_vmaf' => $calls === 1 ? 97 : 80,
            'windows' => [], 'dispersion' => 1.0, 'encode_seconds' => 0.1];
    });
    $policy['maximum_candidates'] = 2;
    $audit = [];

    $result = app(OptimizeTranscode::class)->handle($plan, $policy, $quality, $directory,
        microtime(true) + 60, fn (): bool => false, function (array $update) use (&$audit): void {
            $audit = array_replace($audit, $update);
        });

    expect($result)->toBeInstanceOf(TranscodePlan::class);
    if (! $result instanceof TranscodePlan) {
        throw new RuntimeException($result->message);
    }
    expect($result->quality?->value)->toBe(28);
    expect($audit['optimization']['winner']['quality'])->toBe(28);
    expect($calls)->toBe(2);
})->with(['quality', 'limit']);

it('reserves encoded audio for its complete packet span rather than video duration', function (): void {
    [$plan, $policy, $quality, $directory] = optimizationFixture(function (): array {
        throw new RuntimeException('Sampling must not start');
    }, audio: true);
    $audit = [];
    $result = app(OptimizeTranscode::class)->handle($plan, $policy, $quality, $directory,
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

it('selects the sampled winner without reencoding candidates for confirmation', function (): void {
    $seen = [];
    [$plan, $policy, $quality, $directory] = optimizationFixture(function ($media, $plan) use (&$seen): array {
        $value = $plan->quality->value;
        $seen[] = $value;
        $score = 100 - $value * 0.1;

        return ['video_bytes' => 1000000 - $value * 10000, 'minimum_vmaf' => $score, 'windows' => [], 'dispersion' => 1.0, 'encode_seconds' => 0.1];
    });
    $audit = [];
    $result = app(OptimizeTranscode::class)->handle($plan, $policy, $quality, $directory,
        microtime(true) + 60, fn (): bool => false, function (array $update) use (&$audit): void {
            $audit = array_replace($audit, $update);
        });
    expect($seen[0])->toBe(28)->and($seen)->not->toContain(51)
        ->and(count($audit['optimization']['candidates']))->toBeLessThanOrEqual(8)
        ->and(count($seen))->toBe(count(array_unique($seen)))
        ->and($audit['optimization']['winner']['quality'])->toBe(40);
    expect($result)->toBeInstanceOf(TranscodePlan::class);
});

it('investigates stronger compression when a quality pass is oversized and skips unqualified results', function (): void {
    $seen = [];
    [$plan, $policy, $quality, $directory] = optimizationFixture(function ($media, $plan) use (&$seen): array {
        $value = $plan->quality->value;
        $seen[] = $value;

        return ['video_bytes' => 950000, 'minimum_vmaf' => $value <= 35 ? 97.0 : 80.0, 'windows' => [], 'dispersion' => 1.0, 'encode_seconds' => 0.1];
    });
    $result = app(OptimizeTranscode::class)->handle($plan, $policy, $quality, $directory,
        microtime(true) + 60, fn (): bool => false, fn () => null);
    expect($seen[0])->toBe(28)->and(count($seen))->toBeLessThanOrEqual(8)
        ->and($seen[1])->toBeGreaterThan($seen[0])->and($result)->toBeInstanceOf(SkippedTranscode::class);
});

it('reports the closest measured candidate when adjacent quality and size limits do not overlap', function (): void {
    [$plan, $policy, $quality, $directory] = optimizationFixture(fn ($media, $plan): array => match ($plan->quality->value) {
        28 => ['video_bytes' => 600000, 'minimum_vmaf' => 92.0, 'windows' => [], 'dispersion' => 1.0, 'encode_seconds' => 0.1],
        26 => ['video_bytes' => 800000, 'minimum_vmaf' => 95.0, 'windows' => [], 'dispersion' => 1.0, 'encode_seconds' => 0.1],
        25 => ['video_bytes' => 900000, 'minimum_vmaf' => 95.5, 'windows' => [], 'dispersion' => 1.0, 'encode_seconds' => 0.1],
        default => throw new RuntimeException('Unnecessary distant probe'),
    });
    $audit = [];
    $result = app(OptimizeTranscode::class)->handle($plan, $policy, $quality, $directory,
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

it('skips without a winner when sampling reaches a resource limit', function (string $message): void {
    [$plan, $policy, $quality, $directory] = optimizationFixture(function () use ($message): never {
        throw new OptimizationLimit($message);
    });
    $audit = [];

    $result = app(OptimizeTranscode::class)->handle($plan, $policy, $quality, $directory,
        microtime(true) + 60, fn (): bool => false, function (array $update) use (&$audit): void {
            $audit = array_replace($audit, $update);
        });

    expect($result)->toBeInstanceOf(SkippedTranscode::class);
    if (! $result instanceof SkippedTranscode) {
        throw new RuntimeException('Expected a resource limit to prevent encoding');
    }
    expect($result->reason)->toBe('optimization_inconclusive');
    expect($audit['optimization']['limits'])->toContain($message);
})->with(['The preparation time budget was exhausted.', 'The preparation temporary storage budget was exhausted.']);

it('propagates cancellation during sampling', function (): void {
    [$plan, $policy, $quality, $directory] = optimizationFixture(function (): never {
        throw new MediaProcessInterrupted('Optimization was cancelled.');
    });

    expect(fn () => app(OptimizeTranscode::class)->handle($plan, $policy, $quality, $directory,
        microtime(true) + 60, fn (): bool => false, fn () => null))->toThrow(MediaProcessInterrupted::class);
});

it('rejects a source changed during sampling', function (): void {
    [$plan, $policy, $quality, $directory] = optimizationFixture(function ($media, $candidate): array {
        file_put_contents($candidate->sourcePath, 'changed source');

        return ['video_bytes' => 400000, 'minimum_vmaf' => 97, 'windows' => [], 'dispersion' => 1.0, 'encode_seconds' => 0.1];
    });
    $policy['maximum_candidates'] = 1;

    expect(fn () => app(OptimizeTranscode::class)->handle($plan, $policy, $quality, $directory,
        microtime(true) + 60, fn (): bool => false, fn () => null))->toThrow(RuntimeException::class, 'The source changed during optimization.');
});
