<?php

use App\Encoding\AccelerationPreference;
use App\Encoding\Data\Backend;
use App\Encoding\Data\Detection;
use App\Encoding\Data\Encoder;
use App\Encoding\Data\MediaSettings;
use App\Encoding\EncoderDetector;
use App\Encoding\Enums\EncoderType;
use App\Encoding\Enums\TargetVideoCodec;
use App\Encoding\Enums\VideoEncoder;
use App\Encoding\Enums\VideoQuality;
use App\Encoding\MediaPreferences;
use App\Ffmpeg\Enums\HardwareBackend;
use App\Ffmpeg\MediaProcessInterrupted;
use App\Ffmpeg\MediaProcessResult;
use App\Jobs\CheckMediaQuality;
use App\Jobs\QueueLibraryTranscodes;
use App\Jobs\TranscodeMediaFile;
use App\Models\MediaStream;
use App\Models\MediaTranscode;
use App\QualityControl\Actions\RequestQualityCheck;
use App\Tasks\Enums\TaskStatus;
use App\Transcoding\Actions\CancelTranscodeRun;
use App\Transcoding\Actions\OptimizeTranscode;
use App\Transcoding\Actions\RequestTranscodeRun;
use App\Transcoding\Actions\RunTranscode;
use App\Transcoding\EncoderQuality;
use App\Transcoding\HdrInspection;
use App\Transcoding\TranscodeInterrupted;
use App\Transcoding\TranscodePlan;
use App\Transcoding\TranscodeProcess;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/TranscodeFixtures.php';

function executableTranscode(): MediaTranscode
{
    $modelDirectory = mediaDirectory(['model.json' => '{}']);
    config(['quality_control.model_path' => $modelDirectory.'/model.json']);
    $optimizer = Mockery::mock(OptimizeTranscode::class);
    $optimizer->shouldReceive('handle')->andReturnUsing(fn (TranscodePlan $plan) => $plan);
    app()->instance(OptimizeTranscode::class, $optimizer);
    Queue::fake();
    Storage::fake('transcodes');
    $file = transcodeSource();
    MediaStream::factory()->for($file->probe()->firstOrFail(), 'probe')->create([
        'stream_index' => 0, 'codec_type' => 'video', 'codec_name' => 'h264', 'pixel_format' => 'yuv420p',
        'width' => 1920, 'height' => 1080, 'average_frame_rate' => '24/1',
    ]);
    $file->probe()->update(['metadata' => ['streams' => [['index' => 0, 'field_order' => 'progressive']]], 'duration_seconds' => 10]);
    app(EncoderDetector::class)->store(new Detection(now()->toImmutable(), encoders: [new Encoder('libx265', 'hevc', EncoderType::VIDEO, 'HEVC')]));
    $run = app(RequestTranscodeRun::class)->handle($file->libraryPath()->firstOrFail()->library()->firstOrFail());
    app()->call([new QueueLibraryTranscodes($run->id, $run->request_token), 'handle']);

    return $run->transcodes()->sole();
}

it('optimizes before the sole full encode and persists its chosen arguments', function (): void {
    $item = executableTranscode();
    $optimizer = Mockery::mock(OptimizeTranscode::class);
    $optimizer->shouldReceive('handle')->once()->andReturnUsing(function (TranscodePlan $plan, array $policy, array $quality, string $directory, float $deadline, Closure $stop, Closure $progress) {
        expect($policy['enabled'])->toBeTrue()->and($quality['media']['minimum_vmaf'])->toEqual(95.0);
        $progress(['phase' => 'sampling', 'optimization' => ['quality_policy' => $quality, 'winner' => ['quality' => 34]]]);

        return $plan->withQuality(new EncoderQuality($plan->encoder, 34));
    });
    app()->instance(OptimizeTranscode::class, $optimizer);
    $runner = Mockery::mock(TranscodeProcess::class);
    $runner->shouldReceive('run')->once()->andReturnUsing(function (array $args, string $output): MediaProcessResult {
        expect(implode(' ', $args))->toContain('-crf 34');
        file_put_contents($output, 'output');

        return new MediaProcessResult(0, '');
    });
    app()->instance(TranscodeProcess::class, $runner);
    app(RunTranscode::class)->handle($item->id, $item->request_token);
    $item->refresh();
    expect($item->status)->toBe(TaskStatus::COMPLETED)
        ->and($item->optimization['winner']['quality'] ?? null)->toBe(34);
});

