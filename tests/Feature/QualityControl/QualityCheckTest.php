<?php

use App\Jobs\CheckMediaQuality;
use App\Models\MediaTranscode;
use App\QualityControl\Actions\CancelQualityCheck;
use App\QualityControl\Actions\RequestQualityCheck;
use App\QualityControl\Actions\RetryQualityCheck;
use App\Tasks\Enums\TaskStatus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/QualityControlFixtures.php';

it('queues one quality check per accepted output and freezes its settings', function (): void {
    Queue::fake([CheckMediaQuality::class]);
    $transcode = qualityTranscode();
    config(['quality_control.sample_seconds' => 20]);

    $check = app(RequestQualityCheck::class)->handle($transcode);
    $transcode->settings()->where('key', 'media.minimum_vmaf')->update(['value' => '99']);
    config(['quality_control.sample_seconds' => 5]);
    $repeated = app(RequestQualityCheck::class)->handle($transcode);

    expect($repeated->id)->toBe($check->id);
    expect($check->refresh()->policy['media']['minimum_vmaf'])->toBe(95);
    expect($check->policy['sample_seconds'])->toBe(20);
    expect($check->transcode_attempt_token)->toBe($transcode->attempt_token);
    Queue::assertPushed(CheckMediaQuality::class, 1);
    Queue::assertPushed(CheckMediaQuality::class, fn (CheckMediaQuality $job): bool => $job->checkId === $check->id
        && $job->requestToken === $check->request_token && $job->connection === 'redis-quality-control' && $job->queue === 'quality-control');
});

it('does not request quality control for an unfinished encode', function (): void {
    Queue::fake([CheckMediaQuality::class]);

    expect(fn () => app(RequestQualityCheck::class)->handle(MediaTranscode::factory()->create()))->toThrow(LogicException::class);

    Queue::assertNothingPushed();
});

it('cancels waiting quality checks without changing the accepted transcode', function (): void {
    Queue::fake([CheckMediaQuality::class]);
    $transcode = qualityTranscode();
    $check = app(RequestQualityCheck::class)->handle($transcode);

    app(CancelQualityCheck::class)->handle($check);

    expect($check->refresh()->status)->toBe(TaskStatus::CANCELLED);
    expect($transcode->refresh()->status)->toBe(TaskStatus::COMPLETED);
    expect($transcode->output_path)->not->toBeNull();
    Queue::assertPushed(CheckMediaQuality::class, 1);
});

it('requests running cancellation and leaves completion to the process owner', function (): void {
    Queue::fake([CheckMediaQuality::class]);
    $check = app(RequestQualityCheck::class)->handle(qualityTranscode());
    $check->update(['status' => TaskStatus::RUNNING, 'attempt_token' => (string) Str::uuid()]);

    app(CancelQualityCheck::class)->handle($check);

    expect($check->refresh()->status)->toBe(TaskStatus::RUNNING);
    expect($check->cancel_requested_at)->not->toBeNull();
    Queue::assertPushed(CheckMediaQuality::class, 1);
});

it('retries with a new request token and current resources while keeping the measurement policy', function (): void {
    Queue::fake([CheckMediaQuality::class]);
    $check = app(RequestQualityCheck::class)->handle(qualityTranscode());
    $token = $check->request_token;
    $policy = $check->policy;
    $check->update(['status' => TaskStatus::FAILED, 'error' => 'Temporary decoder error']);
    config(['quality_control.timeout_seconds' => 120, 'quality_control.threads' => 3, 'quality_control.sample_seconds' => 5]);

    app(RetryQualityCheck::class)->handle($check);

    expect($check->refresh()->status)->toBe(TaskStatus::QUEUED);
    expect($check->request_token)->not->toBe($token);
    expect($check->policy)->toBe($policy);
    expect($check->execution_limit_seconds)->toBe(120);
    expect($check->threads)->toBe(3);
    Queue::assertPushed(CheckMediaQuality::class, 2);
});
