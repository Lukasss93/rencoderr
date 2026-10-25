<?php

use App\QualityControl\PacketSpan;

it('measures audio packet boundaries with bounded streaming input', function (): void {
    $span = new PacketSpan;
    $data = "packets.packet.0.pts_time=\"-0.021\"\npackets.packet.0.duration_time=\"0.021\"\npackets.packet.1.pts_time=\"0.000\"\npackets.packet.1.duration_time=\"0.021\"\n";
    foreach (str_split($data, 13) as $chunk) {
        $span->push($chunk);
    }
    expect($span->finish())->toBe(['start' => -0.021, 'end' => 0.021]);
});

it('fails closed when audio timing is absent or incomplete', function (string $data): void {
    $span = new PacketSpan;
    expect(function () use ($span, $data): void {
        $span->push($data);
        $span->finish();
    })->toThrow(RuntimeException::class);
})->with(['', "packets.packet.0.pts_time=\"0\"\n", "packets.packet.0.pts_time=\"N/A\"\npackets.packet.0.duration_time=\"0.02\"\n"]);
