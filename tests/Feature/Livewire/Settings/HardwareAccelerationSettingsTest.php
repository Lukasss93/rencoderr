<?php

use App\Encoding\AccelerationPreference;
use App\Encoding\EncoderDetector;
use App\Ffmpeg\Enums\HardwareBackend;
use App\Livewire\Settings\Status;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Process\Factory;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

/** @param array<string, mixed> $responses */
function fakeSettingsHardware(array $responses = []): void
{
    fakeFfmpegDriver();
    Process::preventStrayProcesses();
    Process::fake($responses + [
        '*-version*' => 'ffmpeg version 7.1',
        '*-encoders*' => " V....D h264_nvenc NVIDIA H264 (codec h264)\n V....D h264_qsv Intel H264 (codec h264)\n V....D h264_vaapi VAAPI H264 (codec h264)",
        '*-hwaccels*' => "Hardware acceleration methods:\ncuda\nqsv\nvaapi",
        '*vaapi=status*' => Process::result(exitCode: 1),
        '*=status*' => 'frame=1',
    ]);
}

it('saves the chosen backend for all users and restores it on another visit', function (): void {
    fakeSettingsHardware();
    actingAs(User::factory()->create());

    Livewire::test(Status::class)->assertSet('preferredAcceleration', 'auto')
        ->set('preferredAcceleration', 'qsv')->call('saveHardwareAcceleration')
        ->assertHasNoErrors()->assertDispatched('ts-ui:toast', title: 'Hardware acceleration preference saved.');

    actingAs(User::factory()->create());
    Livewire::test(Status::class)->assertSet('preferredAcceleration', 'qsv')
        ->assertViewHas('effectiveAcceleration', HardwareBackend::QSV);
    expect(app(AccelerationPreference::class)->saved())->toBe('qsv')
        ->and(Setting::query()->count())->toBe(1);
});

it('shows a warning for a saved backend that is no longer available', function (): void {
    Setting::factory()->create(['key' => 'hardware_acceleration', 'value' => 'qsv']);
    fakeSettingsHardware(['*qsv=status:hw*' => Process::result(exitCode: 1)]);

    Livewire::test(Status::class)->assertSet('preferredAcceleration', 'qsv')
        ->assertSee('Your saved hardware backend is unavailable. Encoding will fall back to Software (CPU).')
        ->assertViewHas('effectiveAcceleration', null);
    expect(app(AccelerationPreference::class)->saved())->toBe('qsv');
});

it('rechecks availability when saving and refuses hardware that disappeared after loading', function (): void {
    fakeSettingsHardware(['*qsv=status:hw*' => Process::sequence()->push('frame=1')->push(Process::result(exitCode: 1))]);

    Livewire::test(Status::class)->set('preferredAcceleration', 'qsv')->call('saveHardwareAcceleration')
        ->assertHasErrors(['preferredAcceleration'])
        ->assertSee('Choose an available hardware encoding backend, Automatic, or Software (CPU).');
    expect(Setting::query()->count())->toBe(0);
});

it('rejects unsupported, unavailable and non-encoding backends without changing the preference', function (string $backend): void {
    fakeSettingsHardware();
    $setting = Setting::factory()->create(['key' => 'hardware_acceleration', 'value' => 'qsv']);

    Livewire::test(Status::class)->set('preferredAcceleration', $backend)->call('saveHardwareAcceleration')
        ->assertHasErrors(['preferredAcceleration']);
    expect($setting->refresh()->value)->toBe('qsv');
})->with(['cuda', 'vaapi', 'invalid', 'qsv; touch /tmp/injected', '']);

it('allows switching to software when FFmpeg detection fails', function (): void {
    Setting::factory()->create(['key' => 'hardware_acceleration', 'value' => 'qsv']);
    fakeSettingsHardware(['*-version*' => Process::result(exitCode: 127)]);

    Livewire::test(Status::class)->assertSee('Preferred hardware acceleration')
        ->set('preferredAcceleration', 'software')->call('saveHardwareAcceleration')->assertHasNoErrors()
        ->assertViewHas('effectiveAcceleration', null);
    expect(app(AccelerationPreference::class)->saved())->toBe('software');
});

