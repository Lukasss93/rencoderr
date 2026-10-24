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
        $filter = implode(',', array_filter(['format=nv12', $encoder->backend?->uploadFilter()]));
        $check = $this->encodeFrame([
            '-hide_banner', '-nostdin', '-loglevel', 'error', ...($encoder->backend?->encodingDeviceArguments() ?? []),
            '-f', 'lavfi', '-i', 'color=c=black:s=1280x720:r=1',
            '-vf', $filter, '-frames:v', '1', '-an', '-c:v', $encoder->name, '-progress', 'pipe:1', '-f', 'null', '-',
        ]);
        $this->detector->store($this->detector->current()->withCheck($encoder, $check));

        return $check;
    }

    /** @param list<string> $arguments */
    private function encodeFrame(array $arguments): EncoderCheck
    {
        try {
            $result = $this->ffmpeg->run($arguments, 10);
        } catch (ProcessTimedOutException) {
            return new EncoderCheck(false, 'The encoder test timed out after 10 seconds.');
        } catch (RuntimeException) {
            return new EncoderCheck(false, 'FFmpeg could not start the encoder test. Check application permissions.');
        }

        return $result->producedFrame()
            ? new EncoderCheck(true, 'Encoded a generated frame successfully using the default device.')
            : new EncoderCheck(false, $result->errorMessage('FFmpeg did not encode a frame using the default device.'));
    }
}
