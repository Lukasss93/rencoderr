<?php

namespace App;

use Alchemy\BinaryDriver\Exception\ExecutionFailureException;
use Alchemy\BinaryDriver\Listeners\DebugListener;
use FFMpeg\Driver\FFMpegDriver;
use FFMpeg\Exception\ExecutableNotFoundException;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

/**
 * @phpstan-type Encoder array{name: string, codec: string, type: string, description: string, hardware: bool, backend: ?string, testable: bool}
 * @phpstan-type Backend array{label: string, available: bool, encoders: int, message: string}
 * @phpstan-type Acceleration array{backends: array<string, Backend>, error: ?string}
 * @phpstan-type Detection array{version: ?string, error: ?string, encoders: list<Encoder>, acceleration: Acceleration}
 * @phpstan-type EncoderCheck array{successful: bool, message: string}
 */
class FfmpegHealth
{
    /** @var array<string, string> */
    private const array BACKEND_LABELS = [
        'nvenc' => 'NVIDIA NVENC',
        'cuda' => 'NVIDIA CUDA',
        'qsv' => 'Intel Quick Sync (QSV)',
        'vaapi' => 'VAAPI',
        'amf' => 'AMD AMF',
        'videotoolbox' => 'Apple VideoToolbox',
        'vdpau' => 'VDPAU',
        'vulkan' => 'Vulkan',
        'opencl' => 'OpenCL',
        'drm' => 'DRM',
        'dxva2' => 'DirectX DXVA2',
        'd3d11va' => 'Direct3D 11',
        'd3d12va' => 'Direct3D 12',
        'v4l2m2m' => 'V4L2 M2M',
        'omx' => 'OpenMAX',
        'mediacodec' => 'Android MediaCodec',
        'mf' => 'Media Foundation',
    ];

    /** @return Detection */
    public function detect(): array
    {
        $report = ['version' => null, 'error' => null, 'encoders' => [], 'acceleration' => ['backends' => [], 'error' => null]];

        try {
            $version = $this->run(5, ['-hide_banner', '-version']);

            if (! $version['successful'] || ! preg_match('/^ffmpeg version (\S+)/m', $version['output'], $match)) {
                $report['error'] = 'Install FFmpeg and make sure the application can run it from PATH.';

                return $report;
            }

            $report['version'] = $match[1];
            $encoders = $this->run(5, ['-hide_banner', '-encoders']);

            if (! $encoders['successful']) {
                $report['error'] = 'FFmpeg could not list its encoders. Check the installation and try refreshing detection.';

                return $report;
            }

            $report['encoders'] = $this->parseEncoders($encoders['output']);

            if ($report['encoders'] === []) {
                $report['error'] = 'No video or audio encoders were detected. Check the FFmpeg installation and try refreshing detection.';
            } else {
                $report['acceleration'] = $this->detectAcceleration($report['encoders']);
            }
        } catch (ProcessTimedOutException) {
            $report['error'] = 'FFmpeg detection timed out. Try refreshing detection.';
        } catch (ExecutableNotFoundException) {
            $report['error'] = 'Install FFmpeg and make sure the application can run it from PATH.';
        } catch (RuntimeException) {
            $report['error'] = 'FFmpeg could not be started. Check the installation and application permissions.';
        }

        return $report;
    }

