<?php

use App\Encoding\Data\MediaSettings;
use App\Encoding\Enums\HdrMode;
use App\Ffmpeg\ToneMap;
use App\Jobs\CheckMediaQuality;
use App\Models\MediaTranscode;
use App\QualityControl\Actions\RequestQualityCheck;
use App\QualityControl\Actions\RunQualityCheck;
use App\QualityControl\Enums\QualityVerdict;
use App\Tasks\Enums\TaskStatus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

require_once __DIR__.'/../../Support/TranscodeFixtures.php';

it('compares real decoded video and leaves both media files unchanged', function (string $scenario): void {
    $hdr = in_array($scenario, ['hdr', 'hdr_changed_metadata', 'tone_map'], true);
    $failed = in_array($scenario, ['missing_model', 'changed_model', 'changed_output', 'decode_error'], true);
    $reject = in_array($scenario, ['degraded', 'truncated_audio', 'hdr_changed_metadata', 'savings'], true);
    $degraded = $scenario === 'degraded';
    $sampled = in_array($scenario, ['sampled', 'variable'], true);
    $disabled = in_array($scenario, ['disabled', 'decode_error'], true);
    $pixel = $scenario === 'decode_error' ? 'yuv420p' : 'yuv420p10le';
    $audio = in_array($scenario, ['audio', 'truncated_audio'], true);
    Queue::fake([CheckMediaQuality::class]);
    Storage::fake('transcodes');
    config(['quality_control.width' => 320, 'quality_control.height' => 180, 'quality_control.sample_seconds' => 1]);
    if ($scenario === 'missing_model') {
        config(['quality_control.model_path' => '/missing-vmaf-model.json']);
    }
    $file = transcodeSource();
    $source = $file->libraryPath()->firstOrFail()->path.'/'.$file->relative_path;
    $duration = $sampled ? 12 : 1;
    $videoFilter = 'testsrc2=size=64x64:rate=8:duration='.$duration.',format='.$pixel;
    if ($sampled) {
        $videoFilter .= $scenario === 'variable' ? ',setpts=PTS+mod(N\\,2)*0.25+80' : ',setpts=PTS+80';
    }
    $videoFilter .= $hdr ? ',setparams=color_primaries=bt2020:color_trc=smpte2084:colorspace=bt2020nc:range=limited'
        : ',setparams=color_primaries=bt709:color_trc=bt709:colorspace=bt709:range=limited';
    $audioInput = $audio ? ['-f', 'lavfi', '-i', 'sine=duration=1:sample_rate=48000', '-c:a', 'pcm_s16le'] : [];
    $x265 = 'lossless=1:pools=1:frame-threads=1:log-level=error';
    $mastering = ':master-display=G(13250,34500)B(7500,3000)R(34000,16000)WP(15635,16450)L(10000000,1):max-cll=1000,400';
    $sourceCodec = $hdr ? ['-c:v', 'libx265', '-preset', 'ultrafast', '-x265-params', $x265.$mastering] : ['-c:v', 'ffv1', '-level', '3'];
    if ($scenario === 'decode_error') {
        $sourceCodec = ['-c:v', 'mpeg2video', '-f', 'mpegts'];
    }
    (new Process(['ffmpeg', '-v', 'error', '-nostdin', '-y', '-f', 'lavfi', '-i', $videoFilter, ...$audioInput, '-fps_mode:v', 'passthrough',
        ...$sourceCodec, '-color_primaries', $hdr ? 'bt2020' : 'bt709', '-color_trc', $hdr ? 'smpte2084' : 'bt709', '-colorspace', $hdr ? 'bt2020nc' : 'bt709', '-color_range', 'tv', $source], timeout: 30))->mustRun();
    if ($hdr) {
        $probe = new Process(['ffprobe', '-v', 'error', '-show_streams', '-of', 'json', $source]);
        $probe->mustRun();
        $metadata = json_decode($probe->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        expect($metadata['streams'][0]['color_transfer'] ?? null)->toBe('smpte2084');
        expect($metadata['streams'][0]['color_primaries'] ?? null)->toBe('bt2020');
    }
    clearstatcache(true);
    $modified = filemtime($source);
    if ($modified === false) {
        throw new RuntimeException('Fixture modification time is unavailable.');
    }
    $file->update(['size_bytes' => filesize($source), 'filesystem_modified_at' => date('Y-m-d H:i:s', $modified)]);
    $attempt = (string) Str::uuid();
    $decisions = [['index' => 0, 'type' => 'video', 'action' => 'encode']];
    if ($audio) {
        $decisions[] = ['index' => 1, 'type' => 'audio', 'action' => 'copy'];
    }
    $transcode = MediaTranscode::factory()->create(['media_file_id' => $file->id, 'status' => TaskStatus::COMPLETED,
        'attempt_token' => $attempt, 'source_path' => $source, 'source_size_bytes' => $file->size_bytes, 'source_modified_at' => $file->filesystem_modified_at,
        'stream_decisions' => $decisions, 'encoder' => 'libx265']);
    $relative = $transcode->id.'/'.$attempt.'/output.mkv';
    Storage::disk('transcodes')->makeDirectory(dirname($relative));
    $output = Storage::disk('transcodes')->path($relative);
    $filter = $degraded ? ['-vf', 'negate'] : [];
    if ($scenario === 'tone_map') {
        $filter = ['-vf', ToneMap::filter('yuv420p10le'), '-color_primaries', 'bt709', '-color_trc', 'bt709', '-colorspace', 'bt709', '-color_range', 'tv'];
    }
    $audioOutput = $scenario === 'truncated_audio' ? ['-af', 'atrim=end=0.4', '-c:a', 'pcm_s16le'] : ['-c:a', 'copy'];
    (new Process(['ffmpeg', '-v', 'error', '-nostdin', '-i', $source, ...$filter, ...$audioOutput, '-fps_mode:v', 'passthrough', '-c:v', 'libx265', '-preset', 'ultrafast',
        '-x265-params', $x265.($scenario === 'hdr' ? $mastering : ($scenario === 'hdr_changed_metadata' ? str_replace('max-cll=1000,400', 'max-cll=900,400', $mastering) : '')), '-pix_fmt', '+'.$pixel, $output], timeout: 30))->mustRun();
    if ($scenario === 'decode_error') {
        $content = file_get_contents($source);
        if ($content === false) {
            throw new RuntimeException('Missing damaged-video fixture.');
        }
        $content = substr_replace($content, str_repeat(chr(0), 80), 188 * 8 + 20, 80);
        file_put_contents($source, $content);
        clearstatcache(true);
        $changed = filemtime($source);
        if ($changed === false) {
            throw new RuntimeException('Missing fixture modification time.');
        }
        $transcode->update(['source_modified_at' => date('Y-m-d H:i:s', $changed)]);
        $probe = new Process(['ffprobe', '-v', 'error', '-show_frames', '-select_streams', 'v:0',
            '-show_entries', 'frame=best_effort_timestamp_time', '-of', 'flat', $source]);
        $probe->run();
        expect($probe->getExitCode())->toBe(0);
        expect(substr_count($probe->getOutput(), 'best_effort_timestamp_time'))->toBe(8);
        expect($probe->getErrorOutput())->not->toBeEmpty();
    }
    $transcode->update(['output_path' => $relative, 'output_size_bytes' => filesize($output)]);
    foreach ((new MediaSettings(hdrMode: $scenario === 'tone_map' ? HdrMode::TONE_MAP : HdrMode::PRESERVE, requireSmallerOutput: $scenario === 'savings', minimumSavingsPercent: 99, verifyVideoQuality: ! $disabled))->toArray() as $key => $value) {
        $transcode->settings()->create(['key' => 'media.'.$key, 'value' => $value]);
    }
    $check = app(RequestQualityCheck::class)->handle($transcode);

    if ($scenario === 'changed_model') {
        $check->update(['model_sha256' => str_repeat('0', 64)]);
    }
    if ($scenario === 'changed_output') {
        file_put_contents($output, 'changed', FILE_APPEND);
    }
    $hashes = [hash_file('sha256', $source), hash_file('sha256', $output)];

    app(RunQualityCheck::class)->handle($check->id, $check->request_token);

    expect($check->refresh()->status)->toBe($failed ? TaskStatus::FAILED : TaskStatus::COMPLETED, (string) $check->error);
    expect($check->verdict)->toBe($failed ? null : ($reject ? QualityVerdict::REJECTED : QualityVerdict::PASSED), (string) $check->error);
    if ($disabled || $failed || in_array($scenario, ['truncated_audio', 'hdr_changed_metadata', 'savings'], true)) {
        expect($check->vmaf_mean)->toBeNull();
    } else {
        expect($check->vmaf_mean)->not->toBeNull();
    }
    if ($sampled) {
        expect($check->results['segments'] ?? null)->toHaveCount(3);
        expect($check->coverage_seconds)->toEqualWithDelta(4.2, 0.15);
    }
    if ($disabled) {
        expect($check->results['video_quality'] ?? null)->toBe('disabled');
    }
    expect([hash_file('sha256', $source), hash_file('sha256', $output)])->toBe($hashes);
    expect(Storage::disk('transcodes')->allFiles())->toBe([$relative]);
    Queue::assertPushed(CheckMediaQuality::class, 1);
})->with(['identical', 'degraded', 'sampled', 'variable', 'disabled', 'audio', 'truncated_audio', 'hdr', 'hdr_changed_metadata', 'tone_map', 'missing_model', 'changed_model', 'changed_output', 'savings', 'decode_error'])->skip(fn (): bool => getenv('QUALITY_CONTROL_NATIVE_TESTS') !== '1', 'Run inside the production image with QUALITY_CONTROL_NATIVE_TESTS=1.');
