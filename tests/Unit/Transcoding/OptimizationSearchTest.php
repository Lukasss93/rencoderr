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
    $confirmed = [];
    $result = (new OptimizationSearch)->run(adaptivePolicy(), function (int $q) use (&$seen): array {
        $seen[] = $q;

        return ['quality' => $q, 'bytes' => 1000000 - $q * 10000, 'vmaf' => 100 - $q * 0.15];
    }, function (array $result) use (&$confirmed): array {
        $confirmed[] = $result['quality'];

        return $result;
    });
    expect($seen[0])->toBe(28)->and($seen)->not->toContain(51)
        ->and(count($seen))->toBe(count(array_unique($seen)))
        ->and($result['quality'] ?? null)->toBe(33)->and($confirmed)->toContain(33);
});

it('refines the size boundary before rejecting a source with incompatible quality and savings', function (): void {
    $seen = [];
    $reason = null;
    $closest = null;
    $result = (new OptimizationSearch)->run(adaptivePolicy(floor: 93, bytes: 1479448523, fixed: 99394359), function (int $q) use (&$seen): array {
        $seen[] = $q;

        return match ($q) {
            28 => ['quality' => 28, 'bytes' => 1030000000, 'vmaf' => 88.0],
            26 => ['quality' => 26, 'bytes' => 1370000000, 'vmaf' => 90.5],
            25 => ['quality' => 25, 'bytes' => 1600000000, 'vmaf' => 91.5],
            default => throw new RuntimeException('Unnecessary distant probe: '.$q),
        };
    }, function (): array {
        throw new RuntimeException('An infeasible candidate cannot be confirmed');
    },
        function (string $value) use (&$reason): void {
            $reason = $value;
        },
        function (array $event) use (&$closest): void {
            $closest = $event['closest'] ?? $closest;
        });
    expect($result)->toBeNull()->and($seen)->toBe([28, 26, 25])
        ->and($reason)->toBe('quality_size_conflict')->and($closest['quality'] ?? null)->toBe(26);
});

it('does not use the candidate budget to choose quality increments', function (): void {
    $paths = [];
    foreach ([8, 16] as $limit) {
        $seen = [];
        (new OptimizationSearch)->run(adaptivePolicy(limit: $limit), function (int $q) use (&$seen): array {
            $seen[] = $q;

            return ['quality' => $q, 'bytes' => 100, 'vmaf' => 100 - $q * 0.5];
        }, fn (array $result): array => $result);
        $paths[] = array_slice($seen, 0, 3);
    }
    expect($paths[0])->toBe([28, 26, 18])->and($paths[1])->toBe($paths[0]);
});

it('learns from failed confirmation and chooses a higher quality that really passes', function (): void {
    $seen = [];
    $checks = [];
    $result = (new OptimizationSearch)->run(adaptivePolicy(), function (int $q) use (&$seen): array {
        $seen[] = $q;

        return ['quality' => $q, 'bytes' => 1000000 - $q * 10000, 'vmaf' => 100 - $q * 0.15];
    }, function (array $point) use (&$checks): array {
        $checks[] = $point['quality'];

        return [...$point, 'vmaf' => $point['quality'] > 31 ? 94.0 : $point['vmaf']];
    });
    expect($result['quality'] ?? null)->toBe(31)->and($checks)->toContain(33, 31)
        ->and(count($seen))->toBeLessThanOrEqual(8)->toBe(count(array_unique($seen)));
});

it('reserves confirmation after exploration exhausts its preparation budget', function (): void {
    $calls = 0;
    $reason = null;
    $result = (new OptimizationSearch)->run(adaptivePolicy(), function (int $q) use (&$calls): array {
        if (++$calls === 3) {
            throw new OptimizationLimit('Search deadline');
        }

        return ['quality' => $q, 'bytes' => 1000 - $q, 'vmaf' => 97.0];
    }, fn (array $point): array => $point, function (string $value) use (&$reason): void {
        $reason = $value;
    });
    expect($result)->not->toBeNull()->and($reason)->toBe('preparation_limit')->and($calls)->toBe(3);
});

it('propagates decoder and metric failures instead of accepting partial results', function (): void {
    expect(fn () => (new OptimizationSearch)->run(adaptivePolicy(), function (): array {
        throw new RuntimeException('decoder failed');
    }, fn (array $point): array => $point))->toThrow(RuntimeException::class, 'decoder failed');
});

it('returns no candidate for genuine zero quality scores', function (): void {
    expect((new OptimizationSearch)->run(adaptivePolicy(), fn (int $q): array => ['quality' => $q, 'bytes' => 100, 'vmaf' => 0.0],
        fn (array $point): array => $point))->toBeNull();
});

it('chooses measured bytes even when size measurements are nonmonotonic', function (): void {
    $result = (new OptimizationSearch)->run(adaptivePolicy(), fn (int $q): array => [
        'quality' => $q, 'bytes' => $q === 30 ? 1 : 1000, 'vmaf' => 97.0,
    ], fn (array $point): array => $point);
    expect($result['quality'] ?? null)->toBe(30)->and($result['bytes'] ?? null)->toBe(1);
});

it('confirms the initial point when it is the only allowed candidate', function (): void {
    $confirmed = false;
    $result = (new OptimizationSearch)->run(adaptivePolicy(limit: 1), fn (int $q): array => ['quality' => $q, 'bytes' => 100, 'vmaf' => 97.0],
        function (array $point) use (&$confirmed): array {
            $confirmed = true;

            return $point;
        });
    expect($confirmed)->toBeTrue()->and($result['quality'] ?? null)->toBe(28);
});

it('handles the wider AV1 VAAPI quality scale with measured projections', function (): void {
    $seen = [];
    $result = (new OptimizationSearch)->run(adaptivePolicy(start: 112, encoder: VideoEncoder::AV1_VAAPI), function (int $q) use (&$seen): array {
        $seen[] = $q;

        return ['quality' => $q, 'bytes' => 1000000 - $q * 1000, 'vmaf' => 100 - $q / 40];
    }, fn (array $point): array => $point);
    expect($seen[0])->toBe(112)->and($seen)->not->toContain(255)->and($result['quality'] ?? null)->toBe(200);
});

it('keeps confirmation evidence separate and moves toward better quality when every holdout fails', function (): void {
    $seen = [];
    $result = (new OptimizationSearch)->run(adaptivePolicy(floor: 96, bytes: 2000000), function (int $q) use (&$seen): array {
        $seen[] = $q;

        return ['quality' => $q, 'bytes' => 1000000 - $q * 10000, 'vmaf' => 100 - $q * 0.1];
    }, fn (array $point): array => [...$point, 'vmaf' => 98.7 - $point['quality'] * 0.1]);
    expect($result['quality'] ?? null)->toBe(27)->and($seen)->toContain(27);
    expect($seen)->not->toContain(42, 43, 44);
});

it('reports measured adjacent size conflicts even on the final allowed candidate', function (): void {
    $reason = null;
    (new OptimizationSearch)->run(adaptivePolicy(limit: 3, floor: 93, bytes: 1479448523), fn (int $q): array => match ($q) {
        28 => ['quality' => 28, 'bytes' => 1030000000, 'vmaf' => 88.0],
        26 => ['quality' => 26, 'bytes' => 1370000000, 'vmaf' => 90.5],
        default => ['quality' => $q, 'bytes' => 1600000000, 'vmaf' => 91.5],
    }, fn (array $point): array => $point, function (string $value) use (&$reason): void {
        $reason = $value;
    });
    expect($reason)->toBe('quality_size_conflict');
});