it('keeps optimization and the full encode on the selected acceleration backend', function (string $mode, TargetVideoCodec $codec): void {
    $item = executableTranscode();
    app(MediaPreferences::class)->saveGlobal(new MediaSettings(videoCodec: $codec));
    app(AccelerationPreference::class)->save($mode);
    $hardware = $codec === TargetVideoCodec::AV1 ? VideoEncoder::AV1_VAAPI : VideoEncoder::HEVC_VAAPI;
    $software = $codec->softwareEncoder();
    $expected = $mode === AccelerationPreference::SOFTWARE ? $software : $hardware;
    app(EncoderDetector::class)->store(new Detection(now()->toImmutable(), encoders: [
        new Encoder($hardware->value, $codec->value, EncoderType::VIDEO, 'VAAPI', HardwareBackend::VAAPI),
        new Encoder($software->value, $codec->value, EncoderType::VIDEO, 'Software'),
    ], backends: ['vaapi' => new Backend('vaapi', 'VAAPI', 1, true, 'Available')]));
    $optimizer = Mockery::mock(OptimizeTranscode::class);
    $optimizer->shouldReceive('handle')->once()->andReturnUsing(function (TranscodePlan $plan, array $policy, array $quality) use ($expected) {
        expect($quality['decoding']['backend'])->toBe($expected->backend()?->value);
        expect($plan->encoder)->toBe($expected);

        return $plan;
    });
    app()->instance(OptimizeTranscode::class, $optimizer);
    $runner = Mockery::mock(TranscodeProcess::class);
    $runner->shouldReceive('run')->once()->andReturnUsing(function (array $args, string $output) use ($expected): MediaProcessResult {
        expect(implode(' ', $args))->toContain('-c:0 '.$expected->value);
        file_put_contents($output, 'encoded-output');

        return new MediaProcessResult(0, '');
    });
    app()->instance(TranscodeProcess::class, $runner);
    app(RunTranscode::class)->handle($item->id, $item->request_token);
    expect($item->refresh()->error)->toBeNull()->and($item->status)->toBe(TaskStatus::COMPLETED);
})->with(['auto', 'vaapi', 'software'])->with([TargetVideoCodec::HEVC, TargetVideoCodec::AV1]);

it('uses the latest settings and stages an immutable handoff without touching the source', function (): void {
    $item = executableTranscode();
    app(MediaPreferences::class)->saveGlobal(new MediaSettings(videoQuality: VideoQuality::SMALLER));
    $runner = Mockery::mock(TranscodeProcess::class);
    $runner->shouldReceive('run')->once()->andReturnUsing(function (array $args, string $output, int $remaining, ?float $duration, Closure $interrupted, Closure $progress): MediaProcessResult {
        expect($args)->toContain('26');
        expect($remaining)->toBeGreaterThan(0)->toBeLessThanOrEqual(604800);
        $progress(50.0, 5.0, 'driver warning');
        file_put_contents($output, 'encoded-output');

        return new MediaProcessResult(0, 'driver warning');
    });
    app()->instance(TranscodeProcess::class, $runner);
    app(RunTranscode::class)->handle($item->id, $item->request_token);
    $item->refresh();
    expect($item->status)->toBe(TaskStatus::COMPLETED);
    expect($item->output_size_bytes)->toBe(14);
    expect($item->diagnostic_tail)->toBe('driver warning');
    expect($item->settings()->where('key', 'media.video_quality')->sole()->value)->toBe('smaller');
    expect($item->run->status)->toBe(TaskStatus::COMPLETED);
    Storage::disk('transcodes')->assertExists((string) $item->output_path);
    expect(file_get_contents($item->source_path))->toBe('video');
    app(MediaPreferences::class)->saveGlobal(new MediaSettings);
    expect($item->settings()->where('key', 'media.video_quality')->sole()->value)->toBe('smaller');
    Queue::assertPushed(TranscodeMediaFile::class, 1);
    Queue::assertPushed(CheckMediaQuality::class, 1);
});

