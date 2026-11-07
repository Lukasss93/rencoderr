<?php

use App\Encoding\Data\MediaSettings;
use App\Encoding\Enums\HdrMode;
use App\QualityControl\QualityRejected;
use App\QualityControl\StreamContract;

/** @return array<string, mixed> */
function qualityVideo(string $codec = 'h264'): array
{
    return ['index' => 0, 'codec_type' => 'video', 'codec_name' => $codec, 'width' => 1920, 'height' => 1080,
        'pix_fmt' => 'yuv420p10le', 'sample_aspect_ratio' => '1:1', 'avg_frame_rate' => '24/1',
        'color_primaries' => 'bt709', 'color_transfer' => 'bt709', 'color_space' => 'bt709', 'color_range' => 'tv'];
}

it('checks the encoded video against the saved stream decision and settings', function (): void {
    $result = (new StreamContract)->verify(['streams' => [qualityVideo()]], ['streams' => [qualityVideo('hevc')]],
        [['index' => 0, 'type' => 'video', 'action' => 'encode']], new MediaSettings);

    expect($result['output_video']['codec_name'])->toBe('hevc');
    expect($result['hdr_view'])->toBe('sdr');
});

it('rejects unexpected video changes', function (array $change): void {
    expect(fn () => (new StreamContract)->verify(['streams' => [qualityVideo()]], ['streams' => [array_replace(qualityVideo('hevc'), $change)]],
        [['index' => 0, 'type' => 'video', 'action' => 'encode']], new MediaSettings))->toThrow(QualityRejected::class);
})->with([[['width' => 1280]], [['pix_fmt' => 'yuv420p']], [['codec_name' => 'h264']], [['sample_aspect_ratio' => '4:3']], [['color_transfer' => 'smpte2084']]]);

it('requires retained audio and allows only intentional stream drops', function (): void {
    $source = ['streams' => [qualityVideo(), ['index' => 1, 'codec_type' => 'audio', 'codec_name' => 'aac', 'channels' => 6]]];
    $output = ['streams' => [qualityVideo('hevc')]];
    $video = ['index' => 0, 'type' => 'video', 'action' => 'encode'];

    expect(fn () => (new StreamContract)->verify($source, $output, [$video, ['index' => 1, 'type' => 'audio', 'action' => 'copy']], new MediaSettings))
        ->toThrow(QualityRejected::class);
    expect((new StreamContract)->verify($source, $output, [$video, ['index' => 1, 'type' => 'audio', 'action' => 'drop']], new MediaSettings)['expected'])
        ->toHaveCount(1);
});

it('requires SDR color tags when the saved policy tone maps HDR', function (): void {
    $source = array_replace(qualityVideo(), ['color_primaries' => 'bt2020', 'color_transfer' => 'smpte2084', 'color_space' => 'bt2020nc']);
    $result = (new StreamContract)->verify(['streams' => [$source]], ['streams' => [qualityVideo('hevc')]],
        [['index' => 0, 'type' => 'video', 'action' => 'encode']], new MediaSettings(hdrMode: HdrMode::TONE_MAP));

    expect($result['hdr_view'])->toBe('tone_mapped_sdr');
});

it('refuses invented dropped-stream identities', function (): void {
    $source = ['streams' => [qualityVideo(), ['index' => 1, 'codec_type' => 'audio']]];
    expect(fn () => (new StreamContract)->verify($source, ['streams' => [qualityVideo('hevc')]],
        [['index' => 0, 'type' => 'video', 'action' => 'encode'], ['index' => 7, 'type' => 'audio', 'action' => 'drop']], new MediaSettings))
        ->toThrow(RuntimeException::class, 'saved stream decision');
});

it('rejects changes to display rotation', function (): void {
    $video = [...qualityVideo(), 'side_data_list' => [['side_data_type' => 'Display Matrix', 'rotation' => 90]]];
    expect(fn () => (new StreamContract)->verify(['streams' => [$video]], ['streams' => [qualityVideo('hevc')]],
        [['index' => 0, 'type' => 'video', 'action' => 'encode']], new MediaSettings))
        ->toThrow(QualityRejected::class, 'rotation');
});

it('accepts equivalent language tags on encoded video and copied audio', function (array $sourceTags, array $outputTags): void {
    $audio = ['index' => 1, 'codec_type' => 'audio', 'codec_name' => 'aac', 'channels' => 2];
    $result = (new StreamContract)->verify(
        ['streams' => [[...qualityVideo(), 'tags' => $sourceTags], [...$audio, 'tags' => $sourceTags]]],
        ['streams' => [[...qualityVideo('hevc'), 'tags' => $outputTags], [...$audio, 'tags' => $outputTags]]],
        [['index' => 0, 'type' => 'video', 'action' => 'encode'], ['index' => 1, 'type' => 'audio', 'action' => 'copy']],
        new MediaSettings,
    );

    expect($result['expected'])->toHaveCount(2);
})->with([
    'und to missing' => [['language' => 'und'], []],
    'missing to und' => [[], ['language' => 'und']],
    'empty to missing' => [['language' => ''], []],
    'missing to empty' => [[], ['language' => '']],
    'unknown case and whitespace' => [['LANGUAGE' => ' UND '], ['language' => '']],
    'known case and whitespace' => [['LANGUAGE' => ' ENG '], ['language' => 'eng']],
]);

it('rejects changed, lost or invented stream languages', function (array $sourceTags, array $outputTags): void {
    $audio = ['index' => 1, 'codec_type' => 'audio', 'codec_name' => 'aac', 'channels' => 2];

    expect(fn () => (new StreamContract)->verify(
        ['streams' => [qualityVideo(), [...$audio, 'tags' => $sourceTags]]],
        ['streams' => [qualityVideo('hevc'), [...$audio, 'tags' => $outputTags]]],
        [['index' => 0, 'type' => 'video', 'action' => 'encode'], ['index' => 1, 'type' => 'audio', 'action' => 'copy']],
        new MediaSettings,
    ))->toThrow(QualityRejected::class, 'Output stream 1 did not retain its language.');
})->with([
    'changed' => [['language' => 'eng'], ['language' => 'ita']],
    'lost' => [['language' => 'eng'], []],
    'became unknown' => [['language' => 'eng'], ['language' => 'und']],
    'invented' => [[], ['language' => 'eng']],
]);

it('recognizes only MP4 chapter data tracks when verifying output stream counts', function (string $handler, bool $valid): void {
    $chapters = [['start_time' => '0', 'end_time' => '1', 'tags' => ['title' => 'Opening']]];
    $chapter = ['index' => 1, 'codec_type' => 'data', 'codec_name' => 'bin_data', 'codec_tag_string' => 'text', 'tags' => ['handler_name' => $handler]];
    $source = ['streams' => [qualityVideo()], 'chapters' => $chapters];
    $output = ['format' => ['format_name' => 'mov,mp4,m4a,3gp,3g2,mj2'], 'streams' => [qualityVideo('hevc'), $chapter], 'chapters' => $chapters];
    $verify = fn (): array => (new StreamContract)->verify($source, $output, [['index' => 0, 'type' => 'video', 'action' => 'encode']], new MediaSettings);

    if ($valid) {
        expect($verify()['expected'])->toHaveCount(1);
    } else {
        expect($verify)->toThrow(QualityRejected::class, 'unexpected streams');
    }
})->with([['SubtitleHandler', true], ['OtherData', false]]);
