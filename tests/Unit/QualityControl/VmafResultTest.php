<?php

use App\QualityControl\VmafResult;

/** @return array{frames: int, mean: float, min: float, max: float} */
function readQualityLog(string $xml, int $expected = 2): array
{
    $path = tempnam(dirname(__DIR__, 3).'/storage/framework/testing', 'vmaf-');
    if ($path === false) {
        throw new RuntimeException('Cannot create VMAF test fixture.');
    }
    try {
        file_put_contents($path, $xml);

        return (new VmafResult)->read($path, $expected);
    } finally {
        unlink($path);
    }
}

it('includes a genuine zero score in the frame-weighted result', function (): void {
    expect(readQualityLog('<VMAF><frames><frame frameNum="0" vmaf="0"/><frame frameNum="1" vmaf="100"/></frames></VMAF>'))
        ->toBe(['frames' => 2, 'mean' => 50.0, 'min' => 0.0, 'max' => 100.0]);
});

it('rejects incomplete or invalid frame results instead of passing on partial scores', function (string $xml): void {
    expect(fn () => readQualityLog($xml))->toThrow(RuntimeException::class);
})->with([
    '<VMAF><frames><frame frameNum="0" vmaf="100"/></frames></VMAF>',
    '<VMAF><frames><frame frameNum="0" vmaf="100"/><frame frameNum="1" vmaf="100"/>',
    '<VMAF><frames><frame frameNum="0" vmaf="100"/><frame frameNum="0" vmaf="100"/></frames></VMAF>',
    '<VMAF><frames><frame frameNum="0" vmaf="NaN"/><frame frameNum="1" vmaf="100"/></frames></VMAF>',
    '<VMAF><frames><frame frameNum="0"/><frame frameNum="1" vmaf="100"/></frames></VMAF>',
    '<!DOCTYPE VMAF [<!ENTITY score SYSTEM "file:///etc/passwd">]><VMAF><frames><frame frameNum="0" vmaf="&score;"/></frames></VMAF>',
]);