it('keeps accepted output when the quality-control handoff fails', function (): void {
    $item = executableTranscode();
    $runner = Mockery::mock(TranscodeProcess::class);
    $runner->shouldReceive('run')->once()->andReturnUsing(function (array $arguments, string $output): MediaProcessResult {
        file_put_contents($output, 'accepted-output');

        return new MediaProcessResult(0, '');
    });
    app()->instance(TranscodeProcess::class, $runner);
    $handoff = Mockery::mock(RequestQualityCheck::class);
    $handoff->shouldReceive('handle')->once()->andThrow(new RuntimeException('Quality-control queue unavailable.'));
    app()->instance(RequestQualityCheck::class, $handoff);

    app(RunTranscode::class)->handle($item->id, $item->request_token);

    expect($item->refresh()->status)->toBe(TaskStatus::COMPLETED);
    Storage::disk('transcodes')->assertExists($item->output_path ?? '');
    expect(file_get_contents($item->source_path))->toBe('video');
});

it('rejects duplicate and stale deliveries before starting an encoder', function (): void {
    $item = executableTranscode();
    $runner = Mockery::mock(TranscodeProcess::class);
    $runner->shouldNotReceive('run');
    app()->instance(TranscodeProcess::class, $runner);
    app(RunTranscode::class)->handle($item->id, 'old-token');
    expect($item->refresh()->status)->toBe(TaskStatus::QUEUED);
    $item->update(['status' => TaskStatus::RUNNING]);
    app(RunTranscode::class)->handle($item->id, $item->request_token);
    expect($item->refresh()->attempt_token)->toBeNull();
});

it('removes only incomplete staging on encoding failure or missing output', function (bool $success): void {
    $item = executableTranscode();
    $runner = Mockery::mock(TranscodeProcess::class);
    $runner->shouldReceive('run')->once()->andReturnUsing(function (array $args, string $output) use ($success): MediaProcessResult {
        if (! $success) {
            file_put_contents($output, 'partial');
        }

        return new MediaProcessResult($success ? 0 : 1, 'encoder diagnostics');
    });
    app()->instance(TranscodeProcess::class, $runner);
    app(RunTranscode::class)->handle($item->id, $item->request_token);
    expect($item->refresh()->status)->toBe(TaskStatus::FAILED);
    expect(Storage::disk('transcodes')->allFiles())->toBe([]);
    expect(file_get_contents($item->source_path))->toBe('video');
})->with([true, false]);

it('cancels a running encode before accepting output and permits the next run only after cleanup', function (): void {
    $item = executableTranscode();
    $runner = Mockery::mock(TranscodeProcess::class);
    $runner->shouldReceive('run')->once()->andReturnUsing(function (array $args, string $output) use ($item): MediaProcessResult {
        app(CancelTranscodeRun::class)->handle($item->run);
        expect($item->run->refresh()->finished_at)->toBeNull();
        expect(app(RequestTranscodeRun::class)->handle($item->run->library()->firstOrFail())->id)->toBe($item->transcode_run_id);
        file_put_contents($output, 'cancelled-output');

        return new MediaProcessResult(0, '');
    });
    app()->instance(TranscodeProcess::class, $runner);
    app(RunTranscode::class)->handle($item->id, $item->request_token);
    expect($item->refresh()->status)->toBe(TaskStatus::CANCELLED);
    expect($item->run->refresh()->status)->toBe(TaskStatus::CANCELLED);
    expect(Storage::disk('transcodes')->allFiles())->toBe([]);
    expect(app(RequestTranscodeRun::class)->handle($item->run->library()->firstOrFail())->id)->not->toBe($item->transcode_run_id);
});