    /**
     * @param  list<Encoder>  $encoders
     * @return Acceleration
     */
    private function detectAcceleration(array $encoders): array
    {
        $methods = [];
        $error = null;

        try {
            $result = $this->run(5, ['-hide_banner', '-hwaccels']);

            if (! $result['successful'] || ! str_contains($result['output'], 'Hardware acceleration methods:')) {
                $error = 'FFmpeg could not list hardware acceleration methods. Encoder detection is still available.';
            } else {
                foreach (explode("\n", $result['output']) as $line) {
                    $name = trim($line);

                    if (preg_match('/^[a-z][a-z0-9_]*$/D', $name)) {
                        $methods[$name] = 0;
                    }
                }
            }
        } catch (ProcessTimedOutException) {
            $error = 'Hardware acceleration detection timed out. Try refreshing detection.';
        } catch (RuntimeException) {
            $error = 'Hardware acceleration detection could not start. Check application permissions.';
        }

        foreach ($encoders as $encoder) {
            if ($encoder['backend'] !== null) {
                $methods[$encoder['backend']] = ($methods[$encoder['backend']] ?? 0) + 1;
            }
        }

        ksort($methods);
        $backends = [];

        foreach ($methods as $name => $count) {
            $backends[$name] = [
                'label' => self::BACKEND_LABELS[$name] ?? strtoupper($name),
                'encoders' => $count,
                ...$this->probeBackend($name, $encoders),
            ];
        }

        return ['backends' => $backends, 'error' => $error];
    }

    /**
     * @param  list<Encoder>  $encoders
     * @return array{available: bool, message: string}
     */
    private function probeBackend(string $backend, array $encoders): array
    {
        $encoderBackends = ['nvenc', 'amf', 'videotoolbox', 'v4l2m2m', 'omx', 'mediacodec', 'mf'];
        $testedEncoder = null;

        if (in_array($backend, $encoderBackends, true)) {
            $codecPriority = ['h264' => 0, 'hevc' => 1];
            usort($encoders, fn (array $left, array $right): int => ($codecPriority[$left['codec']] ?? 2) <=> ($codecPriority[$right['codec']] ?? 2));

            foreach ($encoders as $encoder) {
                if ($encoder['backend'] === $backend && $encoder['type'] === 'Video') {
                    $testedEncoder = $encoder['name'];
                    break;
                }
            }

            if ($testedEncoder === null) {
                return ['available' => false, 'message' => 'No supported video encoder is installed for this backend.'];
            }
        }

        $command = ['-hide_banner', '-nostdin', '-loglevel', $backend === 'vulkan' ? 'verbose' : 'error'];

        if ($testedEncoder === null || $backend === 'nvenc') {
            $device = match ($backend) {
                'nvenc' => 'cuda=status',
                'qsv' => 'qsv=status:hw',
                'opencl' => 'opencl=status:,device_type=gpu',
                default => $backend.'=status',
            };
            $command = [...$command, '-init_hw_device', $device];
        }

        $command = [...$command, '-f', 'lavfi', '-i', 'color=c=black:s=1280x720:r=1', '-vf', 'format=nv12'];

        if ($testedEncoder !== null) {
            $command = [...$command, '-c:v', $testedEncoder];

            if ($backend === 'mf') {
                $command = [...$command, '-hw_encoding', '1'];
            } elseif ($backend === 'videotoolbox') {
                $command = [...$command, '-allow_sw', '0', '-require_sw', '0'];
            }
        }

        $command = [...$command, '-frames:v', '1', '-progress', 'pipe:1', '-f', 'null', '-'];

        try {
            $result = $this->run(3, $command);
            $available = $result['successful'] && preg_match('/^frame=\s*[1-9]\d*\s*$/m', $result['output']) === 1;

            if ($available && $backend === 'vulkan') {
                $available = preg_match('/Device \d+ selected: .*\((?:integrated|discrete|virtual)\)/i', $result['error']) === 1;

                if (! $available) {
                    return ['available' => false, 'message' => 'FFmpeg did not confirm a hardware Vulkan device. Software Vulkan devices do not provide hardware acceleration.'];
                }
            }

            return [
                'available' => $available,
                'message' => $available
                    ? ($testedEncoder ? 'Encoded a generated frame with '.$testedEncoder.'.' : 'Initialized the default hardware device. Check individual encoders for codec support.')
                    : Str::limit(trim($result['error']) ?: 'The default device check did not complete successfully.', 2000),
            ];
        } catch (ProcessTimedOutException) {
            return ['available' => false, 'message' => 'The default device check timed out after 3 seconds.'];
        } catch (RuntimeException) {
            return ['available' => false, 'message' => 'The default device check could not start. Check application permissions.'];
        }
    }

