<?php

namespace App\Encoding;

use App\Encoding\Data\Encoder;
use App\Encoding\Data\EncoderCheck;
use App\Ffmpeg\Ffmpeg;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

class EncoderTester
{
    public function __construct(private Ffmpeg $ffmpeg, private EncoderDetector $detector) {}

    public function test(Encoder $encoder): EncoderCheck
    {
        $detection = $this->detector->current();
        $device = $detection->backends[$encoder->backend->value ?? '']->device ?? null;
        $filter = implode(',', array_filter(['format=nv12', $encoder->backend?->uploadFilter()]));
        $check = $this->encodeFrame([
            '-hide_banner', '-nostdin', '-loglevel', 'error', ...($encoder->backend?->encodingDeviceArguments($device) ?? []),
            '-f', 'lavfi', '-i', 'color=c=black:s=1280x720:r=1',
            '-vf', $filter, '-frames:v', '1', '-an', '-c:v', $encoder->name, ...($encoder->backend?->hardwareOnlyArguments($device) ?? []), '-progress', 'pipe:1', '-f', 'null', '-',
        ], $device);
        $this->detector->store($detection->withCheck($encoder, $check));

        return $check;
    }

    /** @param list<string> $arguments */
    private function encodeFrame(array $arguments, ?string $device): EncoderCheck
    {
        try {
            $result = $this->ffmpeg->run($arguments, 10);
        } catch (ProcessTimedOutException) {
            return new EncoderCheck(false, 'The encoder test timed out after 10 seconds.');
        } catch (RuntimeException) {
            return new EncoderCheck(false, 'FFmpeg could not start the encoder test. Check application permissions.');
        }

        $description = $device !== null ? 'device '.$device : 'the default device';

        return $result->producedFrame()
            ? new EncoderCheck(true, 'Encoded a generated frame successfully using '.$description.'.')
            : new EncoderCheck(false, $result->errorMessage('FFmpeg did not encode a frame using '.$description.'.'));
    }
}
