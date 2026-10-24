<?php

use App\Transcoding\TranscodeInterrupted;
use App\Transcoding\TranscodeProcess;
use FFMpeg\Driver\FFMpegDriver;
use FFMpeg\Driver\FFProbeDriver;
use Symfony\Component\Process\Process;

function transcodeFixture(string $code): TranscodeProcess
{
    $directory = mediaDirectory(['ffmpeg' => '#!'.PHP_BINARY."\n<?php\n".$code]);
    chmod($directory.'/ffmpeg', 0755);

    return new TranscodeProcess(FFMpegDriver::create(configuration: ['ffmpeg.binaries' => $directory.'/ffmpeg']));
}

it('streams progress and returns bounded diagnostics and a nonzero exit code', function (): void {
    $runner = transcodeFixture('fwrite(STDOUT, "out_time_us=2500000\\nprogress=end\\n"); fwrite(STDERR, str_repeat("x", 100000)."failure"); exit(3);');
    $updates = [];

    $result = $runner->run([], '/unused.partial.mkv', 5, 10, fn (): bool => false, function (?float $percent, float $seconds, string $tail) use (&$updates): void {
        $updates[] = [$percent, $seconds];
    });

    expect($result->exitCode)->toBe(3);
    expect($result->diagnosticTail)->toHaveLength(65536)->toEndWith('failure');
    expect(array_pop($updates))->toBe([25.0, 2.5]);
});

it('passes arguments literally and adds machine progress options and the output path', function (): void {
    $runner = transcodeFixture('fwrite(STDERR, json_encode(array_slice($argv, 1)));');

    $result = $runner->run(['-i', 'a;$(touch nope).mkv'], '/output.partial.mkv', 5, null, fn (): bool => false, function (): void {});

    expect(json_decode($result->diagnosticTail, true))->toBe(['-nostdin', '-nostats', '-progress', 'pipe:1', '-stats_period', '1', '-i', 'a;$(touch nope).mkv', '/output.partial.mkv']);
});

it('does not launch a process after the deadline', function (): void {
    $runner = transcodeFixture('exit(0);');

    expect(fn () => $runner->run([], '/unused.partial.mkv', 0, null, fn (): bool => false, function (): void {}))->toThrow(RuntimeException::class, 'deadline');
});

it('interrupts and waits for the child before returning control', function (): void {
    $directory = mediaDirectory();
    $pidPath = $directory.'/pid';
    $runner = transcodeFixture('file_put_contents('.var_export($pidPath, true).', (string) getmypid()); while (true) { usleep(10000); }');

    expect(fn () => $runner->run([], '/unused.partial.mkv', 5, null, fn (): bool => file_exists($pidPath), function (): void {}))->toThrow(TranscodeInterrupted::class);

    $pid = (int) file_get_contents($pidPath);
    expect(posix_kill($pid, 0))->toBeFalse();
});

it('enforces the watchdog timeout independently of progress', function (): void {
    $runner = transcodeFixture('while (true) { usleep(10000); }');

    $result = $runner->run([], '/unused.partial.mkv', 1, null, fn (): bool => false, function (): void {});

    expect($result->exitCode)->toBe(124);
});

it('stops descendants when a progress callback fails', function (): void {
    $directory = mediaDirectory();
    $pidPath = $directory.'/pid';
    $runner = transcodeFixture('$child = pcntl_fork(); if ($child === 0) { file_put_contents('.var_export($pidPath, true).', (string) getmypid()); } while (true) { usleep(10000); }');

    expect(fn () => $runner->run([], '/unused.partial.mkv', 5, null, fn (): bool => false, function () use ($pidPath): void {
        $until = microtime(true) + 1;
        while (! file_exists($pidPath) && microtime(true) < $until) {
            usleep(10000);
        }

        throw new RuntimeException('Progress persistence failed.');
    }))->toThrow(RuntimeException::class, 'Progress persistence failed.');

    $pid = (int) file_get_contents($pidPath);
    $status = @file_get_contents('/proc/'.$pid.'/stat');
    expect($status === false || preg_match('/^\d+ \(.*\) Z /', $status) === 1)->toBeTrue();
});

