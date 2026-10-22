<?php

use App\Livewire\Settings\Status;
use App\Models\Setting;
use App\Models\User;
use App\Settings\ApplicationSettings;
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
        ->assertHasNoErrors()->assertSee('Hardware acceleration preference saved.');

    actingAs(User::factory()->create());
    Livewire::test(Status::class)->assertSet('preferredAcceleration', 'qsv')
        ->assertViewHas('effectiveAcceleration', 'qsv');
    expect(app(ApplicationSettings::class)->hardwareAcceleration())->toBe('qsv')
        ->and(Setting::query()->count())->toBe(1);
});

it('shows a warning for a saved backend that is no longer available', function (): void {
    Setting::factory()->create(['key' => 'hardware_acceleration', 'value' => 'qsv']);
    fakeSettingsHardware(['*qsv=status:hw*' => Process::result(exitCode: 1)]);

    Livewire::test(Status::class)->assertSet('preferredAcceleration', 'qsv')
        ->assertSee('Your saved hardware backend is unavailable. Encoding will fall back to Software (CPU).')
        ->assertViewHas('effectiveAcceleration', null);
    expect(app(ApplicationSettings::class)->hardwareAcceleration())->toBe('qsv');
});

it('rechecks availability when saving and refuses hardware that disappeared after loading', function (): void {
    fakeSettingsHardware(['*qsv=status:hw*' => Process::sequence()->push('frame=1')->push(Process::result(exitCode: 1))]);

    Livewire::test(Status::class)->set('preferredAcceleration', 'qsv')->call('saveHardwareAcceleration')
        ->assertHasErrors(['preferredAcceleration'])
        ->assertSee('Choose an available hardware encoding backend, Automatic, or Software (CPU).');
    expect(Setting::query()->count())->toBe(0);
});

it('allows switching to software when FFmpeg detection fails', function (): void {
    Setting::factory()->create(['key' => 'hardware_acceleration', 'value' => 'qsv']);
    fakeSettingsHardware(['*-version*' => Process::result(exitCode: 127)]);

    Livewire::test(Status::class)->assertSee('Preferred hardware acceleration')
        ->set('preferredAcceleration', 'software')->call('saveHardwareAcceleration')->assertHasNoErrors()
        ->assertViewHas('effectiveAcceleration', null);
    expect(app(ApplicationSettings::class)->hardwareAcceleration())->toBe('software');
});

it('only displays hardware encoding backends that can be selected', function (): void {
    fakeSettingsHardware();
    $component = Livewire::test(Status::class);
    $document = new DOMDocument;
    $document->loadHTML($component->html(), LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
    $xpath = new DOMXPath($document);

    expect($xpath->evaluate('count(//select[@id="preferred-acceleration"]/option)'))->toBe(4.0)
        ->and($xpath->evaluate('count(//select[@id="preferred-acceleration"]/option[@value="qsv"])'))->toBe(1.0)
        ->and($xpath->evaluate('count(//select[@id="preferred-acceleration"]/option[@value="nvenc"])'))->toBe(1.0)
        ->and($xpath->evaluate('count(//select[@id="preferred-acceleration"]/option[@value="cuda" or @value="vaapi"])'))->toBe(0.0);
});

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
        ->assertViewHas('effectiveAcceleration', 'qsv')
        ->assertDontSee('Your saved hardware backend is unavailable.');
});
