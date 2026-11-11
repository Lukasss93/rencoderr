<?php

use App\Models\Setting;
use App\Workers\Enums\WorkerPool;
use App\Workers\Enums\WorkerState;
use App\Workers\HorizonWorkers;
use App\Workers\WorkerLimit;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Laravel\Horizon\AutoScaler;
use Laravel\Horizon\Contracts\HorizonCommandQueue;
use Laravel\Horizon\Contracts\MetricsRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;
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
use Tevkert\QueuePrefork\Client\Broker;
use Tevkert\QueuePrefork\Client\ForkedProcess;
use Tevkert\QueuePrefork\Horizon\PreforkAddSupervisor;
use Tevkert\QueuePrefork\Master as PreforkMaster;
use Tevkert\QueuePrefork\Preflight;
use Tevkert\QueuePrefork\Support\Channel;

use function Pest\Laravel\mock;

it('isolates quality-control workers and gives their reservation enough shutdown time', function (): void {
    $limit = app(WorkerLimit::class);
    $limit->save(3, WorkerPool::QUALITY_CONTROL);
    $quality = ProvisioningPlan::get('test-master')->optionsFor('*', WorkerPool::QUALITY_CONTROL->supervisor());
    $timeout = config('quality_control.timeout_seconds');
    expect($quality->queue)->toBe('quality-control')
        ->and($quality->connection)->toBe('redis-quality-control')
        ->and($quality->timeout)->toBe($timeout + 120)
        ->and(config('queue.connections.redis-quality-control.retry_after'))->toBe($timeout + 180)
        ->and($limit->get(WorkerPool::QUALITY_CONTROL))->toBe(3)
        ->and($limit->get(WorkerPool::TRANSCODES))->toBe(1)
        ->and($limit->get())->toBe(1);
});

beforeEach(function (): void {
    mock(MetricsRepository::class)->shouldReceive('acquireWaitTimeMonitorLock')->andReturn(false);
});

it('uses the configured maximum until a worker limit is saved', function (): void {
    Config::set('horizon.defaults.supervisor-1.maxProcesses', 3);
    $limit = app(WorkerLimit::class);

    expect($limit->get())->toBe(3);

    $limit->save(5);
    Config::set('horizon.defaults.supervisor-1.maxProcesses', 2);

    expect(app(WorkerLimit::class)->get())->toBe(5)
        ->and(Setting::query()->where('key', 'queue_max_workers')->firstOrFail()->value)->toBe(5);
});

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
        ->and($supervisor->options->queue)->toBe('scans,probes,default')
        ->and($supervisor->process->getCommandLine())->toContain('--max-processes=4');
});

it('does not disable prefork with a custom supervisor binding', function (): void {
    expect(Preflight::check(app(), probes: false))
        ->not->toContain('the app binds '.AddSupervisor::class.' itself');
});

it('applies saved limits when prefork creates supervisor processes', function (): void {
    mock(HorizonCommandQueue::class)->shouldReceive('flush');
    app(WorkerLimit::class)->save(4);
    app()->bind(AddSupervisor::class, PreforkAddSupervisor::class);
    $prefork = app(PreforkMaster::class);
    app()->instance(PreforkMaster::class, $prefork);
    $stream = fopen('php://memory', 'w+b');
    if ($stream === false) {
        throw new LogicException('The prefork test channel requires an open stream.');
    }

    try {
        $prefork->active = true;
        $prefork->broker = new Broker(new Channel($stream), 'test-');
        $options = ProvisioningPlan::get('test-master')->optionsFor('*', 'supervisor-1');
        $master = new MasterSupervisor;

        app(AddSupervisor::class)->process($master, $options->toArray());
        $supervisor = $master->supervisors->first();

        expect($supervisor->options->maxProcesses)->toBe(4)
            ->and($supervisor->process)->toBeInstanceOf(ForkedProcess::class)
            ->and($supervisor->process->getCommandLine())->toContain('--max-processes=4')
            ->and($supervisor->process->isStarted())->toBeFalse();
    } finally {
        fclose($stream);
    }
});

