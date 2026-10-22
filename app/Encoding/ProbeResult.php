<?php

namespace App\Encoding;

use App\Exceptions\Encoding\MalformedProbeResult;
use JsonException;
use stdClass;

readonly class ProbeResult
{
    /**
     * @param  array<string, mixed>  $metadata
     * @param  list<array<string, int|string|bool|null>>  $streams
     */
    private function __construct(
        public array $metadata,
        public ?string $format,
        public ?string $duration,
        public ?int $bitRate,
        public array $streams,
    ) {}

    public static function fromJson(string $json): self
    {
        try {
            $object = json_decode($json, flags: JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            $metadata = json_decode($json, true, flags: JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException $exception) {
            throw new MalformedProbeResult('FFprobe returned invalid JSON.', previous: $exception);
        }

        if (! $object instanceof stdClass || ! ($object->format ?? null) instanceof stdClass
            || ! is_array($object->streams ?? null) || ! is_array($metadata)) {
            throw new MalformedProbeResult('FFprobe did not return a format and a list of streams.');
        }

        $streams = [];
        $indexes = [];
        foreach ($object->streams as $stream) {
            if (! $stream instanceof stdClass || ! is_int($stream->index ?? null) || $stream->index < 0 || isset($indexes[$stream->index])) {
                throw new MalformedProbeResult('FFprobe returned a missing, invalid, or duplicate stream index.');
            }
            $indexes[$stream->index] = true;
            $streams[] = self::stream($stream);
        }

        return new self($metadata, self::text($object->format->format_name ?? null), self::decimal($object->format->duration ?? null), self::integer($object->format->bit_rate ?? null), $streams);
    }

    /** @return array<string, int|string|bool|null> */
    private static function stream(stdClass $stream): array
    {
        $row = ['stream_index' => $stream->index];
        foreach (['codec_type', 'codec_name', 'profile', 'color_range', 'color_space', 'color_transfer', 'color_primaries', 'channel_layout'] as $key) {
            $row[$key] = self::text($stream->{$key} ?? null);
        }
        foreach (['bit_rate', 'width', 'height', 'sample_rate', 'channels'] as $key) {
            $row[$key] = self::integer($stream->{$key} ?? null);
        }
        $row['duration_seconds'] = self::decimal($stream->duration ?? null);
        $row['pixel_format'] = self::text($stream->pix_fmt ?? null);
        $row['average_frame_rate'] = self::fraction($stream->avg_frame_rate ?? null);
        $row['real_frame_rate'] = self::fraction($stream->r_frame_rate ?? null);
        foreach (['language', 'title'] as $key) {
            $row[$key] = self::text($stream->tags->{$key} ?? null);
        }
        foreach (['is_default' => 'default', 'is_forced' => 'forced', 'is_attached_picture' => 'attached_pic'] as $key => $flag) {
            $value = $stream->disposition->{$flag} ?? null;
            $row[$key] = in_array($value, [0, 1], true) ? (bool) $value : null;
        }

        return $row;
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' && $value !== 'N/A' ? $value : null;
    }

    private static function integer(mixed $value): ?int
    {
        if (! is_int($value) && ! is_string($value)) {
            return null;
        }
        $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        return $number === false ? null : $number;
    }

    private static function decimal(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }
        $value = (string) $value;

        return preg_match('/^\d{1,14}(?:\.\d+)?$/D', $value) === 1 ? $value : null;
    }

    private static function fraction(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[1-9]\d*\/[1-9]\d*$/D', $value) === 1 ? $value : null;
    }
}
