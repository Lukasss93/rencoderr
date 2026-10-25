<?php

use App\Encoding\Data\MediaSettings;
use App\QualityControl\SampleScorer;

it('accelerates only supported comparison inputs and keeps frame selection and the model unchanged', function (): void {
    $reference = ['index' => 0, 'codec_name' => 'ffv1', 'pix_fmt' => 'yuv420p10le'];
    $output = ['index' => 2, 'codec_name' => 'hevc', 'pix_fmt' => 'yuv420p10le'];
    $command = (new SampleScorer)->comparison('/ffmpeg', ['source' => '/reference.mkv', 'output' => '/candidate.mkv'], [], [],
        ['source_video' => $reference, 'output_video' => $output, 'hdr_view' => 'sdr'], new MediaSettings,
        ['width' => 1920, 'height' => 1080, 'decoding' => ['backend' => 'vaapi', 'device' => '/dev/dri/renderD129']],
        ['source_start' => 1.0, 'source_end' => 2.0, 'output_start' => 1.0, 'output_end' => 2.0], 2, 'segment.xml');
    $first = array_search('/reference.mkv', $command, true);
    $second = array_search('/candidate.mkv', $command, true);
    expect($first)->not->toBeFalse()->and($second)->not->toBeFalse();
    if ($first === false || $second === false) {
        throw new RuntimeException('Missing comparison inputs');
    }
    expect(array_slice($command, 0, $first))->not->toContain('-hwaccel:0')
        ->and(array_slice($command, $first + 1, $second - $first - 1))->toContain('-hwaccel:2', 'p010le')
        ->and(implode(' ', $command))->toContain('trim=start=1.000000000:end=2.000000000', 'libvmaf=model=path=model.json', 'n_subsample=1', 'repeatlast=0');
});
