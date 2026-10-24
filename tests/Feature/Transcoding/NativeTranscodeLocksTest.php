<?php

use App\Jobs\TranscodeMediaFile;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $store = getenv('TRANSCODE_LOCK_STORE') ?: config('cache.default');
    config(['cache.default' => $store, 'cache.prefix' => 'transcode-lock-test-'.Str::uuid().':']);
    Cache::purge($store);
    app()->instance(Repository::class, Cache::store($store));
});

it('uses the configured native cache to suppress duplicate publication and protect a replacement owner', function (): void {
    $locks = new UniqueLock(Cache::store());
    $old = new TranscodeMediaFile(1, 23, 'old', 60);
    $replacement = new TranscodeMediaFile(2, 23, 'replacement', 60);
    try {
        expect($locks->acquire($old))->toBeTrue();
        expect($locks->acquire($replacement))->toBeFalse();
        Cache::lock(UniqueLock::getKey($old))->forceRelease();
        expect($locks->acquire($replacement))->toBeTrue();
        $locks->release($old);
        expect($locks->acquire(new TranscodeMediaFile(3, 23, 'duplicate', 60)))->toBeFalse();
    } finally {
        $locks->release($replacement);
        $locks->release($old);
    }
});

it('uses the actual overlap middleware to release contention until the current process finishes', function (): void {
    $first = (new TranscodeMediaFile(1, 31, 'first', 60))->withFakeQueueInteractions();
    $second = (new TranscodeMediaFile(2, 31, 'second', 60))->withFakeQueueInteractions();
    $ran = 0;
    $first->middleware()[0]->handle($first, function () use ($second, &$ran): void {
        $second->middleware()[0]->handle($second, function () use (&$ran): void {
            $ran++;
        });
    });
    expect($ran)->toBe(0);
    $second->assertReleased(60);
    $second->middleware()[0]->handle($second, function () use (&$ran): void {
        $ran++;
    });
    expect($ran)->toBe(1);
});
