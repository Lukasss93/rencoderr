<?php

use App\Jobs\QueueLibraryTranscodes;
use App\Jobs\TranscodeMediaFile;
use App\Models\MediaTranscode;
use App\Models\TranscodeRun;
use App\Tasks\Enums\TaskStatus;
use App\Transcoding\Actions\DispatchTranscode;
use App\Transcoding\Actions\RecoverTranscodes;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

use function Pest\Laravel\freezeTime;
use function Pest\Laravel\travel;

it('replays unacknowledged publication with the same request and owner but leaves confirmed queues alone', function (): void {
    Queue::fake();
    freezeTime();
    $item = MediaTranscode::factory()->create();
    app(DispatchTranscode::class)->handle($item->id);
    $item->refresh();
    $owner = $item->unique_lock_owner;
    $token = $item->request_token;
    $item->update(['dispatched_at' => null]);
    app(RecoverTranscodes::class)->handle();
    expect($item->refresh()->unique_lock_owner)->toBe($owner);
    expect($item->request_token)->toBe($token);
    expect($item->dispatched_at)->not->toBeNull();
    travel(30)->days();
    app(RecoverTranscodes::class)->handle();
    Queue::assertPushed(TranscodeMediaFile::class, 2);
});

it('keeps publication failures pending and releases only its own lock', function (): void {
    $item = MediaTranscode::factory()->create();
    $bus = Mockery::mock(Dispatcher::class);
    $bus->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Redis unavailable'));
    app()->instance(Dispatcher::class, $bus);
    expect(fn () => app(DispatchTranscode::class)->handle($item->id))->toThrow(RuntimeException::class, 'Redis unavailable');
    expect($item->refresh()->status)->toBe(TaskStatus::PENDING);
    expect($item->unique_lock_owner)->toBeNull();
    $locks = new UniqueLock(Cache::store());
    expect($locks->acquire(new TranscodeMediaFile($item->id, $item->file_identity_id, $item->request_token, $item->execution_limit_seconds)))->toBeTrue();
});

it('waits for the saved deadline plus margin and overlap permission before reclaiming a lost worker', function (): void {
    Queue::fake();
    Storage::fake('transcodes');
    freezeTime();
    $item = MediaTranscode::factory()->create([
        'status' => TaskStatus::RUNNING, 'attempt_token' => (string) Str::uuid(),
        'execution_limit_seconds' => 30, 'execution_deadline_at' => now()->addSeconds(30), 'heartbeat_at' => now()->subDays(10),
    ]);
    $job = app(DispatchTranscode::class)->job($item);
    $overlap = Cache::lock($job->middleware()[0]->getLockKey($job), 1000);
    $overlap->get();
    $directory = $item->id.'/'.$item->attempt_token;
    Storage::disk('transcodes')->put($directory.'/output.mkv', 'uncommitted rename');
    $token = $item->request_token;
    app(RecoverTranscodes::class)->handle();
    expect($item->refresh()->status)->toBe(TaskStatus::RUNNING);
    travel(151)->seconds();
    app(RecoverTranscodes::class)->handle();
    expect($item->refresh()->status)->toBe(TaskStatus::RUNNING);
    $overlap->release();
    app(RecoverTranscodes::class)->handle();
    expect($item->refresh()->status)->toBe(TaskStatus::QUEUED);
    expect($item->request_token)->not->toBe($token);
    expect($item->execution_limit_seconds)->toBe(30);
    Storage::disk('transcodes')->assertMissing($directory.'/output.mkv');
    Queue::assertPushed(TranscodeMediaFile::class, 1);
});

it('clears stale native locks and rotates outstanding tokens only at exclusive startup', function (): void {
    Queue::fake();
    Storage::fake('transcodes');
    $item = MediaTranscode::factory()->create();
    app(DispatchTranscode::class)->handle($item->id);
    $item->refresh();
    $oldJob = app(DispatchTranscode::class)->job($item);
    $overlapKey = $oldJob->middleware()[0]->getLockKey($oldJob);
    Cache::lock($overlapKey, 900)->get();
    app(RecoverTranscodes::class)->handle(exclusive: true);
    expect($item->refresh()->status)->toBe(TaskStatus::QUEUED);
    expect($item->request_token)->not->toBe($oldJob->requestToken);
    $oldJob->failed(new RuntimeException('stale failure'));
    expect($item->refresh()->status)->toBe(TaskStatus::QUEUED);
    expect(Cache::lock($overlapKey, 10)->get())->toBeTrue();
    Queue::assertPushed(TranscodeMediaFile::class, 2);
});

it('resumes unfinished enumeration without changing the cursor or request during periodic reconciliation', function (): void {
    Queue::fake();
    $run = TranscodeRun::factory()->create(['inventory_cursor_id' => 200, 'inventory_cutoff_id' => 350]);
    $token = $run->request_token;
    app(RecoverTranscodes::class)->handle();
    expect($run->refresh()->inventory_cursor_id)->toBe(200);
    expect($run->request_token)->toBe($token);
    Queue::assertPushed(QueueLibraryTranscodes::class, fn (QueueLibraryTranscodes $job): bool => $job->runId === $run->id && $job->requestToken === $token);
});

it('keeps completed output and cancels stranded work whose library was deleted', function (): void {
    Queue::fake();
    Storage::fake('transcodes');
    $completed = MediaTranscode::factory()->create(['status' => TaskStatus::COMPLETED, 'output_path' => 'retained/output.mkv']);
    Storage::disk('transcodes')->put((string) $completed->output_path, 'keep');
    $pending = MediaTranscode::factory()->create();
    $pending->run->library()->firstOrFail()->delete();
    app(RecoverTranscodes::class)->handle();
    expect($pending->refresh()->status)->toBe(TaskStatus::CANCELLED);
    expect($pending->run->refresh()->status)->toBe(TaskStatus::CANCELLED);
    Storage::disk('transcodes')->assertExists((string) $completed->output_path);
});

it('recovers an interrupted attempt when releasing its queue message fails', function (): void {
    Queue::fake();
    $item = MediaTranscode::factory()->create([
        'status' => TaskStatus::QUEUED, 'attempt_token' => (string) Str::uuid(), 'dispatched_at' => now(), 'unique_lock_owner' => 'saved-owner',
    ]);
    $job = new TranscodeMediaFile($item->id, $item->file_identity_id, $item->request_token, $item->execution_limit_seconds);
    $job->failed(new RuntimeException('Redis release failed'));
    app(RecoverTranscodes::class)->handle();
    expect($item->refresh()->status)->toBe(TaskStatus::QUEUED);
    Queue::assertPushed(TranscodeMediaFile::class, 1);
});

it('keeps manual reconciliation read only until apply is requested', function (): void {
    Queue::fake();
    $item = MediaTranscode::factory()->create();
    artisanCommand('transcodes:reconcile', ['--json' => true])
        ->expectsOutput('{"dry_run":true,"active_runs":1,"active_files":1,"recovered_files":0}')->assertSuccessful();
    expect($item->refresh()->status)->toBe(TaskStatus::PENDING);
    Queue::assertNothingPushed();
    artisanCommand('transcodes:reconcile', ['--apply' => true, '--plain' => true])->expectsOutput('recovered_files=1')->assertSuccessful();
    expect($item->refresh()->status)->toBe(TaskStatus::QUEUED);
});
