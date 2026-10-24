<?php

use App\Transcoding\ProgressParser;

it('parses split progress records and clamps the percentage', function (): void {
    $parser = new ProgressParser(10);
    $parser->push('out_time_us=250');
    $parser->push("0000\nprogress=continue\nout_time_us=20000000\nprogress=end\n");

    expect($parser->seconds())->toBe(20.0);
    expect($parser->percent())->toBe(100.0);
});

it('keeps unknown durations indeterminate and ignores malformed timestamps', function (): void {
    $parser = new ProgressParser(null);
    $parser->push("out_time_us=3500000\nout_time_us=N/A\n");

    expect($parser->seconds())->toBe(3.5);
    expect($parser->percent())->toBeNull();
});

it('discards oversized incomplete lines without losing following progress', function (): void {
    $parser = new ProgressParser(10);
    $parser->push(str_repeat('x', 100000));
    $parser->push("\nout_time_us=5000000\n");

    expect($parser->seconds())->toBe(5.0);
    expect($parser->percent())->toBe(50.0);
});