it('uses the saved limit before a replacement supervisor starts with stale options', function (): void {
    mock(HorizonCommandQueue::class)->shouldReceive('flush');
    Setting::factory()->create(['key' => 'queue_max_workers', 'value' => 1]);
    $staleOptions = new SupervisorOptions('test-master:supervisor-1', 'redis', 'scans,probes', maxProcesses: 10);
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
    $limit = mock(WorkerLimit::class);
    $limit->shouldReceive('get')->once()->ordered()->andThrow(new RuntimeException('Database unavailable'));
    $limit->shouldReceive('get')->once()->ordered()->andReturn(2);
    $staleOptions = new SupervisorOptions('test-master:supervisor-1', 'redis', 'scans,probes', maxProcesses: 10);

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
    mock(WorkerLimit::class)->shouldNotReceive('get');

    Event::dispatch(new CommandStarting($command, new ArrayInput([]), new NullOutput));
})->with(['config:cache', 'migrate', 'package:discover']);

it('refreshes running supervisor limits without resuming or restarting workers', function (bool $working): void {
    mock(HorizonCommandQueue::class)->shouldReceive('flush');
    $options = new SupervisorOptions('test-master:supervisor-1', 'redis', 'scans,probes', balance: 'off');
    $supervisor = new Supervisor($options);
    $supervisor->working = $working;
    $pool = $supervisor->processPools->first();
    $workerLimit = app(WorkerLimit::class);

    foreach ([5, 2, 2] as $limit) {
        $workerLimit->save($limit);
        Event::dispatch(new SupervisorLooped($supervisor));

        expect($supervisor->options->maxProcesses)->toBe($limit)
            ->and($supervisor->options->minProcesses)->toBe(1)
            ->and($supervisor->working)->toBe($working)
            ->and($supervisor->processPools->first())->toBe($pool);
    }

    $replacement = new Supervisor(new SupervisorOptions('new-master:supervisor-1', 'redis', 'scans,probes', balance: 'off'));
    Event::dispatch(new SupervisorLooped($replacement));

    expect($replacement->options->maxProcesses)->toBe(2);
})->with(['running' => true, 'paused' => false]);

it('leaves unrelated supervisors alone', function (): void {
    mock(HorizonCommandQueue::class)->shouldReceive('flush');
    mock(WorkerLimit::class)->shouldNotReceive('get');
    $supervisor = new Supervisor(new SupervisorOptions('test-master:other', 'redis', maxProcesses: 3));

    Event::dispatch(new SupervisorLooped($supervisor));

    expect($supervisor->options->maxProcesses)->toBe(3);
});

it('keeps the running limit when settings cannot be read', function (): void {
    mock(HorizonCommandQueue::class)->shouldReceive('flush');
    mock(WorkerLimit::class)->shouldReceive('get')->andThrow(new RuntimeException('Database unavailable'));
    $supervisor = new Supervisor(new SupervisorOptions('test-master:supervisor-1', 'redis', maxProcesses: 3));

    expect(fn () => Event::dispatch(new SupervisorLooped($supervisor)))->toThrow(RuntimeException::class, 'Database unavailable');
    expect($supervisor->options->maxProcesses)->toBe(3);
});