it('does not accept a source changed during encoding', function (): void {
    $item = executableTranscode();
    $runner = Mockery::mock(TranscodeProcess::class);
    $runner->shouldReceive('run')->once()->andReturnUsing(function (array $args, string $output) use ($item): MediaProcessResult {
        file_put_contents($item->source_path, 'changed source');
        file_put_contents($output, 'encoded-output');

        return new MediaProcessResult(0, '');
    });
    app()->instance(TranscodeProcess::class, $runner);
    app(RunTranscode::class)->handle($item->id, $item->request_token);
    expect($item->refresh()->status)->toBe(TaskStatus::SKIPPED);
    expect($item->skip_reason)->toBe('source_changed');
    expect(Storage::disk('transcodes')->allFiles())->toBe([]);
});

it('cleans renamed output if accepting the database record fails', function (): void {
    $item = executableTranscode();
    $runner = Mockery::mock(TranscodeProcess::class);
    $runner->shouldReceive('run')->once()->andReturnUsing(function (array $args, string $output): MediaProcessResult {
        file_put_contents($output, 'encoded-output');

        return new MediaProcessResult(0, '');
    });
    app()->instance(TranscodeProcess::class, $runner);
    MediaTranscode::updating(function (MediaTranscode $item): void {
        if ($item->status === TaskStatus::COMPLETED) {
            throw new RuntimeException('completion storage failed');
        }
    });
    app(RunTranscode::class)->handle($item->id, $item->request_token);
    expect(Storage::disk('transcodes')->allFiles())->toBe([]);
    expect($item->refresh()->status)->toBe(TaskStatus::FAILED);
});

it('snapshots effective policy settings even when the file is skipped', function (): void {
    $item = executableTranscode();
    app(MediaPreferences::class)->saveGlobal(new MediaSettings(skipVideoCodecs: ['h264']));
    app(RunTranscode::class)->handle($item->id, $item->request_token);
    expect($item->refresh()->status)->toBe(TaskStatus::SKIPPED);
    expect($item->settings()->where('key', 'media.skip_video_codecs')->sole()->value)->toBe(['h264']);
});

it('stops an encode when its run is cancelled and removes the partial output', function (): void {
    $item = executableTranscode();
    $runner = Mockery::mock(TranscodeProcess::class);
    $runner->shouldReceive('run')->once()->andReturnUsing(function (array $args, string $output, int $remaining, ?float $duration, Closure $interrupted) use ($item): never {
        file_put_contents($output, 'partial');
        app(CancelTranscodeRun::class)->handle($item->run);
        expect($interrupted())->toBeTrue();
        throw new TranscodeInterrupted('The transcode was interrupted.');
    });
    app()->instance(TranscodeProcess::class, $runner);
    app(RunTranscode::class)->handle($item->id, $item->request_token);
    expect($item->refresh()->status)->toBe(TaskStatus::CANCELLED);
    expect($item->run->refresh()->status)->toBe(TaskStatus::CANCELLED);
    expect(Storage::disk('transcodes')->allFiles())->toBe([]);
});

it('fails if staging is within a library and never writes into that source folder', function (): void {
    $item = executableTranscode();
    $root = dirname($item->source_path);
    Storage::forgetDisk('transcodes');
    config(['filesystems.disks.transcodes.root' => $root.'/staging']);
    $runner = Mockery::mock(TranscodeProcess::class);
    $runner->shouldNotReceive('run');
    app()->instance(TranscodeProcess::class, $runner);
    app(RunTranscode::class)->handle($item->id, $item->request_token);
    expect($item->refresh()->status)->toBe(TaskStatus::FAILED);
    expect($item->error)->toContain('outside every library');
    expect(file_get_contents($item->source_path))->toBe('video');
});

