<?php

use App\QualityControl\QualityPolicy;

require_once __DIR__.'/../../Support/QualityControlFixtures.php';

it('allows a one-point segment tolerance without lowering the required overall score', function (float $mean, array $segments, bool $expected): void {
    expect(app(QualityPolicy::class)->passes(['version' => 2, 'segment_tolerance' => 1.0], $mean, array_values($segments), 92))->toBe($expected);
})->with([
    'Descendants measured scores' => [94.384110683157, [95.0240724061, 91.2437022153, 95.9832508331, 93.4695913125, 96.2030465125], true],
    'exact segment boundary' => [92.0, [91.0, 93.0], true],
    'below segment boundary' => [94.0, [90.999, 97.0], false],
    'below overall minimum' => [91.99, [91.5, 92.5], false],
    'above minimum' => [99.0, [99.0, 99.0], true],
    'no measured segments' => [95.0, [], false],
]);

it('uses the saved segment tolerance and keeps older snapshots strict', function (): void {
    $policy = app(QualityPolicy::class);
    expect($policy->passes(['version' => 2], 94, [91.24, 96], 92))->toBeFalse();
    expect($policy->passes(['version' => 2, 'segment_tolerance' => 0.0], 94, [91.99, 96], 92))->toBeFalse();
    expect($policy->passes(['version' => 2, 'segment_tolerance' => 0.5], 94, [91.5, 96], 92))->toBeTrue();
    expect($policy->segmentMinimum(['segment_tolerance' => 1], 0.5))->toBe(0.0);
});

it('freezes the configured tolerance in each new measurement policy', function (): void {
    $transcode = qualityTranscode();
    $transcode->settings()->where('key', 'media.segment_tolerance')->update(['value' => '0.5']);
    $snapshot = app(QualityPolicy::class)->snapshot($transcode);
    $transcode->settings()->where('key', 'media.segment_tolerance')->update(['value' => '0']);
    $transcode->update(['optimization' => ['quality_policy' => $snapshot]]);

    expect($snapshot['segment_tolerance'])->toBe(0.5);
    expect(app(QualityPolicy::class)->snapshot($transcode))->toEqual($snapshot);
    expect(app(QualityPolicy::class)->passes($snapshot, 96, [94.5, 98.0], 95))->toBeTrue();
});

it('can still read saved policies without the new media setting and keeps their strict threshold', function (): void {
    $transcode = qualityTranscode();
    $snapshot = app(QualityPolicy::class)->snapshot($transcode);
    unset($snapshot['segment_tolerance'], $snapshot['media']['segment_tolerance']);
    $transcode->update(['optimization' => ['quality_policy' => $snapshot]]);

    expect(app(QualityPolicy::class)->snapshot($transcode))->toEqual($snapshot);
    expect(app(QualityPolicy::class)->passes($snapshot, 96, [94.5, 98.0], 95))->toBeFalse();
});

it('rejects invalid segment tolerances', function (mixed $tolerance): void {
    expect(fn () => app(QualityPolicy::class)->segmentMinimum(['segment_tolerance' => $tolerance], 92))
        ->toThrow(RuntimeException::class, 'segment tolerance');
})->with([-1.0, 100.01, INF, NAN, 'invalid']);

it('rejects invalid transcode tolerances before saving a new measurement policy', function (string $value): void {
    $transcode = qualityTranscode();
    $transcode->settings()->where('key', 'media.segment_tolerance')->update(['value' => json_encode($value)]);

    expect(fn () => app(QualityPolicy::class)->snapshot($transcode))
        ->toThrow(RuntimeException::class, 'segment tolerance');
})->with(['invalid', '1oops']);
