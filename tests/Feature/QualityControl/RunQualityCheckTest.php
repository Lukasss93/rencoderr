<?php

use App\Jobs\CheckMediaQuality;
use App\QualityControl\Actions\CancelQualityCheck;
use App\QualityControl\Actions\RequestQualityCheck;
use App\QualityControl\Actions\RetryQualityCheck;
use App\QualityControl\Actions\RunQualityCheck;
use App\QualityControl\Enums\QualityVerdict;
use App\QualityControl\QualityEvaluator;
use App\QualityControl\QualityRejected;
use App\Tasks\Enums\TaskStatus;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../../Support/QualityControlFixtures.php';

it('records a quality verdict independently from encoding completion', function (): void {
    Queue::fake([CheckMediaQuality::class]);
    $transcode = qualityTranscode();
    $check = app(RequestQualityCheck::class)->handle($transcode);
    $evaluator = Mockery::mock(QualityEvaluator::class);
    $evaluator->shouldReceive('evaluate')->once()->andReturn(['verdict' => QualityVerdict::PASSED, 'vmaf_mean' => 96.25]);
    app()->instance(QualityEvaluator::class, $evaluator);

    app(RunQualityCheck::class)->handle($check->id, $check->request_token);

    expect($check->refresh()->status)->toBe(TaskStatus::COMPLETED);
    expect($check->verdict)->toBe(QualityVerdict::PASSED);
    expect($check->vmaf_mean)->toBe(96.25);
    expect($transcode->refresh()->status)->toBe(TaskStatus::COMPLETED);
    Queue::assertPushed(CheckMediaQuality::class, 1);
});

it('distinguishes rejected output from a failed check', function (bool $rejected): void {
    Queue::fake([CheckMediaQuality::class]);
    $check = app(RequestQualityCheck::class)->handle(qualityTranscode());
    $evaluator = Mockery::mock(QualityEvaluator::class);
    $evaluator->shouldReceive('evaluate')->once()->andThrow($rejected
        ? new QualityRejected('video_duration', 'The video is truncated.') : new RuntimeException('libvmaf is unavailable.'));
    app()->instance(QualityEvaluator::class, $evaluator);

    app(RunQualityCheck::class)->handle($check->id, $check->request_token);

    expect($check->refresh()->status)->toBe($rejected ? TaskStatus::COMPLETED : TaskStatus::FAILED);
    expect($check->verdict)->toBe($rejected ? QualityVerdict::REJECTED : null);
    Queue::assertPushed(CheckMediaQuality::class, 1);
})->with([true, false]);

it('does not execute stale or duplicate deliveries', function (): void {
    Queue::fake([CheckMediaQuality::class]);
    $check = app(RequestQualityCheck::class)->handle(qualityTranscode());
    $evaluator = Mockery::mock(QualityEvaluator::class);
    $evaluator->shouldNotReceive('evaluate');
    app()->instance(QualityEvaluator::class, $evaluator);

    app(RunQualityCheck::class)->handle($check->id, 'stale');
    $check->update(['status' => TaskStatus::RUNNING]);
    app(RunQualityCheck::class)->handle($check->id, $check->request_token);

    expect($check->refresh()->attempt_token)->toBeNull();
    Queue::assertPushed(CheckMediaQuality::class, 1);
});

it('gives cancellation priority over a computed passing verdict', function (): void {
    Queue::fake();
    $check = app(RequestQualityCheck::class)->handle(qualityTranscode());
    $evaluator = Mockery::mock(QualityEvaluator::class);
    $evaluator->shouldReceive('evaluate')->once()->andReturnUsing(function () use ($check): array {
        app(CancelQualityCheck::class)->handle($check);

        return ['verdict' => QualityVerdict::PASSED];
    });
    app()->instance(QualityEvaluator::class, $evaluator);
    app(RunQualityCheck::class)->handle($check->id, $check->request_token);
    expect($check->refresh()->status)->toBe(TaskStatus::CANCELLED)->and($check->verdict)->toBeNull();
});

it('does not let a stale failure callback overwrite a later attempt', function (): void {
    Queue::fake();
    $check = app(RequestQualityCheck::class)->handle(qualityTranscode());
    $oldJob = new CheckMediaQuality($check->id, $check->request_token, $check->execution_limit_seconds);
    $check->update(['status' => TaskStatus::FAILED]);
    app(RetryQualityCheck::class)->handle($check);
    $oldJob->failed(new RuntimeException('Old timeout'));
    expect($check->refresh()->status)->toBe(TaskStatus::QUEUED)->and($check->error)->toBeNull();
});

it('honors a cancellation request when the worker failure callback runs', function (): void {
    Queue::fake();
    $check = app(RequestQualityCheck::class)->handle(qualityTranscode());
    $check->update(['status' => TaskStatus::RUNNING, 'cancel_requested_at' => now()]);
    (new CheckMediaQuality($check->id, $check->request_token, $check->execution_limit_seconds))->failed(new RuntimeException('Timeout'));
    expect($check->refresh()->status)->toBe(TaskStatus::CANCELLED)->and($check->verdict)->toBeNull();
});