it('keeps the watchdog active after the PHP worker is killed', function (): void {
    $directory = mediaDirectory();
    $pidPath = $directory.'/pid';
    $executable = $directory.'/ffmpeg';
    file_put_contents($executable, '#!'.PHP_BINARY."\n<?php\n".'file_put_contents('.var_export($pidPath, true).', (string) getmypid()); while (true) { usleep(10000); }');
    chmod($executable, 0755);
    $worker = $directory.'/worker.php';
    file_put_contents($worker, '<?php require '.var_export(base_path('vendor/autoload.php'), true).'; $runner = new App\\Transcoding\\TranscodeProcess(FFMpeg\\Driver\\FFMpegDriver::create(configuration: ["ffmpeg.binaries" => '.var_export($executable, true).'])); $runner->run([], "/unused.partial.mkv", 1, null, fn (): bool => false, function (): void {});');
    $process = new Process([PHP_BINARY, $worker]);

    try {
        $process->start();
        $until = microtime(true) + 2;
        while (! file_exists($pidPath) && $process->isRunning() && microtime(true) < $until) {
            usleep(10000);
        }
        expect(file_exists($pidPath))->toBeTrue();
        $process->signal(SIGKILL);
        $process->wait();

        $pid = (int) file_get_contents($pidPath);
        $until = microtime(true) + 2;
        do {
            $status = @file_get_contents('/proc/'.$pid.'/stat');
            $stopped = $status === false || preg_match('/^\d+ \(.*\) Z /', $status) === 1;
            if (! $stopped) {
                usleep(10000);
            }
        } while (! $stopped && microtime(true) < $until);

        expect($stopped)->toBeTrue();
    } finally {
        $process->stop(0, SIGKILL);
    }
});

it('inspects decoded frame metadata before HDR preservation without retaining probe output', function (): void {
    $frames = "frames.frame.0.best_effort_timestamp=0\nframes.frame.0.side_data_list.side_data.0.side_data_type=\"HDR Dynamic Metadata SMPTE2094-40 (HDR10+)\"\n";
    $directory = mediaDirectory(['ffprobe' => '#!'.PHP_BINARY."\n<?php\n".'fwrite(STDOUT, '.var_export($frames, true).');']);
    chmod($directory.'/ffprobe', 0755);
    app()->instance(FFProbeDriver::class, FFProbeDriver::create(configuration: ['ffprobe.binaries' => $directory.'/ffprobe']));
    $runner = transcodeFixture('exit(0);');
    $inspection = $runner->inspectHdr('/source.mkv', 0, 5, fn (): bool => false, function (): void {});
    expect($inspection->unsupported())->toBeTrue();
});

it('kills an uncooperative probe even when decoding its streamed metadata throws', function (): void {
    $directory = mediaDirectory();
    $pidPath = $directory.'/pid';
    $code = 'pcntl_async_signals(true); pcntl_signal(SIGTERM, SIG_IGN); file_put_contents('.var_export($pidPath, true).', getmypid()); while (true) { fwrite(STDOUT, "frames.frame.0.side_data_list.side_data.0.side_data_type=invalid-json\\n"); usleep(10000); }';
    file_put_contents($directory.'/ffprobe', '#!'.PHP_BINARY."\n<?php\n".$code);
    chmod($directory.'/ffprobe', 0755);
    app()->instance(FFProbeDriver::class, FFProbeDriver::create(configuration: ['ffprobe.binaries' => $directory.'/ffprobe']));
    $runner = transcodeFixture('exit(0);');
    try {
        expect(fn () => $runner->inspectHdr('/source.mkv', 0, 2, fn (): bool => false, function (): void {}))->toThrow(JsonException::class);
        $pid = (int) file_get_contents($pidPath);
        $until = microtime(true) + 2;
        do {
            $status = @file_get_contents('/proc/'.$pid.'/stat');
            $stopped = $status === false || preg_match('/^\d+ \(.*\) Z /', $status) === 1;
            if (! $stopped) {
                usleep(10000);
            }
        } while (! $stopped && microtime(true) < $until);

        expect($stopped)->toBeTrue();
    } finally {
        if (is_file($pidPath)) {
            @posix_kill((int) file_get_contents($pidPath), SIGKILL);
        }
    }
});
