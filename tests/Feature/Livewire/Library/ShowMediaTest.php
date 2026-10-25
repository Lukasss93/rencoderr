<?php

use App\Jobs\TranscodeMediaFile;
use App\Livewire\Library\ShowMedia;
use App\Models\MediaFile;
use App\Models\MediaStream;
use App\Models\MediaTranscode;
use App\Models\TranscodeRun;
use App\Models\User;
use App\Tasks\Enums\TaskStatus;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\postJson;

require_once __DIR__.'/../../../Support/TranscodeFixtures.php';

it('shows file information, streams, and only that files transcode history', function (): void {
    $file = transcodeSource();
    $probe = $file->probe()->firstOrFail();
    $probe->update(['format' => 'matroska', 'duration_seconds' => 120, 'bit_rate' => 4000000, 'probed_at' => now()]);
    MediaStream::factory()->for($probe, 'probe')->create(['width' => 1920, 'height' => 1080, 'pixel_format' => 'yuv420p', 'average_frame_rate' => '24000/1001']);
    MediaStream::factory()->for($probe, 'probe')->create(['stream_index' => 1, 'codec_type' => 'audio', 'codec_name' => 'aac', 'language' => 'eng', 'channels' => 6, 'channel_layout' => '5.1', 'sample_rate' => 48000]);
    MediaStream::factory()->for($probe, 'probe')->create(['stream_index' => 2, 'codec_type' => 'subtitle', 'codec_name' => 'subrip', 'language' => 'ita', 'is_forced' => true]);
    MediaTranscode::factory()->for($file)->create(['status' => TaskStatus::COMPLETED, 'encoder' => 'libx265', 'output_path' => 'retained/movie.mkv', 'output_size_bytes' => 3]);
    MediaTranscode::factory()->for($file)->create(['status' => TaskStatus::FAILED, 'error' => 'Encoder failed for this movie']);
    MediaTranscode::factory()->create(['error' => 'Unrelated file failure']);

    actingAs(User::factory()->create())->get(route('media.show', $file))->assertOk()
        ->assertSee('movie.mkv')->assertSee($file->libraryPath()->firstOrFail()->path)->assertSee('matroska')
        ->assertSee('Streams')->assertSee('1920 × 1080')->assertSee('24000/1001')
        ->assertSee('aac')->assertSee('eng')->assertSee('5.1')->assertSee('subrip')->assertSee('ita')->assertSee('Forced')
        ->assertSee('Transcode history')->assertSee('Awaiting quality control')->assertSee('libx265')
        ->assertSee('retained/movie.mkv')->assertSee('Encoder failed for this movie')->assertDontSee('Unrelated file failure');
});

it('shows empty metadata and history and marks old metadata as stale', function (): void {
    $file = MediaFile::factory()->create();
    Livewire::test(ShowMedia::class, ['mediaFile' => $file])->assertSee('Not probed')
        ->assertSee('No streams available.')->assertSee('No transcodes yet.');

    $file = transcodeSource();
    $file->update(['size_bytes' => 99]);
    Livewire::test(ShowMedia::class, ['mediaFile' => $file])->assertSee('Stale metadata')
        ->assertSee('Current metadata is required before transcoding.');
});

it('refreshes live status and paginates transcode history newest first', function (): void {
    $file = transcodeSource();
    MediaTranscode::factory()->for($file)->count(10)->create(['status' => TaskStatus::COMPLETED, 'encoder' => 'old-encoder']);
    $item = MediaTranscode::factory()->for($file)->create(['status' => TaskStatus::QUEUED, 'encoder' => 'latest-encoder']);
    $page = Livewire::test(ShowMedia::class, ['mediaFile' => $file]);
    $page->assertViewHas('transcodes', fn ($items): bool => $items->total() === 11 && $items->count() === 10);
    $page->assertSeeInOrder(['latest-encoder', 'old-encoder']);
    $item->update(['status' => TaskStatus::RUNNING, 'progress_percent' => 42.5]);
    $page->call('$refresh')->assertSee('42.5%')->assertSee('Running')
        ->call('setPage', 2)->assertDontSee('latest-encoder')->assertSee('old-encoder');
});

