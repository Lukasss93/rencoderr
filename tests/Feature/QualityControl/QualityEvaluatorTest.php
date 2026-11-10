<?php

use App\Encoding\Data\MediaSettings;
use App\Ffmpeg\MediaProcess;
use App\Ffmpeg\MediaProcessResult;
use App\Models\MediaQualityCheck;
use App\Models\MediaTranscode;
use App\QualityControl\Enums\QualityVerdict;
use App\QualityControl\QualityEvaluator;
use App\Tasks\Enums\TaskStatus;
use App\Writeback\Enums\WritebackMode;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/TranscodeFixtures.php';

function fingerprintQualityCheck(): MediaQualityCheck
{
    $disk = Storage::fake('transcodes');
    $file = transcodeSource();
    $source = $file->libraryPath()->firstOrFail()->path.'/'.$file->relative_path;
    $attempt = (string) Str::uuid();
    $transcode = MediaTranscode::factory()->for($file)->create([
        'status' => TaskStatus::COMPLETED, 'attempt_token' => $attempt, 'writeback_mode' => WritebackMode::REPLACE,
        'source_path' => $source, 'source_size_bytes' => 5, 'source_modified_at' => $file->filesystem_modified_at,
        'output_size_bytes' => 4, 'stream_decisions' => [['index' => 0, 'type' => 'video', 'action' => 'encode']],
    ]);
    $relative = $transcode->id.'/'.$attempt.'/output.mkv';
    $disk->put($relative, 'new!');
    $transcode->update(['output_path' => $relative]);
    $check = MediaQualityCheck::factory()->for($transcode, 'transcode')->create([
        'attempt_token' => (string) Str::uuid(), 'transcode_attempt_token' => $attempt,
        'source_path' => $source, 'source_size_bytes' => 5, 'source_modified_at' => $file->filesystem_modified_at,
        'output_path' => $relative, 'output_size_bytes' => 4, 'execution_deadline_at' => now()->addMinute(),
        'policy' => ['version' => 2, 'mode' => 'full', 'width' => 320, 'height' => 180, 'duration_tolerance_seconds' => 0.5,
            'positions' => [0.5], 'sample_seconds' => 1,
            'media' => (new MediaSettings(requireSmallerOutput: false, verifyVideoQuality: false))->toArray()],
    ]);
    $process = Mockery::mock(MediaProcess::class);
    $process->shouldReceive('execute')->andReturnUsing(function (array $command, int $timeout, Closure $stop, Closure $consume): MediaProcessResult {
        if (in_array('-version', $command, true)) {
            $consume('test binary version');
        } elseif (in_array('-show_frames', $command, true)) {
            $consume("frames.frame.0.best_effort_timestamp_time=\"0\"\nframes.frame.0.duration_time=\"0.04\"\n");
        } else {
            $consume(json_encode(['streams' => [['index' => 0, 'codec_type' => 'video', 'codec_name' => 'hevc',
                'width' => 64, 'height' => 64, 'pix_fmt' => 'yuv420p', 'avg_frame_rate' => '25/1', 'time_base' => '1/25']],
                'format' => ['format_name' => 'matroska,webm']], JSON_THROW_ON_ERROR));
        }

        return new MediaProcessResult(0, '');
    });
    app()->instance(MediaProcess::class, $process);

    return $check;
}

it('verifies unchanged contents after filesystem metadata changes', function (): void {
    $check = fingerprintQualityCheck();
    $source = $check->source_path;
    $output = Storage::disk('transcodes')->path($check->output_path);
    touch($source, 1000000000);

    $result = app(QualityEvaluator::class)->evaluate($check, fn (): bool => false, function (array $update) use ($source, $output): void {
        if (($update['phase'] ?? null) !== 'probing') {
            return;
        }
        copy($source, $source.'.replacement');
        rename($source.'.replacement', $source);
        touch($output, 1000000000);
    });

    expect($result['verdict'])->toBe(QualityVerdict::PASSED);
    expect($result['source_fingerprint'])->toBe(hash('sha256', 'video'));
    expect($result['output_fingerprint'])->toBe(hash('sha256', 'new!'));
});

it('refuses changed contents with the same size and modification time during quality control', function (string $field): void {
    $check = fingerprintQualityCheck();
    $path = $field === 'source' ? $check->source_path : Storage::disk('transcodes')->path($check->output_path);
    $modified = filemtime($path);
    if ($modified === false) {
        throw new RuntimeException('Missing media modification time.');
    }

    expect(fn () => app(QualityEvaluator::class)->evaluate($check, fn (): bool => false,
        function (array $update) use ($path, $field, $modified): void {
            if (($update['phase'] ?? null) === 'probing') {
                file_put_contents($path, $field === 'source' ? 'other' : 'bad!');
                touch($path, $modified);
            }
        }))->toThrow(RuntimeException::class, 'The source or staged output changed during quality control.');
})->with(['source', 'output']);
