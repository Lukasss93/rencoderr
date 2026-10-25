<?php

use App\Transcoding\SourceByteInventory;

it('counts streamed packet sizes across chunk boundaries without using bit rate metadata', function (): void {
    $inventory = new SourceByteInventory;
    $inventory->push("stream_index=0|size=100\nstream_index=1|si");
    $inventory->push("ze=30\nstream_index=1|size=20\n");
    expect($inventory->finish())->toBe([0 => 100, 1 => 50]);
});

it('fails closed for unknown packet sizes', function (): void {
    $inventory = new SourceByteInventory;
    expect(fn () => $inventory->push("stream_index=1|size=N/A\n"))->toThrow(RuntimeException::class);
});