it('allows choosing installed encoding backends to configure an alternate device', function (): void {
    fakeSettingsHardware();
    $component = Livewire::test(Status::class);
    $document = new DOMDocument;
    $document->loadHTML($component->html(), LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
    $xpath = new DOMXPath($document);

    expect($xpath->evaluate('count(//select[@id="preferred-acceleration"]/option)'))->toBe(5.0)
        ->and($xpath->evaluate('count(//select[@id="preferred-acceleration"]/option[@value="qsv"])'))->toBe(1.0)
        ->and($xpath->evaluate('count(//select[@id="preferred-acceleration"]/option[@value="nvenc"])'))->toBe(1.0)
        ->and($xpath->evaluate('count(//select[@id="preferred-acceleration"]/option[@value="vaapi"])'))->toBe(1.0)
        ->and($xpath->evaluate('count(//select[@id="preferred-acceleration"]/option[@value="cuda"])'))->toBe(0.0);
});

it('saves an alternate device even when the default device is unavailable', function (string $backend, string $device, string $initialization): void {
    fakeSettingsHardware([
        '*'.$initialization.'*' => 'frame=1',
        '*=status*' => Process::result(exitCode: 1),
    ]);

    Livewire::test(Status::class)->set('preferredAcceleration', $backend)
        ->set('preferredDevice', $device)->call('saveHardwareAcceleration')->assertHasNoErrors()
        ->assertViewHas('effectiveAcceleration', HardwareBackend::from($backend));

    Livewire::test(Status::class)->assertSet('preferredAcceleration', $backend)
        ->assertSet('preferredDevice', $device)->assertSee('Current device: '.$device);
})->with([
    'NVIDIA second GPU' => ['nvenc', '1', 'cuda=status:1'],
    'NVIDIA first GPU' => ['nvenc', '0', 'cuda=status:0'],
    'Intel second GPU' => ['qsv', '/dev/dri/renderD129', 'qsv=status:hw,child_device=/dev/dri/renderD129'],
    'VAAPI second GPU' => ['vaapi', '/dev/dri/renderD129', 'vaapi=status:/dev/dri/renderD129'],
]);

it('rejects invalid device selectors before running device checks', function (string $backend, string $device): void {
    fakeSettingsHardware();
    $component = Livewire::test(Status::class)->set('preferredAcceleration', $backend);
    Process::swap((new Factory)->fake());

    $component->set('preferredDevice', $device)->call('saveHardwareAcceleration')
        ->assertHasErrors(['preferredDevice']);

    Process::assertNothingRan();
    expect(Setting::query()->count())->toBe(0);
})->with([
    ['nvenc', '-1'], ['nvenc', 'list'], ['nvenc', '1,primary_ctx=1'],
    ['nvenc', '1; touch /tmp/injected'], ['qsv', '/etc/passwd'],
    ['vaapi', '/dev/dri/renderD129,driver=evil'], ['qsv', "1\n"],
    ['auto', '1'], ['software', '1'],
]);

it('preserves the saved device and cached checks when an alternate device fails', function (): void {
    fakeSettingsHardware(['*cuda=status:1*' => 'frame=1', '*cuda=status:2*' => Process::result(exitCode: 1)]);
    $component = Livewire::test(Status::class)->set('preferredAcceleration', 'nvenc')
        ->set('preferredDevice', '1')->call('saveHardwareAcceleration')->assertHasNoErrors();

    $component->set('preferredDevice', '2')->call('saveHardwareAcceleration')->assertHasErrors(['preferredDevice']);

    Livewire::test(Status::class)->assertSet('preferredDevice', '1')
        ->assertViewHas('effectiveAcceleration', HardwareBackend::NVENC);
});

it('clears device selection when changing backends including automatic and software', function (string $backend): void {
    fakeSettingsHardware();
    Livewire::test(Status::class)->set('preferredAcceleration', 'nvenc')
        ->set('preferredDevice', '0')->call('saveHardwareAcceleration')->assertHasNoErrors()
        ->set('preferredAcceleration', $backend)->assertSet('preferredDevice', '')
        ->call('saveHardwareAcceleration')->assertHasNoErrors();

    Livewire::test(Status::class)->assertSet('preferredDevice', '');
})->with(['qsv', 'auto', 'software']);

it('falls back to software when the saved device disappears and restores it after refresh', function (): void {
    fakeSettingsHardware(['*cuda=status:1*' => Process::sequence()->push('frame=1')->push(Process::result(exitCode: 1))->push('frame=1')]);
    $component = Livewire::test(Status::class)->set('preferredAcceleration', 'nvenc')
        ->set('preferredDevice', '1')->call('saveHardwareAcceleration')->assertHasNoErrors();

    $component->call('refreshDetection')->assertSet('preferredDevice', '1')
        ->assertViewHas('effectiveAcceleration', null)->assertSee('Software (CPU)');
    $component->call('refreshDetection')->assertViewHas('effectiveAcceleration', HardwareBackend::NVENC);
});

it('tests the saved device and discards verification when the device changes', function (string $backend, string $device, string $expectedArguments): void {
    fakeSettingsHardware([
        '*=status*' => 'frame=1',
        '*-c:v*' => 'frame=1',
    ]);
    $component = Livewire::test(Status::class)->set('preferredAcceleration', $backend)
        ->set('preferredDevice', $device)->call('saveHardwareAcceleration')->assertHasNoErrors()
        ->call('testEncoder', 'h264_'.$backend)->assertSee('Verified');

    Process::assertRan(fn (PendingProcess $process): bool => is_array($process->command)
        && $process->timeout === 10 && str_contains(implode(' ', $process->command), $expectedArguments));
    expect(app(EncoderDetector::class)->current()->checks['h264_'.$backend]->message)->toContain('device '.$device);

    $component->set('preferredDevice', '')->call('saveHardwareAcceleration')->assertHasNoErrors();
    expect(app(EncoderDetector::class)->current()->checks)->toBe([]);
    Livewire::test(Status::class)->assertSet('preferredDevice', '')->assertSee('Current device: Default device');
})->with([
    ['nvenc', '1', '-gpu 1'],
    ['qsv', '/dev/dri/renderD129', '-init_hw_device qsv=hw:hw,child_device=/dev/dri/renderD129'],
    ['vaapi', '/dev/dri/renderD129', '-init_hw_device vaapi=hw:/dev/dri/renderD129 -filter_hw_device hw'],
]);

it('prevents saving application preferences after logout', function (): void {
    fakeSettingsHardware();
    $response = actingAs(User::factory()->create())->get(route('settings.status'))->assertOk();
    auth()->logout();
    session()->invalidate();
    Auth::forgetGuards();

    postJson(Livewire::getUpdateUri(), livewireUpdatePayload($response, updates: ['preferredAcceleration' => 'qsv'], calls: [
        ['method' => 'saveHardwareAcceleration', 'params' => []],
    ]), ['X-Livewire' => 'true'])->assertUnauthorized();
    expect(Setting::query()->count())->toBe(0);
});

it('restores the saved backend when it becomes available again', function (): void {
    Setting::factory()->create(['key' => 'hardware_acceleration', 'value' => 'qsv']);
    fakeSettingsHardware(['*qsv=status:hw*' => Process::sequence()->push(Process::result(exitCode: 1))->push('frame=1')]);
    $component = Livewire::test(Status::class);
    $component->assertViewHas('effectiveAcceleration', null);

    $component->call('refreshDetection')->assertSet('preferredAcceleration', 'qsv')
        ->assertViewHas('effectiveAcceleration', HardwareBackend::QSV)
        ->assertDontSee('Your saved hardware backend is unavailable.');
});
