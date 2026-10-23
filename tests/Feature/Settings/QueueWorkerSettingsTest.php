<?php

use App\Models\Setting;
use App\Settings\ApplicationSettings;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Laravel\Horizon\AutoScaler;
use Laravel\Horizon\Contracts\HorizonCommandQueue;
use Laravel\Horizon\Contracts\MetricsRepository;
use Laravel\Horizon\Events\SupervisorLooped;
use Laravel\Horizon\MasterSupervisor;
use Laravel\Horizon\MasterSupervisorCommands\AddSupervisor;
use Laravel\Horizon\ProcessPool;
use Laravel\Horizon\ProvisioningPlan;
use Laravel\Horizon\Supervisor;
use Laravel\Horizon\SupervisorOptions;
use Laravel\Horizon\WorkerProcess;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Process\Process;

use function Pest\Laravel\mock;

it('uses the configured maximum until a worker limit is saved', function (): void {
    Config::set('horizon.defaults.supervisor-1.maxProcesses', 3);
    $settings = app(ApplicationSettings::class);

    expect($settings->queueMaxWorkers())->toBe(3);

    $settings->saveQueueMaxWorkers('5');
    Config::set('horizon.defaults.supervisor-1.maxProcesses', 2);

    expect(app(ApplicationSettings::class)->queueMaxWorkers())->toBe(5)
        ->and(Setting::query()->where('key', 'queue_max_workers')->firstOrFail()->value)->toBe(5);
});

it('rejects invalid worker limits without replacing the saved value', function (mixed $value): void {
    Setting::factory()->create(['key' => 'queue_max_workers', 'value' => 2]);

    expect(fn () => app(ApplicationSettings::class)->saveQueueMaxWorkers($value))->toThrow(ValidationException::class);
    expect(app(ApplicationSettings::class)->queueMaxWorkers())->toBe(2);
})->with(['empty' => [''], 'zero' => [0], 'negative' => [-1], 'fraction' => ['1.5'], 'text' => ['many'], 'array' => [[2]], 'overflow' => ['999999999999999999999']]);

it('loads the saved limit before Horizon provisions supervisors', function (): void {
    mock(HorizonCommandQueue::class)->shouldReceive('flush');
    Setting::factory()->create(['key' => 'queue_max_workers', 'value' => 4]);
    Config::set('horizon.defaults.supervisor-1.maxProcesses', 1);
    $options = ProvisioningPlan::get('test-master')->optionsFor('*', 'supervisor-1');
    $master = new MasterSupervisor;

    app(AddSupervisor::class)->process($master, $options->toArray());
    $supervisor = $master->supervisors->first();

    expect($supervisor->options->maxProcesses)->toBe(4)
        ->and($supervisor->options->minProcesses)->toBe(1)
        ->and($supervisor->options->queue)->toBe('default,probes')
        ->and($supervisor->process->getCommandLine())->toContain('--max-processes=4');
});

it('uses the saved limit before a replacement supervisor starts with stale options', function (): void {
    mock(HorizonCommandQueue::class)->shouldReceive('flush');
    Setting::factory()->create(['key' => 'queue_max_workers', 'value' => 1]);
    $staleOptions = new SupervisorOptions('test-master:supervisor-1', 'redis', 'default,probes', maxProcesses: 10);
    $master = new MasterSupervisor;

    app(AddSupervisor::class)->process($master, $staleOptions->toArray());
    $replacement = $master->supervisors->first();

    expect($replacement->options->maxProcesses)->toBe(1)
        ->and($replacement->process->getCommandLine())->toContain('--max-processes=1 ')
        ->and($replacement->process->isStarted())->toBeFalse();
});

it('retries supervisor provisioning after a temporary settings read failure', function (): void {
    $pending = [];
    $commands = mock(HorizonCommandQueue::class);
    $commands->shouldReceive('flush');
    $master = new MasterSupervisor;
    $commands->shouldReceive('push')->once()->withArgs(function (string $queue, string $command, array $options) use (&$pending, $master): bool {
        $pending[] = ['command' => $command, 'options' => $options];

        return $queue === MasterSupervisor::commandQueueFor($master->name);
    });
    $settings = mock(ApplicationSettings::class);
    $settings->shouldReceive('queueMaxWorkers')->once()->ordered()->andThrow(new RuntimeException('Database unavailable'));
    $settings->shouldReceive('queueMaxWorkers')->once()->ordered()->andReturn(2);
    $staleOptions = new SupervisorOptions('test-master:supervisor-1', 'redis', 'default,probes', maxProcesses: 10);

    expect(fn () => app(AddSupervisor::class)->process($master, $staleOptions->toArray()))
        ->toThrow(RuntimeException::class, 'Database unavailable');
    expect($master->supervisors)->toHaveCount(0);
    expect($pending)->toHaveCount(1);

    foreach ($pending as $command) {
        app($command['command'])->process($master, $command['options']);
    }

    expect($master->supervisors)->toHaveCount(1)
        ->and($master->supervisors->first()->options->maxProcesses)->toBe(2)
        ->and($master->supervisors->first()->process->isStarted())->toBeFalse();
});

it('does not read settings for unrelated Artisan commands', function (string $command): void {
    mock(ApplicationSettings::class)->shouldNotReceive('queueMaxWorkers');

    Event::dispatch(new CommandStarting($command, new ArrayInput([]), new NullOutput));
})->with(['config:cache', 'migrate', 'package:discover']);

