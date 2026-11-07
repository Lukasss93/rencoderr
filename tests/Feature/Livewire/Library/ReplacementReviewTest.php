<?php

use App\Jobs\WriteMediaFile;
use App\Library\FileFingerprint;
use App\Livewire\Dashboard;
use App\Livewire\Library\ShowMedia;
use App\Livewire\Queue as QueuePage;
use App\Models\MediaQualityCheck;
use App\Models\MediaTranscode;
use App\Models\MediaWriteback;
use App\Models\User;
use App\QualityControl\Enums\QualityVerdict;
use App\Tasks\Enums\TaskStatus;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

require_once __DIR__.'/../../../Support/TranscodeFixtures.php';

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\postJson;

it('reviews saved source and output facts before replacing the original', function (): void {
    $file = transcodeSource();
    $transcode = MediaTranscode::factory()->for($file)->create(['status' => TaskStatus::COMPLETED]);
    $check = MediaQualityCheck::factory()->for($transcode, 'transcode')->create([
        'status' => TaskStatus::COMPLETED, 'verdict' => QualityVerdict::PASSED, 'vmaf_mean' => 96.7, 'savings_percent' => 40.0,
    ]);
    $receipt = MediaWriteback::factory()->for($transcode, 'transcode')->for($check, 'qualityCheck')->create([
        'status' => TaskStatus::PENDING, 'phase' => 'awaiting_approval',
        'candidate_path' => '/library/movie.rencoderr.mkv', 'destination_path' => '/library/movie.mkv',
        'comparison' => [
            'source' => ['size_bytes' => 10000, 'container' => 'mpegts', 'streams' => [
                ['index' => 0, 'type' => 'video', 'codec_name' => 'h264', 'language' => null],
                ['index' => 1, 'type' => 'audio', 'codec_name' => 'ac3', 'language' => 'eng'],
                ['index' => 2, 'type' => 'subtitle', 'codec_name' => 'subrip', 'language' => 'ita'],
            ]],
            'output' => ['size_bytes' => 6000, 'container' => 'matroska', 'streams' => [
                ['index' => 0, 'type' => 'video', 'codec_name' => 'hevc', 'language' => null],
                ['index' => 1, 'type' => 'audio', 'codec_name' => 'aac', 'language' => 'eng'],
                ['index' => 2, 'type' => 'subtitle', 'codec_name' => 'subrip', 'language' => 'ita'],
            ]],
        ],
    ]);
    $file->probe()->update(['format' => 'changed-container']);

    Livewire::test(ShowMedia::class, ['mediaFile' => $file])
        ->assertSee('Review replacement')->assertSee('Reclaimed disk space: 0 bytes')
        ->call('reviewReplacement', $transcode->id)->assertSet('showReplacementReview', true)
        ->assertSet('selectedWritebackId', $receipt->id)->assertSee('10,000 bytes')->assertSee('6,000 bytes')
        ->assertSee('4,000 bytes')->assertSee('40.0%')->assertSee('mpegts')->assertSee('hevc')->assertSee('ac3')
        ->assertSee('aac')->assertSee('ita')->assertSee('96.70')->assertSee('video quality score')
        ->assertSee('/library/movie.rencoderr.mkv')->assertSee('/library/movie.mkv')->assertSee('Replace original');
});

it('does not offer replacement review for an unapproved quality result or failed receipt', function (string $status, string $phase, string $verdict): void {
    $file = transcodeSource();
    $transcode = MediaTranscode::factory()->for($file)->create(['status' => TaskStatus::COMPLETED]);
    $check = MediaQualityCheck::factory()->for($transcode, 'transcode')->create([
        'status' => TaskStatus::COMPLETED, 'verdict' => $verdict,
    ]);
    MediaWriteback::factory()->for($transcode, 'transcode')->for($check, 'qualityCheck')->create([
        'status' => $status, 'phase' => $phase,
    ]);

    Livewire::test(ShowMedia::class, ['mediaFile' => $file])->assertDontSee('Review replacement')
        ->call('reviewReplacement', $transcode->id)->assertHasErrors('writeback')
        ->assertSet('showReplacementReview', false);
})->with([
    'rejected quality' => ['pending', 'awaiting_approval', 'rejected'],
    'failed receipt' => ['failed', 'copying', 'passed'],
    'already replacing' => ['queued', 'replacing', 'passed'],
]);

it('does not review another media files replacement', function (): void {
    $file = transcodeSource();
    $other = MediaTranscode::factory()->create();

    Livewire::test(ShowMedia::class, ['mediaFile' => $file])->call('reviewReplacement', $other->id)->assertNotFound();
});

