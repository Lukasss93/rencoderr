<?php

use App\Jobs\QueueLibraryTranscodes;
use App\Jobs\TranscodeMediaFile;
use App\Models\MediaTranscode;
use App\Models\TranscodeRun;
use App\Tasks\Enums\TaskStatus;
use App\Transcoding\Actions\EnumerateTranscodeRun;
use App\Transcoding\Actions\RecoverTranscodes;
use App\Transcoding\Actions\RunTranscode;
use App\Transcoding\TranscodeProcess;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

it('requeues interrupted files with new tokens at startup and removes only incomplete output', function (): void {
    Queue::fake([TranscodeMediaFile::class]);
    Storage::fake('transcodes');
    $running = MediaTranscode::factory()->create(['status' => TaskStatus::RUNNING, 'attempt_token' => (string) Str::uuid(), 'started_at' => now()]);
    $queued = MediaTranscode::factory()->create();
    $completed = MediaTranscode::factory()->create(['status' => TaskStatus::COMPLETED, 'attempt_token' => (string) Str::uuid(), 'output_path' => 'retained/output.mkv']);
    $partial = $running->id.'/'.$running->attempt_token.'/output.partial.mkv';
    Storage::disk('transcodes')->put($partial, 'partial');
    Storage::disk('transcodes')->put('retained/output.mkv', 'keep');
    $tokens = [$running->id => $running->request_token, $queued->id => $queued->request_token];

    $recovered = app(RecoverTranscodes::class)->handle();

    expect($recovered)->toBe(2);
    foreach ([$running, $queued] as $item) {
        expect($item->refresh()->status)->toBe(TaskStatus::QUEUED)
            ->and($item->request_token)->not->toBe($tokens[$item->id])
            ->and($item->attempt_token)->toBeNull()
            ->and($item->started_at)->toBeNull();
        Queue::assertPushed(TranscodeMediaFile::class, fn (TranscodeMediaFile $job): bool => $job->transcodeId === $item->id && $job->token === $item->request_token);
    }
    expect($completed->refresh()->status)->toBe(TaskStatus::COMPLETED);
    Storage::disk('transcodes')->assertMissing($partial);
    Storage::disk('transcodes')->assertExists('retained/output.mkv');
});

it('ignores jobs and failures from before the restart', function (): void {
    Queue::fake([TranscodeMediaFile::class]);
    Storage::fake('transcodes');
    $item = MediaTranscode::factory()->create();
    $old = new TranscodeMediaFile($item->id, $item->request_token);
    app(RecoverTranscodes::class)->handle();
    $runner = Mockery::mock(TranscodeProcess::class);
    $runner->shouldNotReceive('run');
    app()->instance(TranscodeProcess::class, $runner);

    $old->handle(app(RunTranscode::class));
    $old->failed(new RuntimeException('stale failure'));

    expect($item->refresh()->status)->toBe(TaskStatus::QUEUED)
        ->and($item->attempt_token)->toBeNull()
        ->and($item->error)->toBeNull();
});

it('fails a file for good when its worker died or timed out and removes its partial output', function (): void {
    Storage::fake('transcodes');
    $run = TranscodeRun::factory()->create(['status' => TaskStatus::RUNNING, 'enumeration_finished_at' => now()]);
    $item = MediaTranscode::factory()->for($run, 'run')->create(['status' => TaskStatus::RUNNING, 'attempt_token' => (string) Str::uuid()]);
    $partial = $item->id.'/'.$item->attempt_token.'/output.partial.mkv';
    Storage::disk('transcodes')->put($partial, 'partial');

    (new TranscodeMediaFile($item->id, $item->request_token))->failed(new MaxAttemptsExceededException('The worker stopped.'));

    expect($item->refresh()->status)->toBe(TaskStatus::FAILED)
        ->and($item->error)->toBe('The worker stopped.');
    expect($run->refresh()->status)->toBe(TaskStatus::FAILED);
    Storage::disk('transcodes')->assertMissing($partial);
});

it('restarts unfinished enumeration at its cursor with a new token at startup', function (): void {
    Queue::fake([QueueLibraryTranscodes::class]);
    $run = TranscodeRun::factory()->create(['status' => TaskStatus::RUNNING, 'inventory_cursor_id' => 200, 'inventory_cutoff_id' => 350]);
    $oldToken = $run->request_token;

    app(RecoverTranscodes::class)->handle();

    expect($run->refresh()->inventory_cursor_id)->toBe(200)
        ->and($run->request_token)->not->toBe($oldToken);
    expect(app(EnumerateTranscodeRun::class)->handle($run->id, $oldToken))->toBeFalse();
    Queue::assertPushed(QueueLibraryTranscodes::class, fn (QueueLibraryTranscodes $job): bool => $job->runId === $run->id && $job->requestToken === $run->request_token);
});

it('cancels stranded work whose library was deleted', function (): void {
    Queue::fake([TranscodeMediaFile::class]);
    Storage::fake('transcodes');
    $item = MediaTranscode::factory()->create(['status' => TaskStatus::RUNNING, 'attempt_token' => (string) Str::uuid()]);
    $item->run->library()->firstOrFail()->delete();

    app(RecoverTranscodes::class)->handle();

    expect($item->refresh()->status)->toBe(TaskStatus::CANCELLED);
    expect($item->run->refresh()->status)->toBe(TaskStatus::CANCELLED);
    Queue::assertNotPushed(TranscodeMediaFile::class);
});
