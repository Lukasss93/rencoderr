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
     * @param  list<string>  $prefix
     * @param  list<string>  $inputArguments
     * @param  list<string>  $videoArguments
     */
    public function __construct(
        public VideoEncoder $encoder,
        public MediaSettings $settings,
        public string $sourcePath,
        public int $sourceSizeBytes,
        public CarbonImmutable $sourceModifiedAt,
        public array $arguments,
        public array $streamDecisions,
        public array $prefix = [],
        public array $inputArguments = [],
        public array $videoArguments = [],
        public ?EncoderQuality $quality = null,
    ) {}

    public function withQuality(EncoderQuality $quality): self
    {
        if ($quality->encoder !== $this->encoder || $this->prefix === []) {
            throw new \InvalidArgumentException('Quality does not match a prepared encoder plan.');
        }

        return new self($this->encoder, $this->settings, $this->sourcePath, $this->sourceSizeBytes, $this->sourceModifiedAt,
            [...$this->prefix, ...$quality->arguments(), ...$this->videoArguments], $this->streamDecisions,
            $this->prefix, $this->inputArguments, $this->videoArguments, $quality);
    }

    /** @return list<string> */
    public function sampleArguments(string $reference): array
    {
        $quality = $this->quality ?? EncoderQuality::starting($this->encoder, $this->settings->videoQuality);

        return [...$this->inputArguments, '-i', $reference, '-map', '0:0', '-c:0', $this->encoder->value,
            ...$quality->arguments(), ...$this->videoArguments];
    }
}