it('rejects client changes to the selected replacement receipt', function (): void {
    $file = transcodeSource();

    expect(fn () => Livewire::test(ShowMedia::class, ['mediaFile' => $file])
        ->set('selectedWritebackId', 123))->toThrow(CannotUpdateLockedPropertyException::class);
});

it('queues approval once and leaves both files unchanged during the request', function (): void {
    Queue::fake([WriteMediaFile::class]);
    $user = User::factory()->create();
    actingAs($user);
    $file = transcodeSource();
    $source = $file->libraryPath()->firstOrFail()->path.'/movie.mkv';
    $candidate = $file->libraryPath()->firstOrFail()->path.'/.replacement.mkv';
    file_put_contents($candidate, 'copy');
    $attempt = (string) Str::uuid();
    $transcode = MediaTranscode::factory()->for($file)->create(['status' => TaskStatus::COMPLETED, 'attempt_token' => $attempt]);
    $check = MediaQualityCheck::factory()->for($transcode, 'transcode')->create([
        'status' => TaskStatus::COMPLETED, 'verdict' => QualityVerdict::PASSED, 'transcode_attempt_token' => $attempt,
    ]);
    $receipt = MediaWriteback::factory()->for($transcode, 'transcode')->for($check, 'qualityCheck')->create([
        'status' => TaskStatus::PENDING, 'phase' => 'awaiting_approval', 'source_path' => $source,
        'candidate_path' => $candidate, 'source_fingerprint' => FileFingerprint::capture($source),
        'output_fingerprint' => FileFingerprint::capture($candidate),
    ]);

    Livewire::test(ShowMedia::class, ['mediaFile' => $file])->call('reviewReplacement', $transcode->id)
        ->call('replaceOriginal')->assertHasNoErrors()->assertSet('showReplacementReview', false)
        ->assertSet('selectedWritebackId', null)->call('replaceOriginal')->assertHasNoErrors();

    expect($receipt->refresh()->status)->toBe(TaskStatus::QUEUED)
        ->and($receipt->approved_by)->toBe($user->id);
    expect(file_get_contents($source))->toBe('video');
    expect(file_get_contents($candidate))->toBe('copy');
    Queue::assertPushed(WriteMediaFile::class, 1);
});

it('refuses replacement if the review result changes before approval', function (): void {
    Queue::fake();
    $file = transcodeSource();
    $transcode = MediaTranscode::factory()->for($file)->create(['status' => TaskStatus::COMPLETED]);
    $check = MediaQualityCheck::factory()->for($transcode, 'transcode')->create([
        'status' => TaskStatus::COMPLETED, 'verdict' => QualityVerdict::PASSED,
    ]);
    $receipt = MediaWriteback::factory()->for($transcode, 'transcode')->for($check, 'qualityCheck')->create([
        'status' => TaskStatus::PENDING, 'phase' => 'awaiting_approval',
    ]);
    $component = Livewire::test(ShowMedia::class, ['mediaFile' => $file])->call('reviewReplacement', $transcode->id);
    $check->update(['verdict' => QualityVerdict::REJECTED]);

    $component->call('replaceOriginal')->assertHasErrors('writeback')->assertDontSee('Replace original');

    expect($receipt->refresh()->approved_at)->toBeNull();
    Queue::assertNothingPushed();
});

it('distinguishes disabled video scoring from missing measurements', function (array $results, string $message): void {
    $file = transcodeSource();
    $transcode = MediaTranscode::factory()->for($file)->create(['status' => TaskStatus::COMPLETED]);
    $check = MediaQualityCheck::factory()->for($transcode, 'transcode')->create([
        'status' => TaskStatus::COMPLETED, 'verdict' => QualityVerdict::PASSED, 'vmaf_mean' => null, 'results' => $results,
    ]);
    MediaWriteback::factory()->for($transcode, 'transcode')->for($check, 'qualityCheck')->create([
        'status' => TaskStatus::PENDING, 'phase' => 'awaiting_approval', 'comparison' => [],
    ]);

    Livewire::test(ShowMedia::class, ['mediaFile' => $file])->call('reviewReplacement', $transcode->id)->assertSee($message);
})->with([
    'disabled scoring' => [['video_quality' => 'disabled'], 'Disabled — not measured'],
    'missing measurement' => [[], 'Not measured'],
]);

