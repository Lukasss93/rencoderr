<?php

use App\Models\Library;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

it('allows the same setting key globally and on different polymorphic owners', function (): void {
    $library = Library::factory()->create();
    $other = Library::factory()->create();
    $user = User::factory()->create();
    $librarySetting = Setting::factory()->for($library, 'settingable')->create(['key' => 'media.video_codec', 'value' => 'av1']);
    Setting::factory()->for($other, 'settingable')->create(['key' => 'media.video_codec', 'value' => 'hevc']);
    $userSetting = Setting::factory()->for($user, 'settingable')->create(['key' => 'media.video_codec', 'value' => 'av1']);
    Setting::put('media.video_codec', 'hevc');

    expect(Setting::query()->count())->toBe(4)
        ->and($librarySetting->settingable?->is($library))->toBeTrue()
        ->and($userSetting->settingable?->is($user))->toBeTrue()
        ->and($library->settings()->sole()->id)->toBe($librarySetting->id)
        ->and(Setting::query()->global()->sole()->settingable)->toBeNull();
});

it('never reads or updates an owned setting through global helpers', function (): void {
    $library = Library::factory()->create();
    $owned = Setting::factory()->for($library, 'settingable')->create(['key' => 'hardware_acceleration', 'value' => 'qsv']);

    expect(Setting::valueOf('hardware_acceleration', 'auto'))->toBe('auto');
    Setting::put('hardware_acceleration', 'software');
    Setting::put('hardware_acceleration', 'nvenc');

    expect(Setting::valueOf('hardware_acceleration'))->toBe('nvenc')
        ->and($owned->refresh()->value)->toBe('qsv')
        ->and(Setting::query()->global()->count())->toBe(1);
});

it('enforces uniqueness for global settings with nullable owners', function (): void {
    Setting::factory()->create(['key' => 'media.video_codec']);
    Setting::factory()->create(['key' => 'media.video_codec']);
})->throws(UniqueConstraintViolationException::class);

it('enforces uniqueness for a setting on the same owner', function (): void {
    $library = Library::factory()->create();
    Setting::factory()->for($library, 'settingable')->create(['key' => 'media.video_codec']);
    Setting::factory()->for($library, 'settingable')->create(['key' => 'media.video_codec']);
})->throws(UniqueConstraintViolationException::class);

it('removes settings only for the deleted library', function (): void {
    $library = Library::factory()->create();
    $other = Library::factory()->create();
    $removed = Setting::factory()->for($library, 'settingable')->create(['key' => 'media.video_codec']);
    $retained = Setting::factory()->for($other, 'settingable')->create(['key' => 'media.video_codec']);
    Setting::put('media.video_codec', 'hevc');

    $library->delete();

    expect(Setting::query()->find($removed->id))->toBeNull()
        ->and($retained->fresh())->not->toBeNull()
        ->and(Setting::valueOf('media.video_codec'))->toBe('hevc');
});
