<?php

namespace App\Transcoding;

use App\Encoding\Data\MediaSettings;
use App\Encoding\Enums\VideoEncoder;
use Carbon\CarbonImmutable;

readonly class TranscodePlan
{
    /**
     * @param  list<string>  $arguments
     * @param  list<array{index: int, type: string, action: string}>  $streamDecisions
     */
    public function __construct(
        public VideoEncoder $encoder,
        public MediaSettings $settings,
        public string $sourcePath,
        public int $sourceSizeBytes,
        public CarbonImmutable $sourceModifiedAt,
        public array $arguments,
        public array $streamDecisions,
    ) {}
}
