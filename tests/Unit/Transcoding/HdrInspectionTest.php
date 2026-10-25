<?php

use App\Transcoding\HdrInspection;

it('detects dynamic HDR from frame side data across split output chunks', function (): void {
    $inspection = new HdrInspection;
    $inspection->push('frames.frame.0.side_data_list.side_data.0.side_data_type="HDR Dyna');
    $inspection->push("mic Metadata SMPTE2094-40 (HDR10+)\"\n");
    expect($inspection->unsupported())->toBeTrue();
});

it('collects static frame metadata and rejects changes later in the source', function (): void {
    $inspection = new HdrInspection;
    $inspection->push("frames.frame.0.best_effort_timestamp=0\nframes.frame.0.side_data_list.side_data.0.side_data_type=\"Content light level metadata\"\nframes.frame.0.side_data_list.side_data.0.max_content=1000\nframes.frame.0.side_data_list.side_data.0.max_average=400\n");
    $inspection->finish();
    expect($inspection->hasFrames())->toBeTrue();
    expect($inspection->sideData())->toBe([['side_data_type' => 'Content light level metadata', 'max_content' => 1000, 'max_average' => 400]]);
    expect($inspection->unsupported())->toBeFalse();
    $inspection->push("frames.frame.1.side_data_list.side_data.0.side_data_type=\"Content light level metadata\"\nframes.frame.1.side_data_list.side_data.0.max_content=2000\nframes.frame.1.side_data_list.side_data.0.max_average=400\n");
    $inspection->finish();
    expect($inspection->unsupported())->toBeTrue();
});