    /** @return EncoderCheck */
    public function testEncoder(string $encoder): array
    {
        abort_unless($this->canTest($encoder), 404);

        $command = ['-hide_banner', '-nostdin', '-loglevel', 'error'];
        $filter = 'format=nv12';

        if (str_ends_with($encoder, '_vaapi')) {
            $command = [...$command, '-init_hw_device', 'vaapi=health', '-filter_hw_device', 'health'];
            $filter .= ',hwupload';
        } elseif (str_ends_with($encoder, '_qsv')) {
            $command = [...$command, '-init_hw_device', 'qsv=health:hw'];
        }

        $command = [...$command, '-f', 'lavfi', '-i', 'color=c=black:s=1280x720:r=1',
            '-vf', $filter, '-frames:v', '1', '-an', '-c:v', $encoder, '-progress', 'pipe:1', '-f', 'null', '-'];

        try {
            $result = $this->run(10, $command);
            $successful = $result['successful'] && preg_match('/^frame=\s*[1-9]\d*\s*$/m', $result['output']) === 1;

            return [
                'successful' => $successful,
                'message' => $successful
                    ? 'Encoded a generated frame successfully using the default device.'
                    : Str::limit(trim($result['error']) ?: 'FFmpeg did not encode a frame using the default device.', 2000),
            ];
        } catch (ProcessTimedOutException) {
            return ['successful' => false, 'message' => 'The encoder test timed out after 10 seconds.'];
        } catch (RuntimeException) {
            return ['successful' => false, 'message' => 'FFmpeg could not start the encoder test. Check application permissions.'];
        }
    }

    /**
     * @param  list<string>  $arguments
     * @return array{successful: bool, output: string, error: string}
     */
    private function run(int $timeout, array $arguments): array
    {
        $driver = app(FFMpegDriver::class);
        $driver->getProcessBuilderFactory()->setTimeout($timeout);
        $error = '';
        $listener = new DebugListener('', '', 'output', 'error');
        $listener->on('error', function (string $line) use (&$error): void {
            $error .= $line."\n";
        });

        try {
            $output = $driver->command($arguments, false, $listener);

            return ['successful' => true, 'output' => $output, 'error' => $error];
        } catch (ExecutionFailureException $exception) {
            if ($exception->getPrevious() instanceof ProcessTimedOutException) {
                throw $exception->getPrevious();
            }

            return ['successful' => false, 'output' => '', 'error' => $exception->getErrorOutput() ?? ''];
        }
    }

    private function canTest(string $encoder): bool
    {
        return preg_match('/^(h264|hevc|av1)_(nvenc|vaapi|qsv)$/D', $encoder) === 1;
    }

    /**
     * FFmpeg lists codec aliases in a trailing "(codec name)" annotation;
     * encoders without that annotation use their own name as the codec.
     *
     * @return list<Encoder>
     */
    private function parseEncoders(string $output): array
    {
        $encoders = [];

        foreach (explode("\n", $output) as $line) {
            if (! preg_match('/^\s*([VA])[A-Z.]{5}\s+([a-zA-Z0-9_\-]+)\s+(.+?)\s*$/', $line, $match)) {
                continue;
            }

            $name = $match[2];
            $suffix = Str::afterLast($name, '_');
            $backend = array_key_exists($suffix, self::BACKEND_LABELS) && ($suffix !== 'mf' || $match[1] === 'V') ? $suffix : null;
            $description = $match[3];
            $codec = preg_match('/\s+\(codec ([a-zA-Z0-9_\-]+)\)$/', $description, $codecMatch) ? $codecMatch[1] : $name;
            $encoders[] = [
                'name' => $name,
                'codec' => $codec,
                'type' => $match[1] === 'V' ? 'Video' : 'Audio',
                'description' => $description,
                'hardware' => $backend !== null,
                'backend' => $backend,
                'testable' => $this->canTest($name),
            ];
        }

        usort($encoders, fn (array $left, array $right): int => [$left['codec'], $left['name']] <=> [$right['codec'], $right['name']]);

        return $encoders;
    }
}
