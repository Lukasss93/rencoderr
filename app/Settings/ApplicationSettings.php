<?php

namespace App\Settings;

use App\Encoding\FfmpegHealth;
use App\Models\Setting;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** @phpstan-import-type Detection from FfmpegHealth */
class ApplicationSettings
{
    /** @var list<string> */
    private const array HARDWARE_PRIORITY = ['nvenc', 'qsv', 'vaapi', 'amf', 'videotoolbox', 'vulkan', 'v4l2m2m', 'omx', 'mediacodec', 'mf'];

    public function queueMaxWorkers(): int
    {
        $value = Setting::query()->where('key', 'queue_max_workers')->first()?->value;

        return is_int($value) && $value > 0
            ? $value
            : max(1, (int) config('horizon.defaults.supervisor-1.maxProcesses', 1));
    }

    public function saveQueueMaxWorkers(mixed $maxWorkers): void
    {
        $validated = Validator::make(['maxWorkers' => $maxWorkers], [
            'maxWorkers' => ['required', 'integer', 'min:1'],
        ], attributes: ['maxWorkers' => 'maximum workers'])->validate();

        Setting::query()->updateOrCreate(['key' => 'queue_max_workers'], ['value' => (int) $validated['maxWorkers']]);
    }

    public function hardwareAcceleration(): string
    {
        $value = Setting::query()->where('key', 'hardware_acceleration')->first()?->value;

        return is_string($value) ? $value : 'auto';
    }

    /** @param Detection $report */
    public function saveHardwareAcceleration(string $backend, array $report): void
    {
        Validator::make(['preferredAcceleration' => $backend], [
            'preferredAcceleration' => ['required', Rule::in(['auto', 'software', ...array_keys($this->availableHardwareBackends($report))])],
        ], [
            'preferredAcceleration.in' => 'Choose an available hardware encoding backend, Automatic, or Software (CPU).',
            'preferredAcceleration.required' => 'Choose a hardware acceleration preference.',
        ])->validate();

        Setting::query()->updateOrCreate(['key' => 'hardware_acceleration'], ['value' => $backend]);
    }

    /**
     * @param  Detection  $report
     * @return array<string, string>
     */
    public function availableHardwareBackends(array $report, ?string $codec = null): array
    {
        if ($report['error'] !== null) {
            return [];
        }

        $available = [];

        foreach ($report['encoders'] as $encoder) {
            $backend = $encoder['backend'];

            if ($encoder['type'] !== 'Video' || ! $encoder['hardware'] || $backend === null || ($codec !== null && $encoder['codec'] !== $codec)) {
                continue;
            }

            if ($report['acceleration']['backends'][$backend]['available'] ?? false) {
                $available[$backend] = $report['acceleration']['backends'][$backend]['label'];
            }
        }

        $priorities = array_flip(self::HARDWARE_PRIORITY);
        uksort($available, fn (string $left, string $right): int => [($priorities[$left] ?? PHP_INT_MAX), $left] <=> [($priorities[$right] ?? PHP_INT_MAX), $right]);

        return $available;
    }

    /**
     * Resolve the saved preference for a job's current detection report and optional codec.
     * A null backend means software encoding, including when the preferred hardware is unavailable.
     *
     * @param  Detection  $report
     */
    public function resolveHardwareAcceleration(array $report, ?string $codec = null): ?string
    {
        $preference = $this->hardwareAcceleration();
        $available = $this->availableHardwareBackends($report, $codec);

        if ($preference === 'auto') {
            return array_key_first($available);
        }

        return isset($available[$preference]) ? $preference : null;
    }
}
