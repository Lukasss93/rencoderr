<?php

use Alchemy\BinaryDriver\Configuration;
use Alchemy\BinaryDriver\Exception\ExecutionFailureException;
use Alchemy\BinaryDriver\Listeners\ListenerInterface;
use Alchemy\BinaryDriver\ProcessBuilderFactoryInterface;
use Alchemy\BinaryDriver\ProcessRunnerInterface;
use App\Jobs\ScanLibraryPath;
use App\Models\LibraryPath;
use App\Scan\Enums\ScanStatus;
use FFMpeg\Driver\FFMpegDriver;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use Illuminate\Testing\TestResponse;
use Pest\TestSuite;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Process\Process;
use Tests\TestCase;

use function Pest\Laravel\artisan;

pest()->extend(TestCase::class)->use(LazilyRefreshDatabase::class)->in('Feature');

/**
 * Exercise PHP-FFMpeg's command pipeline using Laravel's process fake without launching a binary.
 */
function fakeFfmpegDriver(): void
{
    $commands = new WeakMap;
    $timeout = 5;
    $factory = Mockery::mock(ProcessBuilderFactoryInterface::class);
    $factory->shouldReceive('setTimeout')->andReturnUsing(function (int $seconds) use (&$timeout, $factory) {
        $timeout = $seconds;

        return $factory;
    });
    $factory->shouldReceive('create')->andReturnUsing(function (array $arguments) use ($commands, &$timeout): Process {
        $process = new Process(['ffmpeg', ...$arguments], timeout: $timeout);
        $commands[$process] = ['ffmpeg', ...$arguments];

        return $process;
    });
    $runner = Mockery::mock(ProcessRunnerInterface::class);
    $runner->shouldReceive('run')->andReturnUsing(function (Process $process, SplObjectStorage $listeners) use ($commands, &$timeout): string {
        try {
            $result = Illuminate\Support\Facades\Process::timeout($timeout)->run($commands[$process]);
        } catch (ProcessTimedOutException $exception) {
            throw new ExecutionFailureException('ffmpeg', $process->getCommandLine(), previous: $exception->getPrevious());
        }

        foreach ($listeners as $listener) {
            if (! $listener instanceof ListenerInterface) {
                throw new LogicException('FFmpeg output listeners must implement ListenerInterface.');
            }

            $listener->handle(Process::ERR, $result->errorOutput());
        }

        if (! $result->successful()) {
            throw new ExecutionFailureException('ffmpeg', $process->getCommandLine(), $result->errorOutput());
        }

        return $result->output();
    });
    if (! $factory instanceof ProcessBuilderFactoryInterface || ! $runner instanceof ProcessRunnerInterface) {
        throw new LogicException('FFmpeg test doubles must implement the driver interfaces.');
    }

    $driver = new FFMpegDriver($factory, new NullLogger, new Configuration(['timeout' => 5]));
    $driver->setProcessRunner($runner);
    app()->instance(FFMpegDriver::class, $driver);
}

/**
 * @param  array<string, mixed>  $parameters
 */
function artisanCommand(string $command, array $parameters = []): PendingCommand
{
    $pendingCommand = artisan($command, $parameters);

    if (! $pendingCommand instanceof PendingCommand) {
        throw new LogicException('Console output must be mocked to assert command prompts and output.');
    }

    return $pendingCommand;
}

/**
 * @param  TestResponse<Response>  $response
 * @param  array<string, mixed>  $updates
 * @param  list<array{method: string, params: list<mixed>}>  $calls
 * @return array{components: list<array{snapshot: string, updates: array<string, mixed>, calls: list<array{method: string, params: list<mixed>}>}>}
 */
function livewireUpdatePayload(TestResponse $response, array $updates = [], array $calls = []): array
{
    $snapshot = str((string) $response->getContent())->betweenFirst('wire:snapshot="', '"');

    return [
        'components' => [[
            'snapshot' => htmlspecialchars_decode((string) $snapshot, ENT_QUOTES),
            'updates' => $updates,
            'calls' => $calls,
        ]],
    ];
}

/**
 * @param  array<string, string>  $files
 */
function mediaDirectory(array $files = []): string
{
    $testCase = TestSuite::getInstance()->test;

    if (! $testCase instanceof TestCase) {
        throw new LogicException('Media fixtures require a Laravel test case.');
    }

    return $testCase->mediaDirectory($files);
}

/**
 * Run a scan of the folder through the synchronous queue, so failures are recorded as a worker would record them.
 *
 * @param  list<string>  $patterns
 */
function scanMediaPath(LibraryPath $path, array $patterns = []): void
{
    $token = (string) Str::uuid();
    $path->refresh()->update(['scan_status' => ScanStatus::Queued, 'scan_token' => $token, 'scan_requested_at' => now()]);
    $path->library()->firstOrFail()->update(['exclusion_patterns' => $patterns]);
    ScanLibraryPath::dispatchSync($path->id, $token);
}
