<?php

use App\Livewire\Settings\Workers;
use App\Models\Setting;
use App\Models\User;
use App\Workers\Enums\WorkerPool;
use App\Workers\Enums\WorkerState;
use App\Workers\WorkerLimit;
use App\Workers\WorkerStatus;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Exceptions;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\mock;
use function Pest\Laravel\postJson;

beforeEach(function (): void {
    mock(SupervisorRepository::class)->shouldReceive('all')->andReturn([])->byDefault();
});

it('shows worker settings inside the authenticated settings section', function (): void {
    get(route('settings.workers'))->assertRedirectToRoute('login');

    actingAs(User::factory()->create())->get(route('settings.workers'))->assertOk()
        ->assertSee('Maximum workers')->assertSee('Settings navigation');

    Livewire::test(Workers::class)->assertSeeHtml('href="'.route('horizon.index').'"')
        ->assertDontSeeHtml('wire:navigate');
});

it('saves the maximum while Horizon is offline and restores it for another user', function (): void {
    Config::set('horizon.defaults.supervisor-1.maxProcesses', 2);
    actingAs(User::factory()->create());

    Livewire::test(Workers::class)->assertSet('maxWorkers', '2')
        ->set('maxWorkers', '4')->call('save')->assertHasNoErrors()
        ->assertDispatched('ts-ui:toast', title: 'Worker limit saved.')->assertSee('The saved limit will apply when Horizon starts.');

    actingAs(User::factory()->create());
    Livewire::test(Workers::class)->assertSet('maxWorkers', '4');

    expect(app(WorkerLimit::class)->get())->toBe(4)
        ->and(Setting::query()->where('key', 'queue_max_workers')->count())->toBe(1);
});

it('displays validation feedback without saving an invalid maximum', function (string $value): void {
    Livewire::test(Workers::class)->set('maxWorkers', $value)->call('save')
        ->assertHasErrors('maxWorkers')->assertNotDispatched('ts-ui:toast');

    expect(Setting::query()->where('key', 'queue_max_workers')->exists())->toBeFalse();
})->with(['', '0', '-1', '1.5', 'many', '999999999999999999999']);

it('shows the reported limit and worker count independently from the saved maximum', function (string $state): void {
    Setting::factory()->create(['key' => 'queue_max_workers', 'value' => 4]);
    mock(SupervisorRepository::class)->shouldReceive('all')->andReturn([
        (object) ['name' => 'local:other', 'status' => 'running', 'processes' => ['redis:other' => 10], 'options' => ['maxProcesses' => 10]],
        (object) ['name' => 'local:supervisor-1', 'status' => $state, 'processes' => ['redis:scans,probes' => 2], 'options' => ['maxProcesses' => 3]],
    ]);

    Livewire::test(Workers::class)->assertViewHas('status', fn (WorkerStatus $status): bool => $status->state === WorkerState::from($state) && $status->limit === 3 && $status->workers === 2)
        ->assertViewHas('savedLimit', 4)->assertSee('Waiting for Horizon to pick up the saved limit.');
})->with(['running', 'paused']);

it('refreshes status without overwriting unsaved input', function (): void {
    $component = Livewire::test(Workers::class)->set('maxWorkers', '7');
    Setting::factory()->create(['key' => 'queue_max_workers', 'value' => 3]);

    $component->call('$refresh')->assertSet('maxWorkers', '7')->assertViewHas('savedLimit', 3);
});

it('clears validation feedback after correcting the worker limit', function (): void {
    Livewire::test(Workers::class)->set('maxWorkers', '0')->call('save')->assertHasErrors('maxWorkers')
        ->set('maxWorkers', '2')->call('save')->assertHasNoErrors()->assertDispatched('ts-ui:toast', title: 'Worker limit saved.');

    expect(app(WorkerLimit::class)->get())->toBe(2);
});

it('distinguishes unavailable status from offline Horizon and still saves the setting', function (): void {
    Exceptions::fake();
    mock(SupervisorRepository::class)->shouldReceive('all')->andThrow(new RuntimeException('Redis unavailable'));

    Livewire::test(Workers::class)->assertSee('Status unavailable')
        ->assertDontSee('The saved limit will apply when Horizon starts.')
        ->set('maxWorkers', '3')->call('save')->assertHasNoErrors()->assertDispatched('ts-ui:toast', title: 'Worker limit saved.');

    expect(app(WorkerLimit::class)->get())->toBe(3);
    Exceptions::assertReported(RuntimeException::class);
});

it('allows worker settings when application authentication is disabled', function (): void {
    Config::set('auth.enabled', false);

    get(route('settings.workers'))->assertOk();
    Livewire::test(Workers::class)->set('maxWorkers', '3')->call('save')->assertHasNoErrors();

    expect(app(WorkerLimit::class)->get())->toBe(3);
});

it('rejects worker setting updates after logout', function (): void {
    $response = actingAs(User::factory()->create())->get(route('settings.workers'))->assertOk();
    auth()->logout();
    session()->invalidate();
    Auth::forgetGuards();

    postJson(Livewire::getUpdateUri(), livewireUpdatePayload($response, updates: ['maxWorkers' => '5'], calls: [
        ['method' => 'save', 'params' => []],
    ]), ['X-Livewire' => 'true'])->assertUnauthorized();

    expect(Setting::query()->where('key', 'queue_max_workers')->exists())->toBeFalse();
});

it('saves transcode workers independently from unsaved standard input', function (): void {
    $limit = app(WorkerLimit::class);
    $limit->save(4);
    Livewire::test(Workers::class)->assertSet('transcodeMaxWorkers', '1')
        ->set('maxWorkers', 'invalid')->set('transcodeMaxWorkers', '2')->call('saveTranscodes')
        ->assertHasNoErrors()->assertDispatched('ts-ui:toast', title: 'Transcode worker limit saved.');

    expect($limit->get())->toBe(4)->and($limit->get(WorkerPool::TRANSCODES))->toBe(2);
    Livewire::test(Workers::class)->assertSet('maxWorkers', '4')->assertSet('transcodeMaxWorkers', '2');
});

it('rejects invalid transcode limits without changing either saved pool', function (string $value): void {
    $limit = app(WorkerLimit::class);
    $limit->save(4);
    Livewire::test(Workers::class)->set('transcodeMaxWorkers', $value)->call('saveTranscodes')
        ->assertHasErrors('transcodeMaxWorkers')->assertNotDispatched('ts-ui:toast');
    expect($limit->get())->toBe(4)->and(Setting::valueOf('transcode_max_workers'))->toBeNull();
})->with(['', '0', '-1', '1.5', 'many', '999999999999999999999']);
