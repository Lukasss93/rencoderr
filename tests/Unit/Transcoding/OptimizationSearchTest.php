<?php

use App\Transcoding\OptimizationSearch;

it('continues past minimum savings and chooses the smallest measured passing candidate', function (): void {
    $tried = [];
    $result = (new OptimizationSearch)->run(26, 0, 51, 8, function (int $quality) use (&$tried): array {
        $tried[] = $quality;

        return ['quality' => $quality, 'bytes' => 1000 - $quality * 10, 'passes' => $quality <= 35];
    });
    if ($result === null) {
        throw new RuntimeException('No candidate selected.');
    }
    expect(count($tried))->toBeGreaterThan(1)->toBeLessThanOrEqual(8)
        ->and($result['quality'])->toBe(35)
        ->and($result['bytes'])->toBe(650);
});

it('selects measured bytes rather than assuming quality settings imply size', function (): void {
    $result = (new OptimizationSearch)->run(26, 0, 51, 8, fn (int $quality): array => [
        'quality' => $quality, 'bytes' => $quality === 26 ? 10 : 100, 'passes' => $quality <= 35,
    ]);
    if ($result === null) {
        throw new RuntimeException('No candidate selected.');
    }
    expect($result['quality'])->toBe(26);
});

it('returns no candidate when every measurement fails quality', function (): void {
    expect((new OptimizationSearch)->run(26, 0, 51, 8, fn (int $q): array => [
        'quality' => $q, 'bytes' => 100, 'passes' => false,
    ]))->toBeNull();
});

it('propagates measurement failures instead of treating them as poor quality', function (): void {
    expect(fn () => (new OptimizationSearch)->run(26, 0, 51, 8, function (): array {
        throw new RuntimeException('decoder failed');
    }))->toThrow(RuntimeException::class, 'decoder failed');
});

it('reports a candidate limit separately from a fully explored boundary', function (): void {
    $termination = null;
    (new OptimizationSearch)->run(26, 0, 51, 1, fn (int $q): array => ['quality' => $q, 'bytes' => 100, 'passes' => true],
        function (string $reason) use (&$termination): void {
            $termination = $reason;
        });
    expect($termination)->toBe('candidate_limit');
});
