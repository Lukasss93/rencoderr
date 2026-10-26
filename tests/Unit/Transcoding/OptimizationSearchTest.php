<?php

use App\Encoding\Enums\VideoEncoder;
use App\Transcoding\EncoderQuality;
use App\Transcoding\OptimizationLimit;
use App\Transcoding\OptimizationSearch;
use App\Transcoding\OptimizationSearchPolicy;

function adaptivePolicy(int $start = 28, int $limit = 8, float $floor = 95, int $bytes = 1000000, int $fixed = 0, VideoEncoder $encoder = VideoEncoder::LIBX265): OptimizationSearchPolicy
{
    return new OptimizationSearchPolicy(new EncoderQuality($encoder, $start), $limit, $floor, $bytes, $fixed);
}

it('starts near the normal preset and refines the measured quality boundary', function (): void {
    $seen = [];
    $result = (new OptimizationSearch)->run(adaptivePolicy(), function (int $q) use (&$seen): array {
        $seen[] = $q;

        return ['quality' => $q, 'bytes' => 1000000 - $q * 10000, 'vmaf' => 100 - $q * 0.15, 'encode_seconds' => 0.1];
    });
    expect($seen[0])->toBe(28)->and($seen)->not->toContain(51)
        ->and(count($seen))->toBe(count(array_unique($seen)))
        ->and($result['winner']['quality'] ?? null)->toBe(33);
});

it('refines the size boundary before rejecting a source with incompatible quality and savings', function (): void {
    $seen = [];
    $closest = null;
    $result = (new OptimizationSearch)->run(adaptivePolicy(floor: 93, bytes: 1479448523, fixed: 99394359), function (int $q) use (&$seen): array {
        $seen[] = $q;

        return match ($q) {
            28 => ['quality' => 28, 'bytes' => 1030000000, 'vmaf' => 88.0, 'encode_seconds' => 0.1],
            26 => ['quality' => 26, 'bytes' => 1370000000, 'vmaf' => 90.5, 'encode_seconds' => 0.1],
            25 => ['quality' => 25, 'bytes' => 1600000000, 'vmaf' => 91.5, 'encode_seconds' => 0.1],
            default => throw new RuntimeException('Unnecessary distant probe: '.$q),
        };
    }, function (array $event) use (&$closest): void {
        $closest = $event['closest'] ?? $closest;
    });
    expect($result['winner'])->toBeNull()->and($seen)->toBe([28, 26, 25])
        ->and($result['termination'])->toBe('quality_size_conflict')->and($closest['quality'] ?? null)->toBe(26);
});

it('does not use the candidate budget to choose quality increments', function (): void {
    $paths = [];
    foreach ([8, 16] as $limit) {
        $seen = [];
        (new OptimizationSearch)->run(adaptivePolicy(limit: $limit), function (int $q) use (&$seen): array {
            $seen[] = $q;

            return ['quality' => $q, 'bytes' => 100, 'vmaf' => 100 - $q * 0.5, 'encode_seconds' => 0.1];
        });
        $paths[] = array_slice($seen, 0, 3);
    }
    expect($paths[0])->toBe([28, 26, 18])->and($paths[1])->toBe($paths[0]);
});

it('chooses a higher quality when sampled candidates fail the quality floor', function (): void {
    $seen = [];
    $result = (new OptimizationSearch)->run(adaptivePolicy(), function (int $q) use (&$seen): array {
        $seen[] = $q;

        return ['quality' => $q, 'bytes' => 1000000 - $q * 10000, 'vmaf' => $q > 31 ? 94.0 : 97.0, 'encode_seconds' => 0.1];
    });
    expect($result['winner']['quality'] ?? null)->toBe(31)
        ->and(count($seen))->toBeLessThanOrEqual(8)->toBe(count(array_unique($seen)));
});

it('retains the best sampled candidate when the preparation budget expires', function (): void {
    $calls = 0;
    $result = (new OptimizationSearch)->run(adaptivePolicy(), function (int $q) use (&$calls): array {
        if (++$calls === 3) {
            throw new OptimizationLimit('Search deadline');
        }

        return ['quality' => $q, 'bytes' => 1000 - $q, 'vmaf' => 97.0, 'encode_seconds' => 0.1];
    });
    expect($result['winner'])->not->toBeNull()->and($result['termination'])->toBe('preparation_limit')->and($calls)->toBe(3);
});

it('propagates decoder and metric failures instead of accepting partial results', function (): void {
    expect(fn () => (new OptimizationSearch)->run(adaptivePolicy(), function (): array {
        throw new RuntimeException('decoder failed');
    }))->toThrow(RuntimeException::class, 'decoder failed');
});

