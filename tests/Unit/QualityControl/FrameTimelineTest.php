<?php

use App\QualityControl\FrameTimeline;
use App\QualityControl\QualityRejected;

/** @param array<array-key, float> $timestamps */
function qualityTimeline(array $timestamps): FrameTimeline
{
    $path = dirname(__DIR__, 3).'/storage/framework/testing/frames-'.bin2hex(random_bytes(8));
    $timeline = new FrameTimeline($path, 0.04);
    $flat = '';
    foreach ($timestamps as $index => $timestamp) {
        $flat .= 'frames.frame.'.$index.'.best_effort_timestamp_time="'.$timestamp."\"\n";
        $flat .= 'frames.frame.'.$index.'.duration_time="0.04"'."\n";
    }
    foreach (str_split($flat, 17) as $chunk) {
        $timeline->push($chunk);
    }
    $timeline->finish();

    return $timeline;
}

it('aligns variable frame timing using whole-video origins and keeps every measured frame', function (): void {
    $source = qualityTimeline([100.0, 100.04, 100.10, 100.18]);
    $output = qualityTimeline([0.0, 0.04, 0.10, 0.18]);
    try {
        expect($source->duration())->toEqualWithDelta(0.22, 0.000001);
        $windows = $source->align($output, [['start' => 0.03, 'end' => 0.15]], 0.002);
        expect($windows[0]['frames'])->toBe(2);
        expect($windows[0]['source_start'])->toEqualWithDelta(100.02, 0.000001);
        expect($windows[0]['output_start'])->toEqualWithDelta(0.02, 0.000001);
        expect($windows[0]['source_end'])->toEqualWithDelta(100.14, 0.000001);
    } finally {
        unlink($source->path);
        unlink($output->path);
    }
});

it('rejects dropped or shifted frames before a VMAF comparison', function (array $timestamps): void {
    $source = qualityTimeline([0.0, 0.04, 0.08, 0.12]);
    $output = qualityTimeline($timestamps);
    try {
        expect(fn () => $source->align($output, [['start' => 0.0, 'end' => 1.0]], 0.002))->toThrow(QualityRejected::class);
    } finally {
        unlink($source->path);
        unlink($output->path);
    }
})->with([[[0.0, 0.04, 0.12]], [[0.0, 0.04, 0.10, 0.12]]]);