it('requests a transcode from the media detail page', function (): void {
    Queue::fake();
    $file = transcodeSource();

    Livewire::test(ShowMedia::class, ['mediaFile' => $file])->call('transcode')->assertHasNoErrors()
        ->assertDispatched('ts-ui:toast', title: 'Media transcode requested.');

    expect(TranscodeRun::query()->sole()->transcodes()->sole()->media_file_id)->toBe($file->id);
    Queue::assertPushed(TranscodeMediaFile::class, 1);
});

it('validates the current file state when a detail page starts a transcode', function (): void {
    Queue::fake();
    $file = transcodeSource();
    $page = Livewire::test(ShowMedia::class, ['mediaFile' => $file]);
    $file->probe()->update(['status' => TaskStatus::FAILED]);

    $page->call('transcode')->assertHasErrors('transcode')->assertSee('Current metadata is required before transcoding.');
    Queue::assertNothingPushed();
});

it('requires authentication for media details and rejects actions after session expiry', function (): void {
    Queue::fake();
    $file = transcodeSource();
    get(route('media.show', $file))->assertRedirectToRoute('login');
    actingAs(User::factory()->create());
    $page = get(route('media.show', $file))->assertOk();
    Auth::logout();
    session()->invalidate();
    Auth::forgetGuards();

    postJson(Livewire::getUpdateUri(), livewireUpdatePayload($page, calls: [
        ['method' => 'transcode', 'params' => []],
    ]), ['X-Livewire' => 'true'])->assertUnauthorized();
    expect(TranscodeRun::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('supports disabled authentication and returns not found for a removed media file', function (): void {
    Config::set('auth.enabled', false);
    $file = transcodeSource();
    get(route('media.show', $file))->assertOk();
    $file->delete();
    get(route('media.show', $file->id))->assertNotFound();
});

it('escapes stream titles and transcode diagnostics', function (): void {
    $file = transcodeSource();
    $unsafe = '<script>alert("media")</script>';
    MediaStream::factory()->for($file->probe()->firstOrFail(), 'probe')->create(['title' => $unsafe]);
    MediaTranscode::factory()->for($file)->create(['diagnostic_tail' => $unsafe, 'error' => $unsafe]);

    Livewire::test(ShowMedia::class, ['mediaFile' => $file])->assertSee($unsafe)->assertDontSeeHtml($unsafe);
});

it('shows estimated storage savings without claiming disk space has been reclaimed', function (): void {
    $file = transcodeSource();
    MediaTranscode::factory()->for($file)->create(['phase' => 'confirming', 'status' => TaskStatus::RUNNING,
        'optimization' => ['enabled' => true, 'candidates' => [['quality' => 32]],
            'winner' => ['quality' => 32, 'encoder' => 'libx265', 'estimated_savings_percent' => 45.0]]]);
    Livewire::test(ShowMedia::class, ['mediaFile' => $file])->assertSee('Confirming')->assertSee('45.0%')
        ->assertSee('1 candidates measured')->assertSee('Reclaimed disk space: 0 bytes');
});

it('shows the closest measured quality and size when adaptive optimization cannot qualify an output', function (): void {
    $file = transcodeSource();
    MediaTranscode::factory()->for($file)->create(['status' => TaskStatus::SKIPPED,
        'optimization' => ['enabled' => true, 'policy' => ['quality_floor' => 93.0],
            'closest' => ['encoder' => 'hevc_vaapi', 'quality' => 26, 'vmaf' => 90.5, 'bytes' => 1370000000, 'within_size_budget' => true]]]);
    Livewire::test(ShowMedia::class, ['mediaFile' => $file])->assertSee('Closest measured result: hevc_vaapi')
        ->assertSee('90.50')->assertSee('required 93.00')->assertSee('Within size budget');
});
