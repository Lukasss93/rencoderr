<?php

namespace App\Ffmpeg;

use Alchemy\BinaryDriver\Exception\ExecutionFailureException;
use Alchemy\BinaryDriver\Listeners\DebugListener;
use FFMpeg\Driver\FFMpegDriver;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

class Ffmpeg
{
    /** @param list<string> $arguments */
    public function run(array $arguments, int $timeout): CommandResult
    {
        $driver = app(FFMpegDriver::class);
        $driver->getProcessBuilderFactory()->setTimeout($timeout);
        $error = '';
        $listener = new DebugListener('', '', 'output', 'error');
        $listener->on('error', function (string $line) use (&$error): void {
            $error .= $line."\n";
        });

        try {
            return new CommandResult(true, $driver->command($arguments, false, $listener), $error);
        } catch (ExecutionFailureException $exception) {
            if ($exception->getPrevious() instanceof ProcessTimedOutException) {
                throw $exception->getPrevious();
            }

            return new CommandResult(false, '', $exception->getErrorOutput() ?? '');
        }
    }
}
