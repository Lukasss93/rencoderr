<?php

use App\QualityControl\SampleWindows;

it('samples three thirty-second windows at the configured positions', function (): void {
    expect((new SampleWindows)->forDuration(1000, 'sampled', [0.15, 0.50, 0.85], 30))->toBe([
        ['start' => 150.0, 'end' => 180.0], ['start' => 500.0, 'end' => 530.0], ['start' => 850.0, 'end' => 880.0],
    ]);
});

it('uses the full video when sampling would cover its entire duration', function (): void {
    expect((new SampleWindows)->forDuration(75, 'sampled', [0.15, 0.50, 0.85], 30))->toBe([['start' => 0.0, 'end' => 75.0]]);
});

it('merges overlapping windows and clamps the last window to the video end', function (): void {
    expect((new SampleWindows)->forDuration(1000, 'sampled', [0.5, 0.51, 1.0], 30))->toBe([
        ['start' => 500.0, 'end' => 540.0], ['start' => 970.0, 'end' => 1000.0],
    ]);
});

it('supports explicit full-video scoring', function (): void {
    expect((new SampleWindows)->forDuration(3600, 'full', [0.5], 30))->toBe([['start' => 0.0, 'end' => 3600.0]]);
});

it('rejects invalid measurement configuration', function (float $duration, string $mode, array $positions, float $seconds): void {
    expect(fn () => (new SampleWindows)->forDuration($duration, $mode, $positions, $seconds))->toThrow(InvalidArgumentException::class);
})->with([[0.0, 'sampled', [0.5], 30.0], [100.0, 'unknown', [0.5], 30.0], [100.0, 'sampled', [], 30.0],
    [100.0, 'sampled', [-0.1], 30.0], [100.0, 'sampled', [1.1], 30.0], [100.0, 'sampled', [0.5], 0.0]]);
