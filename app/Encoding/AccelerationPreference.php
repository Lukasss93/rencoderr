<?php

namespace App\Encoding;

use App\Encoding\Data\Detection;
use App\Encoding\Enums\EncoderType;
use App\Ffmpeg\Enums\HardwareBackend;
use App\Models\Setting;

class AccelerationPreference
{
    public const string AUTOMATIC = 'auto';

    public const string SOFTWARE = 'software';

    private const string SETTING = 'hardware_acceleration';

    public function saved(): string
    {
        $value = Setting::valueOf(self::SETTING);

        return is_string($value) ? $value : self::AUTOMATIC;
    }

    public function save(string $preference): void
    {
        Setting::put(self::SETTING, $preference);
    }

    /** @return list<string> */
    public function choices(Detection $detection): array
    {
        return [self::AUTOMATIC, self::SOFTWARE, ...array_map(fn (HardwareBackend $backend): string => $backend->value, $this->availableBackends($detection))];
    }

    /** @return list<HardwareBackend> */
    public function availableBackends(Detection $detection, ?string $codec = null): array
    {
        if ($detection->error !== null) {
            return [];
        }

        $available = [];

        foreach ($detection->encoders as $encoder) {
            if ($encoder->type === EncoderType::VIDEO && $encoder->backend !== null && ($codec === null || $encoder->codec === $codec)
                && $detection->isBackendAvailable($encoder->backend->value)) {
                $available[$encoder->backend->value] = $encoder->backend;
            }
        }

        usort($available, fn (HardwareBackend $left, HardwareBackend $right): int => [$left->priority(), $left->value] <=> [$right->priority(), $right->value]);

        return $available;
    }

    /**
     * Null means software, including when the preferred backend is unavailable.
     */
    public function resolve(Detection $detection, ?string $codec = null): ?HardwareBackend
    {
        $available = $this->availableBackends($detection, $codec);
        $preference = $this->saved();

        if ($preference === self::AUTOMATIC) {
            return $available[0] ?? null;
        }

        return in_array(HardwareBackend::tryFrom($preference), $available, true) ? HardwareBackend::from($preference) : null;
    }
}
