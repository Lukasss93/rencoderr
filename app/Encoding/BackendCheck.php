<?php

namespace App\Encoding;

use App\Encoding\Data\Backend;
use App\Encoding\Data\Encoder;
use App\Encoding\Enums\EncoderType;
use App\Ffmpeg\Enums\HardwareBackend;
use App\Ffmpeg\Ffmpeg;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

class BackendCheck
{
    public function __construct(private Ffmpeg $ffmpeg) {}

    /** @param list<Encoder> $encoders */
    public function check(string $name, array $encoders, ?string $device = null): Backend
    {
        $backend = HardwareBackend::tryFrom($name);
        $own = array_values(array_filter($encoders, fn (Encoder $encoder): bool => $encoder->backend?->value === $name));
        [$available, $message] = $this->probe($name, $backend, $own, $device);

        return new Backend($name, HardwareBackend::labelFor($name), count($own), $available, $message, $device);
    }

    /**
     * @param  list<Encoder>  $backendEncoders
     * @return array{bool, string}
     */
    private function probe(string $name, ?HardwareBackend $backend, array $backendEncoders, ?string $selectedDevice): array
    {
        $encoder = null;

        if ($backend?->checksWithEncoder()) {
            $encoder = $this->widelySupportedVideoEncoder($backendEncoders);

            if ($encoder === null) {
                return [false, 'No supported video encoder is installed for this backend.'];
            }
        }

        $device = $backend === null ? $name.'=status' : $backend->statusDevice($selectedDevice);
        $arguments = ['-hide_banner', '-nostdin', '-loglevel', $backend === HardwareBackend::VULKAN ? 'verbose' : 'error'];

        if ($device !== null) {
            $arguments = [...$arguments, '-init_hw_device', $device];
        }

        $arguments = [...$arguments, '-f', 'lavfi', '-i', 'color=c=black:s=1280x720:r=1', '-vf', 'format=nv12'];

        if ($encoder !== null) {
            $arguments = [...$arguments, '-c:v', $encoder->name, ...$backend->hardwareOnlyArguments($selectedDevice)];
        }

        try {
            $result = $this->ffmpeg->run([...$arguments, '-frames:v', '1', '-progress', 'pipe:1', '-f', 'null', '-'], 3);
        } catch (ProcessTimedOutException) {
            return [false, 'The hardware device check timed out after 3 seconds.'];
        } catch (RuntimeException) {
            return [false, 'The hardware device check could not start. Check application permissions.'];
        }

        if (! $result->producedFrame()) {
            return [false, $result->errorMessage('The hardware device check did not complete successfully.')];
        }

        // Vulkan also runs on software devices such as llvmpipe, which do not accelerate anything.
        if ($backend === HardwareBackend::VULKAN && preg_match('/Device \d+ selected: .*\((?:integrated|discrete|virtual)\)/i', $result->error) !== 1) {
            return [false, 'FFmpeg did not confirm a hardware Vulkan device. Software Vulkan devices do not provide hardware acceleration.'];
        }

        return [true, $encoder === null
            ? 'Initialized '.($selectedDevice ?? 'the default hardware device').'. Check individual encoders for codec support.'
            : 'Encoded a generated frame with '.$encoder->name.'.'];
    }

    /**
     * Prefer H.264, then HEVC, so a GPU without newer codecs is not reported unavailable.
     *
     * @param  list<Encoder>  $encoders
     */
    private function widelySupportedVideoEncoder(array $encoders): ?Encoder
    {
        $priority = ['h264' => 0, 'hevc' => 1];
        $video = array_filter($encoders, fn (Encoder $encoder): bool => $encoder->type === EncoderType::VIDEO);
        usort($video, fn (Encoder $left, Encoder $right): int => ($priority[$left->codec] ?? 2) <=> ($priority[$right->codec] ?? 2));

        return $video[0] ?? null;
    }
}