it('returns no candidate for genuine zero quality scores', function (): void {
    expect((new OptimizationSearch)->run(adaptivePolicy(), fn (int $q): array => ['quality' => $q, 'bytes' => 100, 'vmaf' => 0.0, 'encode_seconds' => 0.1])['winner'])->toBeNull();
});

it('chooses measured bytes even when size measurements are nonmonotonic', function (): void {
    $result = (new OptimizationSearch)->run(adaptivePolicy(), fn (int $q): array => [
        'quality' => $q, 'bytes' => $q === 30 ? 1 : 1000, 'vmaf' => 97.0, 'encode_seconds' => 0.1,
    ]);
    expect($result['winner']['quality'] ?? null)->toBe(30)->and($result['winner']['bytes'] ?? null)->toBe(1);
});

it('selects the initial sample when it is the only allowed candidate', function (): void {
    $seen = [];
    $result = (new OptimizationSearch)->run(adaptivePolicy(limit: 1), function (int $q) use (&$seen): array {
        $seen[] = $q;

        return ['quality' => $q, 'bytes' => 100, 'vmaf' => 97.0, 'encode_seconds' => 0.1];
    });
    expect($result['winner'])->toBe(['quality' => 28, 'bytes' => 100, 'vmaf' => 97.0, 'encode_seconds' => 0.1])
        ->and($seen)->toBe([28]);
});

it('handles the wider AV1 VAAPI quality scale with measured projections', function (): void {
    $seen = [];
    $result = (new OptimizationSearch)->run(adaptivePolicy(start: 112, encoder: VideoEncoder::AV1_VAAPI), function (int $q) use (&$seen): array {
        $seen[] = $q;

        return ['quality' => $q, 'bytes' => 1000000 - $q * 1000, 'vmaf' => 100 - $q / 40, 'encode_seconds' => 0.1];
    });
    expect($seen[0])->toBe(112)->and($seen)->not->toContain(255)->and($result['winner']['quality'] ?? null)->toBe(200);
});

it('selects a passing sample without requiring a second measurement', function (): void {
    $seen = [];
    $result = (new OptimizationSearch)->run(adaptivePolicy(floor: 96, bytes: 2000000), function (int $q) use (&$seen): array {
        if (in_array($q, $seen, true)) {
            throw new RuntimeException('A quality setting was measured twice');
        }
        $seen[] = $q;

        return ['quality' => $q, 'bytes' => 1000000 - $q * 10000, 'vmaf' => 100 - $q * 0.1, 'encode_seconds' => 0.1];
    });
    expect($result['winner']['quality'] ?? null)->toBe(40)
        ->and(count($seen))->toBeLessThanOrEqual(8);
});

it('reports measured adjacent size conflicts even on the final allowed candidate', function (): void {
    $result = (new OptimizationSearch)->run(adaptivePolicy(limit: 3, floor: 93, bytes: 1479448523), fn (int $q): array => match ($q) {
        28 => ['quality' => 28, 'bytes' => 1030000000, 'vmaf' => 88.0, 'encode_seconds' => 0.1],
        26 => ['quality' => 26, 'bytes' => 1370000000, 'vmaf' => 90.5, 'encode_seconds' => 0.1],
        default => ['quality' => $q, 'bytes' => 1600000000, 'vmaf' => 91.5, 'encode_seconds' => 0.1],
    });
    expect($result['termination'])->toBe('quality_size_conflict');
});

it('ranks passing candidates by size then quality then encoding time', function (int $firstBytes, float $firstVmaf, float $firstSeconds, int $nextBytes, float $nextVmaf, float $nextSeconds, int $expectedQuality): void {
    $winners = [];
    $result = (new OptimizationSearch)->run(adaptivePolicy(limit: 2), fn (int $q): array => [
        'quality' => $q,
        'bytes' => $q === 28 ? $firstBytes : $nextBytes,
        'vmaf' => $q === 28 ? $firstVmaf : $nextVmaf,
        'encode_seconds' => $q === 28 ? $firstSeconds : $nextSeconds,
    ], trace: function (array $event) use (&$winners): void {
        if ($event['phase'] === 'measured') {
            $winners[] = $event['winner']['quality'] ?? null;
        }
    });

    expect($result['winner']['quality'] ?? null)->toBe($expectedQuality);
    expect($winners)->toBe([28, $expectedQuality]);
})->with([
    'size takes precedence' => [1000, 97.0, 1.0, 1001, 99.0, 0.5, 28],
    'quality breaks size ties' => [1000, 97.0, 1.0, 1000, 98.0, 2.0, 30],
    'encoding time breaks quality ties' => [1000, 97.0, 2.0, 1000, 97.0, 1.0, 30],
]);
