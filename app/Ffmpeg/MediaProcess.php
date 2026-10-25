<?php

namespace App\Ffmpeg;

use Closure;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

class MediaProcess
{
    private ?Process $process = null;

    private ?int $group = null;

    private bool $stopped = false;

    /**
     * @param  list<string>  $command
     * @param  Closure(): bool  $interrupted
     * @param  Closure(string): void  $consume
     * @param  Closure(string): mixed  $progress
     */
    public function execute(array $command, int $remainingSeconds, Closure $interrupted, Closure $consume, Closure $progress, ?string $directory = null): MediaProcessResult
    {
        if ($remainingSeconds <= 0) {
            throw new RuntimeException('The media execution deadline has expired.');
        }
        if (! function_exists('posix_kill')) {
            throw new RuntimeException('Media execution requires POSIX process-group support.');
        }
        $timeout = (new ExecutableFinder)->find('timeout');
        if ($timeout === null || ! is_executable($command[0])) {
            throw new RuntimeException('A required media executable or GNU timeout was not found.');
        }
        $tail = '';
        $outputFailure = null;
        $this->stopped = false;
        $this->process = $process = new Process([$timeout, '--kill-after=10s', $remainingSeconds.'s', ...$command], cwd: $directory, timeout: null);
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

            return new MediaProcessResult($process->getExitCode() ?? 1, $tail);
        } finally {
            $this->stop();
            $this->process = null;
            $this->group = null;
        }
    }

    private function stop(): void
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
            throw new MediaProcessInterrupted('The media process was interrupted.');
        }
    }
}
