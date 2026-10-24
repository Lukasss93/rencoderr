<?php

use App\Encoding\Data\Detection;
use App\Encoding\Data\Encoder;
use App\Encoding\Data\MediaSettings;
use App\Encoding\EncoderDetector;
use App\Encoding\Enums\EncoderType;
use App\Encoding\Enums\VideoQuality;
use App\Encoding\MediaPreferences;
use App\Jobs\QueueLibraryTranscodes;
use App\Jobs\TranscodeMediaFile;
use App\Models\MediaStream;
use App\Models\MediaTranscode;
use App\Tasks\Enums\TaskStatus;
use App\Transcoding\Actions\CancelTranscodeRun;
use App\Transcoding\Actions\RequestTranscodeRun;
use App\Transcoding\Actions\RunTranscode;
use App\Transcoding\Events\TranscodeStaged;
use App\Transcoding\HdrInspection;
use App\Transcoding\TranscodeInterrupted;
use App\Transcoding\TranscodeOutcome;
use App\Transcoding\TranscodeProcess;
use App\Transcoding\TranscodeProcessResult;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../../Support/TranscodeFixtures.php';

function executableTranscode(): MediaTranscode
{
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

it('uses the latest settings and stages an immutable handoff without touching the source', function (): void {
    $item = executableTranscode();
    app(MediaPreferences::class)->saveGlobal(new MediaSettings(videoQuality: VideoQuality::SMALLER));
    Event::fake([TranscodeStaged::class]);
    $runner = Mockery::mock(TranscodeProcess::class);
    $runner->shouldReceive('run')->once()->andReturnUsing(function (array $args, string $output, int $remaining, ?float $duration, Closure $interrupted, Closure $progress): TranscodeProcessResult {
        expect($args)->toContain('26');
        expect($remaining)->toBeGreaterThan(0)->toBeLessThanOrEqual(604800);
        $progress(50.0, 5.0, 'driver warning');
        file_put_contents($output, 'encoded-output');

        return new TranscodeProcessResult(0, 'driver warning');
    });
    app()->instance(TranscodeProcess::class, $runner);
    expect(app(RunTranscode::class)->handle($item->id, $item->request_token, $item->execution_limit_seconds, fn (): bool => false))->toBe(TranscodeOutcome::COMPLETED);
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
    Event::assertDispatched(TranscodeStaged::class, 1);
    Queue::assertPushed(TranscodeMediaFile::class, 1);
});

it('rejects duplicate and stale deliveries before starting an encoder', function (): void {
    $item = executableTranscode();
    $runner = Mockery::mock(TranscodeProcess::class);
    $runner->shouldNotReceive('run');
    app()->instance(TranscodeProcess::class, $runner);
    expect(app(RunTranscode::class)->handle($item->id, 'old-token', $item->execution_limit_seconds, fn (): bool => false))->toBe(TranscodeOutcome::STALE);
    $item->update(['status' => TaskStatus::RUNNING]);
    expect(app(RunTranscode::class)->handle($item->id, $item->request_token, $item->execution_limit_seconds, fn (): bool => false))->toBe(TranscodeOutcome::STALE);
});

it('removes only incomplete staging on encoding failure or missing output', function (bool $success): void {
    $item = executableTranscode();
    Event::fake([TranscodeStaged::class]);
    $runner = Mockery::mock(TranscodeProcess::class);
    $runner->shouldReceive('run')->once()->andReturnUsing(function (array $args, string $output) use ($success): TranscodeProcessResult {
        if (! $success) {
            file_put_contents($output, 'partial');
        }

        return new TranscodeProcessResult($success ? 0 : 1, 'encoder diagnostics');
    });
    app()->instance(TranscodeProcess::class, $runner);
    expect(app(RunTranscode::class)->handle($item->id, $item->request_token, $item->execution_limit_seconds, fn (): bool => false))->toBe(TranscodeOutcome::FAILED);
    expect($item->refresh()->status)->toBe(TaskStatus::FAILED);
    expect(Storage::disk('transcodes')->allFiles())->toBe([]);
    expect(file_get_contents($item->source_path))->toBe('video');
    Event::assertNotDispatched(TranscodeStaged::class);
})->with([true, false]);

it('cancels a running encode before accepting output and permits the next run only after cleanup', function (): void {
    $item = executableTranscode();
    $runner = Mockery::mock(TranscodeProcess::class);
    $runner->shouldReceive('run')->once()->andReturnUsing(function (array $args, string $output) use ($item): TranscodeProcessResult {
        app(CancelTranscodeRun::class)->handle($item->run);
        expect($item->run->refresh()->finished_at)->toBeNull();
        expect(app(RequestTranscodeRun::class)->handle($item->run->library()->firstOrFail())->id)->toBe($item->transcode_run_id);
        file_put_contents($output, 'cancelled-output');

        return new TranscodeProcessResult(0, '');
    });
    app()->instance(TranscodeProcess::class, $runner);
    expect(app(RunTranscode::class)->handle($item->id, $item->request_token, $item->execution_limit_seconds, fn (): bool => false))->toBe(TranscodeOutcome::CANCELLED);
    expect($item->refresh()->status)->toBe(TaskStatus::CANCELLED);
    expect($item->run->refresh()->status)->toBe(TaskStatus::CANCELLED);
    expect(Storage::disk('transcodes')->allFiles())->toBe([]);
    expect(app(RequestTranscodeRun::class)->handle($item->run->library()->firstOrFail())->id)->not->toBe($item->transcode_run_id);
});

it('does not accept a source changed during encoding', function (): void {
    $item = executableTranscode();
    $runner = Mockery::mock(TranscodeProcess::class);
    $runner->shouldReceive('run')->once()->andReturnUsing(function (array $args, string $output) use ($item): TranscodeProcessResult {
        file_put_contents($item->source_path, 'changed source');
        file_put_contents($output, 'encoded-output');

        return new TranscodeProcessResult(0, '');
    });
    app()->instance(TranscodeProcess::class, $runner);
    expect(app(RunTranscode::class)->handle($item->id, $item->request_token, $item->execution_limit_seconds, fn (): bool => false))->toBe(TranscodeOutcome::SKIPPED);
    expect($item->refresh()->skip_reason)->toBe('source_changed');
    expect(Storage::disk('transcodes')->allFiles())->toBe([]);
});

it('cleans renamed output if accepting the database record fails', function (): void {
    $item = executableTranscode();
    $runner = Mockery::mock(TranscodeProcess::class);
    $runner->shouldReceive('run')->once()->andReturnUsing(function (array $args, string $output): TranscodeProcessResult {
        file_put_contents($output, 'encoded-output');

        return new TranscodeProcessResult(0, '');
    });
    app()->instance(TranscodeProcess::class, $runner);
    MediaTranscode::updating(function (MediaTranscode $item): void {
        if ($item->status === TaskStatus::COMPLETED) {
            throw new RuntimeException('completion storage failed');
        }
    });
    expect(app(RunTranscode::class)->handle($item->id, $item->request_token, $item->execution_limit_seconds, fn (): bool => false))->toBe(TranscodeOutcome::FAILED);
    expect(Storage::disk('transcodes')->allFiles())->toBe([]);
    expect($item->refresh()->status)->toBe(TaskStatus::FAILED);
});

it('snapshots effective policy settings even when the file is skipped', function (): void {
    $item = executableTranscode();
    app(MediaPreferences::class)->saveGlobal(new MediaSettings(skipVideoCodecs: ['h264']));
    expect(app(RunTranscode::class)->handle($item->id, $item->request_token, $item->execution_limit_seconds, fn (): bool => false))->toBe(TranscodeOutcome::SKIPPED);
    expect($item->settings()->where('key', 'media.skip_video_codecs')->sole()->value)->toBe(['h264']);
});

it('releases a normal shutdown for another attempt after cleaning partial output', function (): void {
    $item = executableTranscode();
    $runner = Mockery::mock(TranscodeProcess::class);
    $runner->shouldReceive('run')->once()->andReturnUsing(function (array $args, string $output): never {
        file_put_contents($output, 'partial');
        throw new TranscodeInterrupted('shutdown');
    });
    app()->instance(TranscodeProcess::class, $runner);
    expect(app(RunTranscode::class)->handle($item->id, $item->request_token, $item->execution_limit_seconds, fn (): bool => true))->toBe(TranscodeOutcome::INTERRUPTED);
    expect($item->refresh()->status)->toBe(TaskStatus::QUEUED);
    expect($item->finished_at)->toBeNull();
    expect(Storage::disk('transcodes')->allFiles())->toBe([]);
    expect($item->run->refresh()->finished_at)->toBeNull();
});

it('fails if staging is within a library and never writes into that source folder', function (): void {
    $item = executableTranscode();
    $root = dirname($item->source_path);
    Storage::forgetDisk('transcodes');
    config(['filesystems.disks.transcodes.root' => $root.'/staging']);
    $runner = Mockery::mock(TranscodeProcess::class);
    $runner->shouldNotReceive('run');
    app()->instance(TranscodeProcess::class, $runner);
    expect(app(RunTranscode::class)->handle($item->id, $item->request_token, $item->execution_limit_seconds, fn (): bool => false))->toBe(TranscodeOutcome::FAILED);
    expect($item->refresh()->error)->toContain('outside every library');
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
    expect(app(RunTranscode::class)->handle($item->id, $item->request_token, $item->execution_limit_seconds, fn (): bool => false))->toBe(TranscodeOutcome::SKIPPED);
    expect($item->refresh()->skip_reason)->toBe('unsupported_hdr_metadata');
    expect(Storage::disk('transcodes')->allFiles())->toBe([]);
});
