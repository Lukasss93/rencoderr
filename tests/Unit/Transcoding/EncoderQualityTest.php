<?php

use App\Encoding\Enums\VideoEncoder;
use App\Transcoding\EncoderQuality;

it('uses the encoder native quality range', function (): void {
    expect((new EncoderQuality(VideoEncoder::AV1_VAAPI, 160))->arguments())->toBe(['-rc_mode', 'CQP', '-global_quality', '160'])
        ->and((new EncoderQuality(VideoEncoder::LIBX265, 32))->arguments())->toBe(['-crf', '32']);
    expect(fn () => new EncoderQuality(VideoEncoder::LIBX265, 80))->toThrow(InvalidArgumentException::class);
});