it('refreshes running supervisor limits without resuming or restarting workers', function (bool $working): void {
    mock(HorizonCommandQueue::class)->shouldReceive('flush');
    $options = new SupervisorOptions('test-master:supervisor-1', 'redis', 'default,probes', balance: 'off');
    $supervisor = new Supervisor($options);
    $supervisor->working = $working;
    $pool = $supervisor->processPools->first();
    $settings = app(ApplicationSettings::class);

    foreach ([5, 2, 2] as $limit) {
        $settings->saveQueueMaxWorkers($limit);
        Event::dispatch(new SupervisorLooped($supervisor));

        expect($supervisor->options->maxProcesses)->toBe($limit)
            ->and($supervisor->options->minProcesses)->toBe(1)
            ->and($supervisor->working)->toBe($working)
            ->and($supervisor->processPools->first())->toBe($pool);
    }

    $replacement = new Supervisor(new SupervisorOptions('new-master:supervisor-1', 'redis', 'default,probes', balance: 'off'));
    Event::dispatch(new SupervisorLooped($replacement));

    expect($replacement->options->maxProcesses)->toBe(2);
})->with(['running' => true, 'paused' => false]);

it('leaves unrelated supervisors alone', function (): void {
    mock(HorizonCommandQueue::class)->shouldReceive('flush');
    mock(ApplicationSettings::class)->shouldNotReceive('queueMaxWorkers');
    $supervisor = new Supervisor(new SupervisorOptions('test-master:other', 'redis', maxProcesses: 3));

    Event::dispatch(new SupervisorLooped($supervisor));

    expect($supervisor->options->maxProcesses)->toBe(3);
});

it('keeps the running limit when settings cannot be read', function (): void {
    mock(HorizonCommandQueue::class)->shouldReceive('flush');
    mock(ApplicationSettings::class)->shouldReceive('queueMaxWorkers')->andThrow(new RuntimeException('Database unavailable'));
    $supervisor = new Supervisor(new SupervisorOptions('test-master:supervisor-1', 'redis', maxProcesses: 3));

    expect(fn () => Event::dispatch(new SupervisorLooped($supervisor)))->toThrow(RuntimeException::class, 'Database unavailable');
    expect($supervisor->options->maxProcesses)->toBe(3);
});

it('lets the native scaler grow under load and gracefully retire excess workers', function (): void {
    mock(HorizonCommandQueue::class)->shouldReceive('flush');
    $supervisor = new Supervisor(new SupervisorOptions('test-master:supervisor-1', 'redis', 'default,probes', balance: 'off'));
    $pool = Mockery::mock(ProcessPool::class, [$supervisor->options])->makePartial()->shouldAllowMockingProtectedMethods();
    $processes = [];

    for ($index = 0; $index < 3; $index++) {
        $process = Mockery::mock(Process::class);
        $process->shouldReceive('isRunning')->andReturn(true);
        $process->shouldNotReceive('start');
        $process->shouldNotReceive('stop');
        if ($index === 0) {
            $process->shouldReceive('signal')->once()->with(SIGTERM);
        } else {
            $process->shouldNotReceive('signal');
        }
        if (! $process instanceof Process) {
            throw new LogicException('Worker process doubles must implement the Symfony process API.');
        }
        $processes[] = new WorkerProcess($process);
    }

    $pool->shouldReceive('createProcess')->times(3)->andReturn(...$processes);
    $supervisor->processPools = collect([$pool]);
    $queue = Mockery::mock(QueueFactory::class);
    $queue->shouldReceive('connection->readyNow')->andReturn(100);
    $metrics = Mockery::mock(MetricsRepository::class);
    $metrics->shouldReceive('runtimeForQueue')->andReturn(1);
    if (! $queue instanceof QueueFactory || ! $metrics instanceof MetricsRepository) {
        throw new LogicException('Horizon doubles must implement the queue and metrics contracts.');
    }
    $scaler = new AutoScaler($queue, $metrics);
    $settings = app(ApplicationSettings::class);

    $settings->saveQueueMaxWorkers(3);
    Event::dispatch(new SupervisorLooped($supervisor));
    for ($step = 0; $step < 4; $step++) {
        $scaler->scale($supervisor);
    }

    expect($supervisor->processes())->toHaveCount(3);

    $settings->saveQueueMaxWorkers(2);
    Event::dispatch(new SupervisorLooped($supervisor));
    $scaler->scale($supervisor);

    expect($supervisor->processes())->toHaveCount(2)
        ->and($supervisor->terminatingProcesses())->toHaveCount(1)
        ->and($supervisor->totalProcessCount())->toBe(3);
});

it('builds and boots cached configuration before the database exists', function (): void {
    $directory = mediaDirectory([]);
    $environment = [
        'APP_CONFIG_CACHE' => $directory.'/config.php',
        'DB_CONNECTION' => 'sqlite',
        'DB_DATABASE' => $directory.'/missing.sqlite',
        'DB_URL' => '',
    ];

    $cache = new Process([PHP_BINARY, 'artisan', 'config:cache', '--no-interaction'], base_path(), $environment);
    $cache->mustRun();
    $boot = new Process([PHP_BINARY, 'artisan', 'route:list', '--name=settings.workers', '--json'], base_path(), $environment);
    $boot->mustRun();

    expect($boot->getOutput())->toContain('settings.workers')
        ->and(file_exists($directory.'/config.php'))->toBeTrue()
        ->and(file_exists($directory.'/missing.sqlite'))->toBeFalse();
});