it('skips frame-only dynamic HDR before encoding when preservation is requested', function (): void {
    $item = executableTranscode();
    $file = $item->mediaFile()->firstOrFail();
    $file->probe()->firstOrFail()->streams()->update([
        'pixel_format' => 'yuv420p10le', 'color_transfer' => 'smpte2084', 'color_primaries' => 'bt2020', 'color_space' => 'bt2020nc',
    ]);
    $inspection = new HdrInspection;
    $inspection->push("frames.frame.0.side_data_list.side_data.0.side_data_type=\"HDR Dynamic Metadata SMPTE2094-40 (HDR10+)\"\n");
    $runner = Mockery::mock(TranscodeProcess::class);
    $runner->shouldReceive('inspectHdr')->once()->andReturn($inspection);
    $runner->shouldNotReceive('run');
    app()->instance(TranscodeProcess::class, $runner);
    app(RunTranscode::class)->handle($item->id, $item->request_token);
    expect($item->refresh()->status)->toBe(TaskStatus::SKIPPED);
    expect($item->skip_reason)->toBe('unsupported_hdr_metadata');
    expect(Storage::disk('transcodes')->allFiles())->toBe([]);
});

it('does not launch the full encode when preparation is cancelled or loses its request token', function (string $scenario): void {
    $item = executableTranscode();
    $optimizer = Mockery::mock(OptimizeTranscode::class);
    $optimizer->shouldReceive('handle')->once()->andReturnUsing(function (TranscodePlan $plan, array $policy, array $quality, string $directory, float $deadline, Closure $stop, Closure $progress) use ($item, $scenario) {
        if ($scenario === 'cancel') {
            app(CancelTranscodeRun::class)->handle($item->run);
            expect($stop())->toBeTrue();
            throw new MediaProcessInterrupted('Cancelled during sampling');
        }
        $item->update(['request_token' => (string) Str::uuid(), 'phase' => 'replacement']);
        $progress(['phase' => 'sampling']);
        throw new RuntimeException('A stale attempt must never publish progress');
    });
    app()->instance(OptimizeTranscode::class, $optimizer);
    $runner = Mockery::mock(TranscodeProcess::class);
    $runner->shouldNotReceive('run');
    app()->instance(TranscodeProcess::class, $runner);
    $token = $item->request_token;
    app(RunTranscode::class)->handle($item->id, $token);
    $item->refresh();
    if ($scenario === 'cancel') {
        expect($item->status)->toBe(TaskStatus::CANCELLED);
    } else {
        expect($item->phase)->toBe('replacement')->and($item->request_token)->not->toBe($token);
    }
    expect(file_get_contents($item->source_path))->toBe('video');
})->with(['cancel', 'stale']);

it('hands off the preparation policy despite settings changing during full encoding', function (): void {
    $item = executableTranscode();
    $runner = Mockery::mock(TranscodeProcess::class);
    $runner->shouldReceive('run')->once()->andReturnUsing(function (array $args, string $output): MediaProcessResult {
        app(MediaPreferences::class)->saveGlobal(new MediaSettings(minimumVmaf: 50));
        config(['quality_control.timeout_seconds' => 5, 'quality_control.model_path' => '/changed-model.json']);
        file_put_contents($output, 'output');

        return new MediaProcessResult(0, '');
    });
    app()->instance(TranscodeProcess::class, $runner);
    app(RunTranscode::class)->handle($item->id, $item->request_token);
    $check = $item->refresh()->qualityCheck;
    expect($check)->not->toBeNull();
    if ($check === null) {
        throw new RuntimeException('Quality handoff was not created');
    }
    expect($check->policy['media']['minimum_vmaf'])->toEqual(95)
        ->and($check->model_sha256)->toBe(hash('sha256', '{}'))
        ->and($check->execution_limit_seconds)->toBe(86400);
});
