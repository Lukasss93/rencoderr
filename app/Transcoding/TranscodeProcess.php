<?php

namespace App\Transcoding;

use Closure;
use FFMpeg\Driver\FFMpegDriver;
use FFMpeg\Driver\FFProbeDriver;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

class TranscodeProcess
{
    private ?Process $process = null;

    private ?int $group = null;

    private bool $stopped = false;

    public function __construct(private readonly FFMpegDriver $driver) {}

    /**
     * @param  list<string>  $arguments
     * @param  Closure(): bool  $interrupted
     * @param  Closure(?float, float, string): void  $progress
     */
    public function run(array $arguments, string $outputPath, int $remainingSeconds, ?float $duration, Closure $interrupted, Closure $progress): TranscodeProcessResult
    {
        $parser = new ProgressParser($duration);
        $binary = $this->driver->getProcessBuilderFactory()->getBinary();

        return $this->execute([$binary, '-nostdin', '-nostats', '-progress', 'pipe:1', '-stats_period', '1', ...$arguments, $outputPath],
            $remainingSeconds, $interrupted, $parser->push(...),
            fn (string $tail) => $progress($parser->percent(), $parser->seconds(), $tail));
    }

    /**
     * @param  Closure(): bool  $interrupted
     * @param  Closure(?float, float, string): void  $progress
     */
    public function inspectHdr(string $source, int $streamIndex, int $remainingSeconds, Closure $interrupted, Closure $progress): HdrInspection
    {
        $binary = app(FFProbeDriver::class)->getProcessBuilderFactory()->getBinary();
        $inspection = new HdrInspection;
        try {
            $result = $this->execute([$binary, '-v', 'error', '-select_streams', (string) $streamIndex, '-show_frames',
                '-show_entries', 'frame=best_effort_timestamp:frame_side_data', '-of', 'flat', $source],
                $remainingSeconds, fn (): bool => $interrupted() || $inspection->unsupported(), $inspection->push(...),
                fn (string $tail) => $progress(null, 0.0, $tail));
        } catch (TranscodeInterrupted $exception) {
            if (! $inspection->unsupported()) {
                throw $exception;
            }

            return $inspection;
        }
        $inspection->finish();
        if ($result->exitCode !== 0 || ! $inspection->hasFrames()) {
            throw new RuntimeException('HDR frame metadata could not be inspected. '.$result->diagnosticTail);
        }

        return $inspection;
    }

    /**
     * @param  list<string>  $command
     * @param  Closure(): bool  $interrupted
     * @param  Closure(string): void  $consume
     * @param  Closure(string): mixed  $progress
     */
    private function execute(array $command, int $remainingSeconds, Closure $interrupted, Closure $consume, Closure $progress): TranscodeProcessResult
    {
        if ($remainingSeconds <= 0) {
            throw new RuntimeException('The transcode deadline has expired.');
        }
        if (! function_exists('posix_kill')) {
            throw new RuntimeException('Transcoding requires POSIX process-group support.');
        }
        $timeout = (new ExecutableFinder)->find('timeout');
        if ($timeout === null || ! is_executable($command[0])) {
            throw new RuntimeException('A required media executable or GNU timeout was not found.');
        }
        $tail = '';
        $outputFailure = null;
        $this->stopped = false;
        $this->process = $process = new Process([$timeout, '--kill-after=10s', $remainingSeconds.'s', ...$command], timeout: null);
        try {
            $this->checkInterrupted($interrupted);
            $process->start(function (string $type, string $data) use ($consume, &$tail, &$outputFailure, $process): void {
                if ($type === Process::OUT) {
                    if (! $this->stopped && $outputFailure === null) {
                        try {
                            $consume($data);
                        } catch (Throwable $exception) {
                            $outputFailure = $exception;
                        }
                    }
                    $process->clearOutput();
                } else {
                    $tail = substr($tail.$data, -65536);
                    $process->clearErrorOutput();
                }
            });
            $this->group = $process->getPid();
            $lastProgress = 0.0;
            while ($process->isRunning()) {
                if ($outputFailure !== null) {
                    throw $outputFailure;
                }
                $this->checkInterrupted($interrupted);
                if (microtime(true) - $lastProgress >= 5) {
                    $progress($tail);
                    $lastProgress = microtime(true);
                }
                usleep(100000);
            }
            $process->wait();
            if ($outputFailure !== null) {
                throw $outputFailure;
            }
            $this->checkInterrupted($interrupted);
            $progress($tail);

            return new TranscodeProcessResult($process->getExitCode() ?? 1, $tail);
        } finally {
            $this->stop();
            $this->process = null;
            $this->group = null;
        }
    }

    public function stop(): void
    {
        $this->stopped = true;
        if ($this->group !== null) {
            // GNU timeout creates a process group; signalling only its PID leaves FFmpeg alive.
            @posix_kill(-$this->group, SIGTERM);
        }

        if ($this->process !== null) {
            $until = microtime(true) + 1;
            while ($this->process->isRunning() && microtime(true) < $until) {
                usleep(10000);
            }
        }

        if ($this->group !== null) {
            @posix_kill(-$this->group, SIGKILL);
        }

        $this->process?->stop(0, SIGKILL);
    }

    /** @param Closure(): bool $interrupted */
    private function checkInterrupted(Closure $interrupted): void
    {
        if ($this->stopped || $interrupted()) {
            throw new TranscodeInterrupted('The transcode was interrupted.');
        }
    }
}