it('links to replacement review from the dashboard and queue', function (): void {
    $file = transcodeSource();
    $transcode = MediaTranscode::factory()->for($file)->create(['status' => TaskStatus::COMPLETED]);
    $check = MediaQualityCheck::factory()->for($transcode, 'transcode')->create([
        'status' => TaskStatus::COMPLETED, 'verdict' => QualityVerdict::PASSED,
    ]);
    MediaWriteback::factory()->for($transcode, 'transcode')->for($check, 'qualityCheck')->create([
        'status' => TaskStatus::PENDING, 'phase' => 'awaiting_approval',
    ]);

    Livewire::test(Dashboard::class)->assertSee('Review replacement')->assertSeeHtml('href="'.route('media.show', $file).'"');
    Livewire::test(QueuePage::class)->assertSee('Review replacement')->assertSeeHtml('href="'.route('media.show', $file).'"');
});

it('rejects replacement actions after the authenticated session expires', function (): void {
    Queue::fake();
    $file = transcodeSource();
    actingAs(User::factory()->create());
    $page = get(route('media.show', $file))->assertOk();
    Auth::logout();
    session()->invalidate();
    Auth::forgetGuards();

    postJson(Livewire::getUpdateUri(), livewireUpdatePayload($page, calls: [
        ['method' => 'replaceOriginal', 'params' => []],
    ]), ['X-Livewire' => 'true'])->assertUnauthorized();

    Queue::assertNothingPushed();
});

it('claims reclaimed space only after replacement and cleanup complete', function (string $status, ?string $installedAt, ?string $cleanedAt, string $message): void {
    $file = transcodeSource();
    $transcode = MediaTranscode::factory()->for($file)->create(['status' => TaskStatus::COMPLETED]);
    MediaWriteback::factory()->for($transcode, 'transcode')->create([
        'status' => $status, 'phase' => 'installed', 'installed_at' => $installedAt, 'staging_cleaned_at' => $cleanedAt,
        'comparison' => ['source' => ['size_bytes' => 10000], 'output' => ['size_bytes' => 6000]],
    ]);

    Livewire::test(ShowMedia::class, ['mediaFile' => $file])->assertSee($message);
})->with([
    'waiting' => ['pending', null, null, 'Reclaimed disk space: 0 bytes'],
    'cleanup failed' => ['failed', '2026-11-07 12:00:00', null, 'Reclaimed disk space: 0 bytes'],
    'staging cleanup pending' => ['completed', '2026-11-07 12:00:00', null, 'Reclaimed disk space: 0 bytes'],
    'installed' => ['completed', '2026-11-07 12:00:00', '2026-11-07 12:00:01', 'Reclaimed disk space: 4,000 bytes'],
]);

it('escapes replacement errors and keeps failed results unavailable for approval', function (): void {
    $file = transcodeSource();
    $transcode = MediaTranscode::factory()->for($file)->create(['status' => TaskStatus::COMPLETED]);
    $error = '<script>alert("replacement")</script>';
    MediaWriteback::factory()->for($transcode, 'transcode')->create([
        'status' => TaskStatus::FAILED, 'phase' => 'copying', 'error' => $error,
    ]);

    Livewire::test(ShowMedia::class, ['mediaFile' => $file])->assertSee('Replacement failed')
        ->assertSee($error)->assertDontSeeHtml($error)->assertDontSee('Review replacement');
});

it('retries a failed replacement without touching the files in the request', function (): void {
    Queue::fake([WriteMediaFile::class]);
    $file = transcodeSource();
    $transcode = MediaTranscode::factory()->for($file)->create(['status' => TaskStatus::COMPLETED]);
    $receipt = MediaWriteback::factory()->for($transcode, 'transcode')->create([
        'status' => TaskStatus::FAILED, 'error' => 'The library folder is not writable.',
    ]);

    Livewire::test(ShowMedia::class, ['mediaFile' => $file])->assertSee('Retry write-back')
        ->call('retryWriteback', $receipt->id)->assertHasNoErrors();

    expect($receipt->refresh()->status)->toBe(TaskStatus::QUEUED)->and($receipt->error)->toBeNull();
    expect(file_get_contents($file->libraryPath()->firstOrFail()->path.'/movie.mkv'))->toBe('video');
    Queue::assertPushed(WriteMediaFile::class, 1);
});

it('does not retry another media files failed replacement', function (): void {
    Queue::fake();
    $file = transcodeSource();
    $receipt = MediaWriteback::factory()->create(['status' => TaskStatus::FAILED]);

    Livewire::test(ShowMedia::class, ['mediaFile' => $file])->call('retryWriteback', $receipt->id)->assertNotFound();

    expect($receipt->refresh()->status)->toBe(TaskStatus::FAILED);
    Queue::assertNothingPushed();
});
