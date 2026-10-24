<?php

namespace App\Encoding;

use App\Encoding\Data\Backend;
use App\Encoding\Data\Detection;
use App\Encoding\Data\Encoder;
use App\Encoding\Enums\EncoderType;
use App\Ffmpeg\Enums\HardwareBackend;
use App\Ffmpeg\Ffmpeg;
use FFMpeg\Exception\ExecutableNotFoundException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

class EncoderDetector
{
    /**
     * Container startup forgets this key, so hardware changes are picked up after a restart.
     */
    public const string CACHE_KEY = 'encoding.detection';

    public function __construct(private Ffmpeg $ffmpeg, private BackendCheck $backends) {}

    public function current(): Detection
    {
        return Detection::fromArray(Cache::rememberForever(self::CACHE_KEY, fn (): array => $this->detect()->toArray()));
    }

    public function refresh(): Detection
    {
        return $this->store($this->detect());
    }

    public function store(Detection $detection): Detection
    {
        Cache::forever(self::CACHE_KEY, $detection->toArray());

        return $detection;
    }

    private function detect(): Detection
    {
        $detectedAt = now()->toImmutable();
        $version = null;

        try {
            $result = $this->ffmpeg->run(['-hide_banner', '-version'], 5);

            if (! $result->successful || ! preg_match('/^ffmpeg version (\S+)/m', $result->output, $match)) {
                return new Detection($detectedAt, error: 'Install FFmpeg and make sure the application can run it from PATH.');
            }

            $version = $match[1];
            $result = $this->ffmpeg->run(['-hide_banner', '-encoders'], 5);

            if (! $result->successful) {
                return new Detection($detectedAt, $version, 'FFmpeg could not list its encoders. Check the installation and try refreshing detection.');
            }

            $encoders = $this->parseEncoders($result->output);

            if ($encoders === []) {
                return new Detection($detectedAt, $version, 'No video or audio encoders were detected. Check the FFmpeg installation and try refreshing detection.');
            }

            [$backends, $accelerationError] = $this->detectBackends($encoders);

            return new Detection($detectedAt, $version, null, $encoders, $backends, $accelerationError);
        } catch (ProcessTimedOutException) {
            $error = 'FFmpeg detection timed out. Try refreshing detection.';
        } catch (ExecutableNotFoundException) {
            $error = 'Install FFmpeg and make sure the application can run it from PATH.';
        } catch (RuntimeException) {
            $error = 'FFmpeg could not be started. Check the installation and application permissions.';
        }

        return new Detection($detectedAt, $version, $error);
    }

    /**
     * Check every acceleration method FFmpeg lists, plus the backends of installed encoders that it
     * does not list, such as NVENC.
     *
     * @param  list<Encoder>  $encoders
     * @return array{array<string, Backend>, ?string}
     */
    private function detectBackends(array $encoders): array
    {
        $names = [];
        $error = null;

        try {
            $result = $this->ffmpeg->run(['-hide_banner', '-hwaccels'], 5);

            if (! $result->successful || ! str_contains($result->output, 'Hardware acceleration methods:')) {
                $error = 'FFmpeg could not list hardware acceleration methods. Encoder detection is still available.';
            } else {
                $names = array_filter(array_map(trim(...), explode("\n", $result->output)), fn (string $name): bool => preg_match('/^[a-z][a-z0-9_]*$/D', $name) === 1);
            }
        } catch (ProcessTimedOutException) {
            $error = 'Hardware acceleration detection timed out. Try refreshing detection.';
        } catch (RuntimeException) {
            $error = 'Hardware acceleration detection could not start. Check application permissions.';
        }

        foreach ($encoders as $encoder) {
            if ($encoder->backend !== null) {
                $names[] = $encoder->backend->value;
            }
        }

        $names = array_unique($names);
        sort($names);
        $backends = [];

        foreach ($names as $name) {
            $backends[$name] = $this->backends->check($name, $encoders);
        }

        return [$backends, $error];
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

            [, $type, $name, $description] = $match;
            $type = $type === 'V' ? EncoderType::VIDEO : EncoderType::AUDIO;
            $backend = HardwareBackend::tryFrom(Str::afterLast($name, '_'));
            $codec = preg_match('/\s+\(codec ([a-zA-Z0-9_\-]+)\)$/', $description, $codecMatch) ? $codecMatch[1] : $name;

            // Media Foundation audio encoders run in software.
            if ($backend === HardwareBackend::MF && $type === EncoderType::AUDIO) {
                $backend = null;
            }

            $encoders[] = new Encoder($name, $codec, $type, $description, $backend);
        }

        usort($encoders, fn (Encoder $left, Encoder $right): int => [$left->codec, $left->name] <=> [$right->codec, $right->name]);

        return $encoders;
    }
}
