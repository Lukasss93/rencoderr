<?php

use App\Encoding\Data\MediaSettings;
use App\Models\MediaTranscode;
use App\QualityControl\QualityPolicy;
use App\Transcoding\OptimizationPolicy;

it('preserves thresholds and bounds preparation without making five percent a target', function (): void {
    $policy = app(OptimizationPolicy::class)->snapshot(new MediaSettings(minimumSavingsPercent: 12, minimumVmaf: 97));
    expect($policy['enabled'])->toBeTrue()
        ->and($policy['quality_floor'])->toBe(98.0)
        ->and($policy['maximum_candidates'])->toBe(8)
        ->and($policy['seconds'])->toBe(1200);
});

it('disables automatic optimization only when a required check is explicitly disabled', function (): void {
    expect(app(OptimizationPolicy::class)->snapshot(new MediaSettings(verifyVideoQuality: false))['enabled'])->toBeFalse()
        ->and(app(OptimizationPolicy::class)->snapshot(new MediaSettings(requireSmallerOutput: false))['enabled'])->toBeFalse();
});

it('persists the optimization audit separately from final quality results', function (): void {
    $item = MediaTranscode::factory()->create(['phase' => 'sampling', 'optimization' => ['candidates' => [['quality' => 32]]]]);
    expect($item->refresh()->optimization)->toBe(['candidates' => [['quality' => 32]]]);
});

it('rejects invalid preparation limits', function (): void {
    config(['transcoding.optimization.maximum_candidates' => 0]);
    expect(fn () => app(OptimizationPolicy::class)->snapshot(new MediaSettings))->toThrow(RuntimeException::class);
});

it('rejects a bad segment even when the overall average passes for new quality policies', function (): void {
    $policy = app(QualityPolicy::class);
    expect($policy->passes(['version' => 2], 98, [99, 94], 95))->toBeFalse()
        ->and($policy->passes([], 98, [99, 94], 95))->toBeTrue();
});

it('freezes the model and execution limit even for direct encodes with video verification', function (): void {
    $directory = mediaDirectory(['model.json' => '{}']);
    config(['quality_control.model_path' => $directory.'/model.json', 'quality_control.timeout_seconds' => 4321]);
    $item = MediaTranscode::factory()->create();
    foreach ((new MediaSettings(requireSmallerOutput: false))->toArray() as $key => $value) {
        $item->settings()->create(['key' => 'media.'.$key, 'value' => $value]);
    }
    $policy = app(QualityPolicy::class)->forEncoding($item);
    expect($policy['model_sha256'])->toBe(hash('sha256', '{}'))
        ->and($policy['execution_limit_seconds'])->toBe(4321);
});

it('freezes configurable encoder starting points and confirmation time reserves', function (): void {
    config(['transcoding.optimization.starting_quality.hevc_vaapi' => 30]);
    $policy = app(OptimizationPolicy::class)->snapshot(new MediaSettings);
    config(['transcoding.optimization.starting_quality.hevc_vaapi' => 26]);
    expect($policy['starting_quality']['hevc_vaapi'])->toBe(30)
        ->and($policy['confirmation_fraction'])->toBe(0.25)->and($policy['strategy'])->toBe('adaptive_quality');
});

it('rejects invalid starting points and unusable confirmation reserves', function (string $key, int|float $value): void {
    config(['transcoding.optimization.'.$key => $value]);
    expect(fn () => app(OptimizationPolicy::class)->snapshot(new MediaSettings))->toThrow(RuntimeException::class);
})->with([['starting_quality.hevc_vaapi', 52], ['confirmation_fraction', 0], ['confirmation_fraction', 1]]);
