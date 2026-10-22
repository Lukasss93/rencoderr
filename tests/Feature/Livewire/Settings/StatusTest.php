<?php

use App\Livewire\Settings\Status;
use App\Models\User;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process as SymfonyProcess;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\postJson;

function healthEncoderListing(): string
{
    return <<<'OUTPUT'
Encoders:
 V..... = Video
 A..... = Audio
 ------
 V....D libx264              libx264 H.264 / AVC (codec h264)
 V....D h264_nvenc           NVIDIA NVENC H.264 encoder (codec h264)
 V....D hevc_vaapi           H.265 VAAPI (codec hevc)
 V..... av1_qsv              AV1 Intel Quick Sync (codec av1)
 V....D h264_vulkan          H.264 Vulkan (codec h264)
 A....D aac                  AAC (Advanced Audio Coding)
 A....D libopus              libopus Opus (codec opus)
 S..... srt                  SubRip subtitle (codec subrip)
OUTPUT;
}

beforeEach(fn () => fakeFfmpegDriver());

it('shows hardware and software encoders in separate categories for each media type', function (): void {
    Process::preventStrayProcesses();
    Process::fake([
        '*-hwaccels*' => "Hardware acceleration methods:\nvaapi\nqsv\ncuda\nvulkan\n",
        '*=status*' => Process::result(exitCode: 1), '*-version*' => 'ffmpeg version 7.1 Copyright', '*-encoders*' => healthEncoderListing()]);

    $response = actingAs(User::factory()->create())->get('/settings/status')->assertOk()
        ->assertSee('Status')->assertSee('7.1')->assertSee('Video encoders')->assertSee('Audio encoders')
        ->assertSee('libx264')->assertSee('h264_nvenc')->assertSee('aac')->assertSee('libopus')
        ->assertSee('Hardware acceleration')->assertSee('Intel Quick Sync (QSV)')->assertSee('NVIDIA NVENC')
        ->assertSee('No hardware audio encoders detected.')
        ->assertSee('FFmpeg supported only')->assertDontSee('Not tested')->assertDontSee('Verified')->assertDontSee('SubRip subtitle');

    $document = new DOMDocument;
    $document->loadHTML((string) $response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
    $navigation = new DOMXPath($document);
    expect($navigation->evaluate('string(//nav[@aria-label="Settings navigation"]/a[@aria-current="page"]/@href)'))
        ->toBe(route('settings.status'));
    expect($navigation->evaluate('count(//section[@aria-label="Video hardware encoders"]//p[contains(@class,"font-mono")])'))->toBe(0.0)
        ->and($navigation->evaluate('count(//section[@aria-label="Video supported encoders"]//p[contains(@class,"font-mono")])'))->toBe(4.0)
        ->and($navigation->evaluate('normalize-space(//section[@aria-label="Video software encoders"]//p[contains(@class,"font-mono")])'))->toBe('libx264')
        ->and($navigation->evaluate('count(//section[@aria-label="Audio software encoders"]//p[contains(@class,"font-mono")])'))->toBe(2.0)
        ->and($navigation->evaluate('normalize-space(//nav[@aria-label="Settings navigation"]/a[1])'))->toBe('Status')
        ->and($navigation->evaluate('string(//button[@aria-label="Test encoder h264_nvenc"]/@*[name()="wire:click"])'))->toBe('testEncoder("h264_nvenc")');
    Process::assertRanTimes(fn (PendingProcess $process): bool => true, 8);
});

it('protects health detection and refresh after logout', function (): void {
    Process::preventStrayProcesses();
    Process::fake([
        '*-hwaccels*' => "Hardware acceleration methods:\nvaapi\nqsv\ncuda\nvulkan\n",
        '*=status*' => Process::result(exitCode: 1), '*-version*' => 'ffmpeg version 7.1', '*-encoders*' => healthEncoderListing()]);
    get('/settings/status')->assertRedirectToRoute('login');
    Process::assertNothingRan();

    $response = actingAs(User::factory()->create())->get('/settings/status')->assertOk();
    auth()->logout();
    session()->invalidate();
    Auth::forgetGuards();

    postJson(Livewire::getUpdateUri(), livewireUpdatePayload($response, calls: [
        ['method' => 'refreshDetection', 'params' => []],
    ]), ['X-Livewire' => 'true'])->assertUnauthorized();
    Process::assertRanTimes(fn (PendingProcess $process): bool => true, 8);
});

it('allows local access when authentication is disabled', function (): void {
    Config::set('auth.enabled', false);
    Process::preventStrayProcesses();
    Process::fake([
        '*-hwaccels*' => "Hardware acceleration methods:\nvaapi\nqsv\ncuda\nvulkan\n",
        '*=status*' => Process::result(exitCode: 1), '*-version*' => 'ffmpeg version 7.1', '*-encoders*' => healthEncoderListing()]);

    get('/settings/status')->assertOk()->assertSee('libx264');
    Process::assertRanTimes(fn (PendingProcess $process): bool => true, 8);
});

it('shows an actionable error when ffmpeg is missing', function (): void {
    Process::preventStrayProcesses();
    Process::fake([
        '*-hwaccels*' => "Hardware acceleration methods:\nvaapi\nqsv\ncuda\nvulkan\n",
        '*=status*' => Process::result(exitCode: 1), '*-version*' => Process::result(errorOutput: 'not found', exitCode: 127)]);

    Livewire::test(Status::class)->assertSee('FFmpeg unavailable')
        ->assertSee('Install FFmpeg')->assertDontSee('Video encoders');
    Process::assertRanTimes(fn (PendingProcess $process): bool => true, 1);
});

it('keeps the version but reports failed or empty encoder detection', function (string $output, int $exitCode): void {
    Process::preventStrayProcesses();
    Process::fake([
        '*-hwaccels*' => "Hardware acceleration methods:\nvaapi\nqsv\ncuda\nvulkan\n",
        '*=status*' => Process::result(exitCode: 1),
        '*-version*' => 'ffmpeg version 7.1',
        '*-encoders*' => Process::result(output: $output, exitCode: $exitCode),
    ]);

    Livewire::test(Status::class)->assertSee('7.1')->assertSee('Detection failed')
        ->assertDontSee('Video encoders');
    Process::assertRanTimes(fn (PendingProcess $process): bool => true, 2);
})->with(['failed command' => ['', 1], 'empty listing' => ['Encoders:', 0], 'unexpected output' => ['invalid output', 0]]);

it('replaces the previous detection and clears hardware verification on refresh', function (): void {
    Process::preventStrayProcesses();
    Process::fake([
        '*-hwaccels*' => "Hardware acceleration methods:\nvaapi\nqsv\ncuda\nvulkan\n",
        '*=status*' => Process::result(exitCode: 1),
        '*-version*' => 'ffmpeg version 7.1',
        '*-encoders*' => Process::sequence()->push(healthEncoderListing())->push(' A....D flac FLAC'),
        '*-c:v*' => "frame=1\nprogress=end",
    ]);
    $component = Livewire::test(Status::class)->call('testEncoder', 'h264_nvenc')->assertSee('Verified');

    $component->call('refreshDetection')->assertSee('flac')->assertDontSee('h264_nvenc')
        ->assertDontSee('Verified')->assertSet('checks', []);
    Process::assertRanTimes(fn (PendingProcess $process): bool => true, 16);
});

it('verifies hardware only after a successful one frame encode', function (string $encoder, string $filter, ?string $device, string $backend): void {
    Process::preventStrayProcesses();
    Process::fake([
        '*-hwaccels*' => "Hardware acceleration methods:\nvaapi\nqsv\ncuda\nvulkan\n",
        '*=status*' => Process::result(exitCode: 1),
        '*-version*' => 'ffmpeg version 7.1', '*-encoders*' => healthEncoderListing(),
        '*-c:v*' => "frame=1\nprogress=end",
    ]);

    Livewire::test(Status::class)->call('testEncoder', $encoder)
        ->assertSet('checks.'.$encoder.'.successful', true)->assertSee('Verified')
        ->assertSet('report.acceleration.backends.'.$backend.'.available', true);

    Process::assertRan(fn (PendingProcess $process): bool => is_array($process->command)
        && in_array('color=c=black:s=1280x720:r=1', $process->command, true)
        && array_slice($process->command, -12) === [
            '-vf', $filter, '-frames:v', '1', '-an', '-c:v', $encoder, '-progress', 'pipe:1', '-f', 'null', '-',
        ]
        && ($device === null || array_slice($process->command, 5, 2) === ['-init_hw_device', $device])
        && ($filter !== 'format=nv12,hwupload' || array_slice($process->command, 7, 2) === ['-filter_hw_device', 'health'])
        && $process->timeout === 10);
})->with([
    'NVIDIA' => ['h264_nvenc', 'format=nv12', null, 'nvenc'],
    'VAAPI' => ['hevc_vaapi', 'format=nv12,hwupload', 'vaapi=health', 'vaapi'],
    'Quick Sync' => ['av1_qsv', 'format=nv12', 'qsv=health:hw', 'qsv'],
]);

it('does not verify an encoder when the test fails or produces no frames', function (string $output, int $exitCode): void {
    Process::preventStrayProcesses();
    Process::fake([
        '*-hwaccels*' => "Hardware acceleration methods:\nvaapi\nqsv\ncuda\nvulkan\n",
        '*=status*' => Process::result(exitCode: 1),
        '*-version*' => 'ffmpeg version 7.1', '*-encoders*' => healthEncoderListing(),
        '*-c:v*' => Process::result(output: $output, errorOutput: 'Device unavailable', exitCode: $exitCode),
    ]);

    Livewire::test(Status::class)->call('testEncoder', 'h264_nvenc')
        ->assertSet('checks.h264_nvenc.successful', false)->assertSee('Test failed')->assertDontSee('Verified');
    Process::assertRanTimes(fn (PendingProcess $process): bool => true, 9);
})->with(['failed' => ['', 1], 'no frame' => ['frame=0', 0]]);

it('rejects unknown and unsupported encoders without starting a test', function (string $encoder): void {
    Process::preventStrayProcesses();
    Process::fake([
        '*-hwaccels*' => "Hardware acceleration methods:\nvaapi\nqsv\ncuda\nvulkan\n",
        '*=status*' => Process::result(exitCode: 1), '*-version*' => 'ffmpeg version 7.1', '*-encoders*' => healthEncoderListing()]);

    Livewire::test(Status::class)->call('testEncoder', $encoder)->assertNotFound();
    Process::assertRanTimes(fn (PendingProcess $process): bool => true, 8);
})->with(['h264_nvenc; touch /tmp/injected', 'hevc_nvenc', 'libx264', 'h264_vulkan']);

it('reports detection timeouts without crashing the settings page', function (string $command): void {
    Process::preventStrayProcesses();
    Process::fake([
        '*-hwaccels*' => "Hardware acceleration methods:\nvaapi\nqsv\ncuda\nvulkan\n",
        '*=status*' => Process::result(exitCode: 1),
        '*-version*' => 'ffmpeg version 7.1',
        '*'.$command.'*' => fn () => throw new ProcessTimedOutException(new SymfonyProcess(['ffmpeg'], timeout: 5), ProcessTimedOutException::TYPE_GENERAL),
    ]);

    Livewire::test(Status::class)->assertSee('FFmpeg detection timed out.')->assertDontSee('Video encoders');
})->with(['-version', '-encoders']);

it('reports an encoder timeout without marking it verified', function (): void {
    Process::preventStrayProcesses();
    Process::fake([
        '*-hwaccels*' => "Hardware acceleration methods:\nvaapi\nqsv\ncuda\nvulkan\n",
        '*=status*' => Process::result(exitCode: 1),
        '*-version*' => 'ffmpeg version 7.1', '*-encoders*' => healthEncoderListing(),
        '*-c:v*' => fn () => throw new ProcessTimedOutException(new SymfonyProcess(['ffmpeg'], timeout: 10), ProcessTimedOutException::TYPE_GENERAL),
    ]);

    Livewire::test(Status::class)->call('testEncoder', 'h264_nvenc')
        ->assertSee('timed out after 10 seconds')->assertSee('Test failed')->assertDontSee('Verified');
});

it('reports failures to start ffmpeg', function (): void {
    Process::preventStrayProcesses();
    Process::fake([
        '*-hwaccels*' => "Hardware acceleration methods:\nvaapi\nqsv\ncuda\nvulkan\n",
        '*=status*' => Process::result(exitCode: 1), '*-version*' => fn () => throw new RuntimeException('Process creation disabled')]);

    Livewire::test(Status::class)->assertSee('FFmpeg could not be started.')->assertDontSee('Video encoders');
});

it('reports failures to start an encoder test', function (): void {
    Process::preventStrayProcesses();
    Process::fake([
        '*-hwaccels*' => "Hardware acceleration methods:\nvaapi\nqsv\ncuda\nvulkan\n",
        '*=status*' => Process::result(exitCode: 1),
        '*-version*' => 'ffmpeg version 7.1', '*-encoders*' => healthEncoderListing(),
        '*-c:v*' => fn () => throw new RuntimeException('Process creation disabled'),
    ]);

    Livewire::test(Status::class)->call('testEncoder', 'h264_nvenc')
        ->assertSee('FFmpeg could not start the encoder test.')->assertSee('Test failed')->assertDontSee('Verified');
});

it('escapes encoder descriptions and failure details', function (): void {
    Process::preventStrayProcesses();
    $markup = '<script>alert("ffmpeg")</script>';
    Process::fake([
        '*-hwaccels*' => "Hardware acceleration methods:\nvaapi\nqsv\ncuda\nvulkan\n",
        '*=status*' => Process::result(exitCode: 1),
        '*-version*' => 'ffmpeg version 7.1',
        '*-encoders*' => ' V....D h264_nvenc '.$markup.' (codec h264)',
        '*-c:v*' => Process::result(errorOutput: $markup, exitCode: 1),
    ]);

    Livewire::test(Status::class)->call('testEncoder', 'h264_nvenc')
        ->assertSee($markup)->assertDontSeeHtml($markup);
    Process::assertRanTimes(fn (PendingProcess $process): bool => true, 9);
});

it('only counts and groups hardware encoders with an available backend', function (): void {
    Process::preventStrayProcesses();
    Process::fake([
        '*-version*' => 'ffmpeg version 7.1',
        '*-encoders*' => healthEncoderListing(),
        '*-hwaccels*' => "Hardware acceleration methods:\nvaapi\nqsv\ncuda\nvulkan",
        '*qsv=status:hw*' => 'frame=1',
        '*=status*' => Process::result(exitCode: 1),
        '*-c:v*' => Process::result(errorOutput: 'Unsupported codec', exitCode: 1),
    ]);

    $component = Livewire::test(Status::class);
    $component->assertViewHas('videoCount', 2)
        ->assertViewHas('audioCount', 2)
        ->assertViewHas('groups', fn (array $groups): bool => array_column($groups['Video']['Hardware']['av1'], 'name') === ['av1_qsv']
            && ! isset($groups['Video']['Hardware']['h264'])
            && array_column($groups['Video']['Software']['h264'], 'name') === ['libx264']
            && array_column($groups['Video']['Supported']['h264'], 'name') === ['h264_nvenc', 'h264_vulkan'])
        ->assertDontSee('Not tested');

    $component->call('testEncoder', 'av1_qsv')
        ->assertSet('report.acceleration.backends.qsv.available', true)
        ->assertViewHas('videoCount', 1)
        ->assertViewHas('groups', fn (array $groups): bool => $groups['Video']['Hardware'] === []
            && array_column($groups['Video']['Supported']['av1'], 'name') === ['av1_qsv']);
});
