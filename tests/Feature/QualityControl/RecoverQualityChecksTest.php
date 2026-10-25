<?php

use App\Jobs\CheckMediaQuality;
use App\QualityControl\Actions\RecoverQualityChecks;
use App\QualityControl\Actions\RequestQualityCheck;
use App\QualityControl\Enums\QualityVerdict;
use App\Tasks\Enums\TaskStatus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/QualityControlFixtures.php';

it('rotates interrupted requests, cleans only their temporary files and backfills accepted encodes', function (): void {
    Queue::fake();
    $disk = Storage::fake('transcodes');
    $running = app(RequestQualityCheck::class)->handle(qualityTranscode());
    $running->update(['status' => TaskStatus::RUNNING, 'attempt_token' => (string) Str::uuid(), 'model_sha256' => str_repeat('a', 64)]);
    $oldToken = $running->request_token;
    $temporary = $running->media_transcode_id.'/'.$running->transcode_attempt_token.'/quality-control/'.$running->attempt_token.'/scores.xml';
    $output = $running->media_transcode_id.'/'.$running->transcode_attempt_token.'/output.mkv';
    $disk->put($temporary, 'partial');
    $disk->put($output, 'accepted');
    $missing = qualityTranscode();
    $terminal = app(RequestQualityCheck::class)->handle(qualityTranscode());
    $terminal->update(['status' => TaskStatus::COMPLETED, 'verdict' => QualityVerdict::REJECTED]);
    Queue::fake();

    expect(app(RecoverQualityChecks::class)->handle())->toBe(2);

    expect($running->refresh()->status)->toBe(TaskStatus::QUEUED)
        ->and($running->request_token)->not->toBe($oldToken)
        ->and($running->attempt_token)->toBeNull()
        ->and($running->model_sha256)->toBe(str_repeat('a', 64));
    expect($missing->qualityCheck()->exists())->toBeTrue();
    expect($terminal->refresh()->verdict)->toBe(QualityVerdict::REJECTED);
    $disk->assertMissing($temporary);
    expect($disk->get($output))->toBe('accepted');
    Queue::assertPushed(CheckMediaQuality::class, 2);
});

it('cancels interrupted checks whose source was removed or cancellation was requested', function (bool $remove): void {
    Queue::fake();
    $check = app(RequestQualityCheck::class)->handle(qualityTranscode());
    if ($remove) {
        $check->transcode()->firstOrFail()->mediaFile()->firstOrFail()->delete();
    } else {
        $check->update(['cancel_requested_at' => now()]);
    }
    Queue::fake();

    expect(app(RecoverQualityChecks::class)->handle())->toBe(0);
    expect($check->refresh()->status)->toBe(TaskStatus::CANCELLED);
    Queue::assertNothingPushed();
})->with([true, false]);
