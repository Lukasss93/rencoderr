<?php

use App\Probe\Exceptions\MalformedProbeResult;
use App\Probe\MediaProber;
use FFMpeg\Driver\FFProbeDriver;

it('extracts all tracks and keeps the original report without losing uncommon metadata', function (): void {
    $report = [
        'format' => ['format_name' => 'matroska,webm', 'duration' => '120.125', 'bit_rate' => '4200000'],
        'streams' => [
            ['index' => 0, 'codec_type' => 'video', 'codec_name' => 'hevc', 'profile' => 'Main 10', 'width' => 3840, 'height' => 2160,
                'pix_fmt' => 'yuv420p10le', 'avg_frame_rate' => '24000/1001', 'r_frame_rate' => '24000/1001',
                'color_transfer' => 'smpte2084', 'side_data_list' => [['side_data_type' => 'Mastering display metadata']],
                'disposition' => ['default' => 1, 'attached_pic' => 0]],
            ['index' => 2, 'codec_type' => 'audio', 'codec_name' => 'aac', 'sample_rate' => '48000', 'channels' => 6,
                'channel_layout' => '5.1', 'tags' => ['language' => 'ita', 'title' => 'Italian']],
            ['index' => 3, 'codec_type' => 'subtitle', 'codec_name' => 'subrip', 'disposition' => ['forced' => 1]],
            ['index' => 4, 'codec_type' => 'attachment', 'codec_name' => 'ttf', 'tags' => ['filename' => 'font.ttf']],
            ['index' => 5, 'codec_type' => 'data', 'codec_name' => 'bin_data'],
        ],
        'chapters' => [['id' => 1, 'start_time' => '0.000000', 'end_time' => '120.125000', 'tags' => ['title' => 'Opening']]],
    ];
    $driver = Mockery::mock(FFProbeDriver::class);
    $driver->shouldReceive('command')->once()->with([
        '-v', 'error', '-print_format', 'json', '-show_format', '-show_streams', '-show_chapters', '/media/a movie.mkv',
    ])->andReturn(json_encode($report, JSON_THROW_ON_ERROR));
    app()->instance(FFProbeDriver::class, $driver);

    $result = app(MediaProber::class)->probe('/media/a movie.mkv');

    expect($result->metadata)->toBe($report);
    expect($result->format)->toBe('matroska,webm');
    expect($result->duration)->toBe('120.125');
    expect($result->bitRate)->toBe(4200000);
    expect($result->streams)->toHaveCount(5);
    expect($result->streams[0])->toMatchArray(['stream_index' => 0, 'codec_name' => 'hevc', 'width' => 3840, 'average_frame_rate' => '24000/1001', 'color_transfer' => 'smpte2084', 'is_default' => true]);
    expect($result->streams[1])->toMatchArray(['stream_index' => 2, 'sample_rate' => 48000, 'channels' => 6, 'language' => 'ita', 'title' => 'Italian']);
    expect($result->streams[2])->toMatchArray(['is_forced' => true, 'width' => null]);
});

it('leaves unknown optional values null and accepts audio without video', function (): void {
    $driver = Mockery::mock(FFProbeDriver::class);
    $driver->shouldReceive('command')->andReturn('{"format":{"duration":"N/A"},"streams":[{"index":0,"codec_type":"audio","sample_rate":"N/A","avg_frame_rate":"0/0"}]}');
    app()->instance(FFProbeDriver::class, $driver);

    $result = app(MediaProber::class)->probe('/media/file.mkv');

    expect($result->duration)->toBeNull();
    expect($result->streams[0])->toMatchArray(['sample_rate' => null, 'average_frame_rate' => null, 'codec_name' => null]);
});

it('rejects malformed reports before they can replace stored metadata', function (string $output): void {
    $driver = Mockery::mock(FFProbeDriver::class);
    $driver->shouldReceive('command')->andReturn($output);
    app()->instance(FFProbeDriver::class, $driver);

    expect(fn () => app(MediaProber::class)->probe('/media/file.mkv'))->toThrow(MalformedProbeResult::class);
})->with([
    'invalid JSON' => ['{'],
    'missing sections' => ['{}'],
    'invalid format' => ['{"format":[],"streams":[]}'],
    'missing stream index' => ['{"format":{},"streams":[{"codec_type":"video"}]}'],
    'duplicate indexes' => ['{"format":{},"streams":[{"index":0},{"index":0}]}'],
]);
