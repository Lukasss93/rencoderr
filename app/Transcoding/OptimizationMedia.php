<?php

namespace App\Transcoding;

use App\Ffmpeg\MediaProcess;
use App\Ffmpeg\MediaProcessInterrupted;
use App\QualityControl\PacketSpan;
use Closure;
use FFMpeg\Driver\FFMpegDriver;
use FFMpeg\Driver\FFProbeDriver;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

class OptimizationMedia
{
    private string $directory;

    private float $deadline;

    private int $maximumBytes;

    private Closure $stop;

    private Closure $progress;

    public function __construct(private MediaProcess $process, private FFMpegDriver $ffmpeg, private FFProbeDriver $ffprobe) {}

    /** @param Closure(): bool $stop
     * @param  Closure(array<string, mixed>): void  $progress
     */
    public function configure(string $directory, float $deadline, int $maximumBytes, Closure $stop, Closure $progress): void
    {
        $this->directory = $directory;
        $this->deadline = $deadline;
        $this->maximumBytes = $maximumBytes;
        $this->stop = $stop;
        $this->progress = $progress;
    }

    public function deadline(float $deadline): void
    {
        $this->deadline = $deadline;
    }

    public function guard(): bool
    {
        if (($this->stop)()) {
            throw new MediaProcessInterrupted('Optimization was cancelled.');
        }
        if (microtime(true) >= $this->deadline) {
            throw new OptimizationLimit('The preparation time budget was exhausted.');
        }
        clearstatcache();
        $bytes = 0;
        if (is_dir($this->directory)) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->directory, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
                if ($file->isLink()) {
                    throw new RuntimeException('An optimization workspace entry became a symbolic link.');
                }
                $bytes += $file->getSize();
                if ($bytes > $this->maximumBytes) {
                    throw new OptimizationLimit('The preparation temporary storage budget was exhausted.');
                }
            }
        }

        return false;
    }

    public function binary(bool $probe = false): string
    {
        return ($probe ? $this->ffprobe : $this->ffmpeg)->getProcessBuilderFactory()->getBinary();
    }

    /** @param list<string> $command
     * @param  (Closure(string): void)|null  $consume
     */
    public function execute(array $command, ?Closure $consume = null, bool $strict = true): void
    {
        $this->guard();
        $result = $this->process->execute($command, max(1, (int) ceil($this->deadline - microtime(true))),
            $this->guard(...), $consume ?? function (): void {},
            fn (string $tail) => ($this->progress)(['diagnostic_tail' => $tail]), $this->directory);
        $this->guard();
        if ($result->exitCode !== 0 || ($strict && trim($result->errorDiagnostics()) !== '')) {
            throw new RuntimeException('Optimization media command failed: '.$result->exitCode.'. '.$result->diagnosticTail);
        }
    }

    /** @param list<string> $command */
    public function read(array $command): string
    {
        $text = '';
        $this->execute($command, function (string $chunk) use (&$text): void {
            if (strlen($text) + strlen($chunk) > 8 * 1024 * 1024) {
                throw new RuntimeException('Optimization metadata exceeds its size limit.');
            }
            $text .= $chunk;
        });

        return $text;
    }

    /** @return array<string, mixed> */
    public function probe(string $path): array
    {
        $data = json_decode($this->read([$this->binary(true), '-v', 'error', '-show_streams', '-show_format', '-of', 'json', $path]), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($data)) {
            throw new RuntimeException('Invalid optimization probe document.');
        }

        return $data;
    }

    /** @return array<int, int> */
    public function inventory(string $path): array
    {
        $inventory = new SourceByteInventory;
        $this->execute([$this->binary(true), '-v', 'error', '-show_packets', '-show_entries', 'packet=stream_index,size', '-of', 'compact=p=0', $path], $inventory->push(...));

        return $inventory->finish();
    }

    public function audioDuration(string $path, int $index): float
    {
        $span = new PacketSpan;
        $this->execute([$this->binary(true), '-v', 'error', '-select_streams', (string) $index, '-show_packets',
            '-show_entries', 'packet=pts_time,duration_time', '-of', 'flat', $path], $span->push(...));
        $bounds = $span->finish();

        return $bounds['end'] - $bounds['start'];
    }
}
