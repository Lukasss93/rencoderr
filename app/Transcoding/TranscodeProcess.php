<?php

namespace App\Transcoding;

use App\Ffmpeg\MediaProcess;
use App\Ffmpeg\MediaProcessInterrupted;
use App\Ffmpeg\MediaProcessResult;
use Closure;
use FFMpeg\Driver\FFMpegDriver;
use FFMpeg\Driver\FFProbeDriver;
use RuntimeException;

class TranscodeProcess
{
    public function __construct(private readonly FFMpegDriver $driver, private MediaProcess $runner = new MediaProcess) {}

    /**
     * @param  list<string>  $arguments
     * @param  Closure(): bool  $interrupted
     * @param  Closure(?float, float, string): void  $progress
     */
    public function run(array $arguments, string $outputPath, int $remainingSeconds, ?float $duration, Closure $interrupted, Closure $progress): MediaProcessResult
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
    private function execute(array $command, int $remainingSeconds, Closure $interrupted, Closure $consume, Closure $progress): MediaProcessResult
    {
        try {
            return $this->runner->execute($command, $remainingSeconds, $interrupted, $consume, $progress);
        } catch (MediaProcessInterrupted $exception) {
            throw new TranscodeInterrupted($exception->getMessage(), previous: $exception);
        }
    }
}
