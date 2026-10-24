<?php

use App\Encoding\Enums\AudioCodec;
use App\Encoding\Enums\EncodingSpeed;
use App\Encoding\Enums\TargetVideoCodec;
use App\Encoding\Enums\VideoEncoder;
use App\Encoding\Enums\VideoQuality;
use App\Ffmpeg\Enums\HardwareBackend;

it('maps native encoder names to their target codec and hardware backend', function (VideoEncoder $encoder, TargetVideoCodec $codec, ?HardwareBackend $backend): void {
    expect($encoder->targetCodec())->toBe($codec);
    expect($encoder->backend())->toBe($backend);
})->with([
    [VideoEncoder::LIBX265, TargetVideoCodec::HEVC, null], [VideoEncoder::LIBSVTAV1, TargetVideoCodec::AV1, null],
    [VideoEncoder::HEVC_NVENC, TargetVideoCodec::HEVC, HardwareBackend::NVENC], [VideoEncoder::AV1_NVENC, TargetVideoCodec::AV1, HardwareBackend::NVENC],
    [VideoEncoder::HEVC_QSV, TargetVideoCodec::HEVC, HardwareBackend::QSV], [VideoEncoder::AV1_QSV, TargetVideoCodec::AV1, HardwareBackend::QSV],
    [VideoEncoder::HEVC_VAAPI, TargetVideoCodec::HEVC, HardwareBackend::VAAPI], [VideoEncoder::AV1_VAAPI, TargetVideoCodec::AV1, HardwareBackend::VAAPI],
]);

it('provides the exact quality and speed arguments for every encoder', function (VideoEncoder $encoder, array $quality, array $speed): void {
    expect(VideoQuality::HIGH->arguments($encoder))->toBe($quality[0]);
    expect(VideoQuality::BALANCED->arguments($encoder))->toBe($quality[1]);
    expect(VideoQuality::SMALLER->arguments($encoder))->toBe($quality[2]);
    expect(EncodingSpeed::FAST->arguments($encoder))->toBe($speed[0]);
    expect(EncodingSpeed::BALANCED->arguments($encoder))->toBe($speed[1]);
    expect(EncodingSpeed::SLOW->arguments($encoder))->toBe($speed[2]);
})->with([
    [VideoEncoder::LIBX265, [['-crf', '20'], ['-crf', '23'], ['-crf', '26']], [['-preset', 'fast'], ['-preset', 'medium'], ['-preset', 'slow']]],
    [VideoEncoder::LIBSVTAV1, [['-crf', '24'], ['-crf', '28'], ['-crf', '32']], [['-preset', '8'], ['-preset', '6'], ['-preset', '4']]],
    [VideoEncoder::HEVC_NVENC, [['-rc', 'vbr', '-b:v', '0', '-cq', '20'], ['-rc', 'vbr', '-b:v', '0', '-cq', '23'], ['-rc', 'vbr', '-b:v', '0', '-cq', '26']], [['-preset', 'p3'], ['-preset', 'p5'], ['-preset', 'p7']]],
    [VideoEncoder::AV1_NVENC, [['-rc', 'vbr', '-b:v', '0', '-cq', '20'], ['-rc', 'vbr', '-b:v', '0', '-cq', '23'], ['-rc', 'vbr', '-b:v', '0', '-cq', '26']], [['-preset', 'p3'], ['-preset', 'p5'], ['-preset', 'p7']]],
    [VideoEncoder::HEVC_QSV, [['-global_quality', '20'], ['-global_quality', '23'], ['-global_quality', '26']], [['-preset', 'fast'], ['-preset', 'medium'], ['-preset', 'slow']]],
    [VideoEncoder::AV1_QSV, [['-global_quality', '20'], ['-global_quality', '23'], ['-global_quality', '26']], [['-preset', 'fast'], ['-preset', 'medium'], ['-preset', 'slow']]],
    [VideoEncoder::HEVC_VAAPI, [['-rc_mode', 'CQP', '-global_quality', '20'], ['-rc_mode', 'CQP', '-global_quality', '23'], ['-rc_mode', 'CQP', '-global_quality', '26']], [['-compression_level', '7'], ['-compression_level', '4'], ['-compression_level', '1']]],
    [VideoEncoder::AV1_VAAPI, [['-rc_mode', 'CQP', '-global_quality', '80'], ['-rc_mode', 'CQP', '-global_quality', '96'], ['-rc_mode', 'CQP', '-global_quality', '112']], [['-compression_level', '7'], ['-compression_level', '4'], ['-compression_level', '1']]],
]);

it('uses the requested native software and audio encoder names', function (): void {
    expect(TargetVideoCodec::HEVC->softwareEncoder())->toBe(VideoEncoder::LIBX265);
    expect(TargetVideoCodec::AV1->softwareEncoder())->toBe(VideoEncoder::LIBSVTAV1);
    expect(AudioCodec::COPY->encoder())->toBe('copy');
    expect(AudioCodec::AAC->encoder())->toBe('aac');
    expect(AudioCodec::OPUS->encoder())->toBe('libopus');
});