it('lets the native scaler grow under load and gracefully retire excess workers', function (): void {
    mock(HorizonCommandQueue::class)->shouldReceive('flush');
    $supervisor = new Supervisor(new SupervisorOptions('test-master:supervisor-1', 'redis', 'scans,probes', balance: 'off'));
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
    $workerLimit = app(WorkerLimit::class);

    $workerLimit->save(3);
    Event::dispatch(new SupervisorLooped($supervisor));
    for ($step = 0; $step < 4; $step++) {
        $scaler->scale($supervisor);
    }

    expect($supervisor->processes())->toHaveCount(3);

    $workerLimit->save(2);
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

it('saves independent pool limits and preserves the standard setting key', function (): void {
    $limit = app(WorkerLimit::class);
    expect($limit->get(WorkerPool::TRANSCODES))->toBe(1);
    $limit->save(5);
    $limit->save(2, WorkerPool::TRANSCODES);

    expect($limit->get())->toBe(5)
        ->and($limit->get(WorkerPool::TRANSCODES))->toBe(2)
        ->and(Setting::valueOf('queue_max_workers'))->toBe(5)
        ->and(Setting::valueOf('transcode_max_workers'))->toBe(2);
});

it('provisions and refreshes each supervisor with its own limit', function (): void {
    mock(HorizonCommandQueue::class)->shouldReceive('flush');
    $limit = app(WorkerLimit::class);
    $limit->save(4);
    $limit->save(2, WorkerPool::TRANSCODES);
    $limit->save(3, WorkerPool::WRITEBACK);
    $master = new MasterSupervisor;
    $plan = ProvisioningPlan::get('test-master');

    foreach (WorkerPool::cases() as $pool) {
        app(AddSupervisor::class)->process($master, $plan->optionsFor('*', $pool->supervisor())->toArray());
    }

    expect($master->supervisors->get(0)->options->maxProcesses)->toBe(4)
        ->and($master->supervisors->get(0)->options->queue)->toBe('scans,probes,default')
        ->and($master->supervisors->get(1)->options->maxProcesses)->toBe(2)
        ->and($master->supervisors->get(1)->options->queue)->toBe('transcodes')
        ->and($master->supervisors->get(1)->options->connection)->toBe('redis-transcodes')
        ->and($master->supervisors->get(2)->options->maxProcesses)->toBe(3)
        ->and($master->supervisors->get(2)->options->queue)->toBe('writeback')
        ->and($master->supervisors->get(2)->options->connection)->toBe('redis-writeback');

    $standard = new Supervisor($master->supervisors->get(0)->options);
    $transcodes = new Supervisor($master->supervisors->get(1)->options);
    $writeback = new Supervisor($master->supervisors->get(2)->options);
    $transcodes->working = false;
    $limit->save(1, WorkerPool::TRANSCODES);
    $limit->save(5, WorkerPool::WRITEBACK);
    Event::dispatch(new SupervisorLooped($standard));
    Event::dispatch(new SupervisorLooped($transcodes));
    Event::dispatch(new SupervisorLooped($writeback));

    expect($standard->options->maxProcesses)->toBe(4)
        ->and($transcodes->options->maxProcesses)->toBe(1)
        ->and($transcodes->working)->toBeFalse()
        ->and($writeback->options->maxProcesses)->toBe(5);
});

it('reports the requested pool status without using another pool', function (WorkerPool $pool, WorkerState $state, int $limit, int $workers): void {
    mock(SupervisorRepository::class)->shouldReceive('all')->andReturn([
        (object) ['name' => 'local:supervisor-1', 'status' => 'running', 'processes' => ['redis:scans,probes,default' => 4], 'options' => ['maxProcesses' => 5]],
        (object) ['name' => 'local:supervisor-transcodes', 'status' => 'paused', 'processes' => ['redis-transcodes:transcodes' => 1], 'options' => ['maxProcesses' => 2]],
        (object) ['name' => 'local:supervisor-writeback', 'status' => 'running', 'processes' => ['redis-writeback:writeback' => 3], 'options' => ['maxProcesses' => 4]],
    ]);
    $status = app(HorizonWorkers::class)->status($pool);
    expect($status->state)->toBe($state)->and($status->limit)->toBe($limit)->and($status->workers)->toBe($workers);
})->with([
    'transcodes' => [WorkerPool::TRANSCODES, WorkerState::PAUSED, 2, 1],
    'writeback' => [WorkerPool::WRITEBACK, WorkerState::RUNNING, 4, 3],
]);

it('keeps transcode reservations beyond supervisor timeout and standard queues isolated', function (): void {
    $plan = ProvisioningPlan::get('test-master');
    $standard = $plan->optionsFor('*', WorkerPool::STANDARD->supervisor());
    $transcodes = $plan->optionsFor('*', WorkerPool::TRANSCODES->supervisor());
    $timeout = max(1, (int) config('transcoding.timeout_seconds', 604800));

    expect($standard->queue)->toBe('scans,probes,default')
        ->and($standard->timeout)->toBe(1830)
        ->and($transcodes->queue)->toBe('transcodes')
        ->and($transcodes->timeout)->toBe($timeout + 120)
        ->and(config('queue.connections.redis-transcodes.retry_after'))->toBe($timeout + 180)
        ->and(config('queue.connections.redis-transcodes.connection'))->toBe(config('queue.connections.redis.connection'));
});

it('isolates writebacks from transcodes with their own reservation timeout', function (): void {
    $writeback = ProvisioningPlan::get('test-master')->optionsFor('*', 'supervisor-writeback');
    $timeout = config('writeback.timeout_seconds');

    expect($writeback)->not->toBeNull();
    expect($writeback->queue)->toBe('writeback')
        ->and($writeback->connection)->toBe('redis-writeback')
        ->and($writeback->maxProcesses)->toBe(1)
        ->and($writeback->timeout)->toBe($timeout + 120)
        ->and(config('queue.connections.redis-writeback.retry_after'))->toBe($timeout + 180)
        ->and(config('queue.connections.redis-writeback.connection'))->toBe(config('queue.connections.redis.connection'));
});
