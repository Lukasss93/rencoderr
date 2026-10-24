<?php

use App\Models\Library;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Schema;

function runMediaSettingsMigration(string $direction): void
{
    $migration = require database_path('migrations/2026_10_22_211927_create_settings_table.php');

    if (! $migration instanceof Migration || ! method_exists($migration, $direction)) {
        throw new LogicException('The media settings migration must implement '.$direction.'.');
    }

    $migration->{$direction}();
}

it('creates settings that preserve global value types', function (): void {
    runMediaSettingsMigration('down');
    runMediaSettingsMigration('up');
    Setting::factory()->create(['key' => 'media.preserve_lossless_audio', 'value' => false]);
    Setting::factory()->create(['key' => 'media.minimum_savings_percent', 'value' => 0]);
    Setting::factory()->create(['key' => 'media.audio_languages', 'value' => []]);
    Setting::factory()->create(['key' => 'hardware_acceleration', 'value' => 'software']);

    expect(Setting::query()->global()->pluck('value', 'key')->all())->toEqual([
        'media.preserve_lossless_audio' => false, 'media.minimum_savings_percent' => 0,
        'media.audio_languages' => [], 'hardware_acceleration' => 'software',
    ]);
});

it('leaves a fresh installation without stored preferences or a library json column', function (): void {
    expect(Schema::hasColumn('libraries', 'media_settings'))->toBeFalse()
        ->and(Schema::getColumnListing('settings'))->toBe([
            'id', 'settingable_id', 'settingable_type', 'key', 'value', 'created_at', 'updated_at',
        ])
        ->and(Setting::query()->count())->toBe(0);
});

it('drops the settings table on rollback while retaining libraries', function (): void {
    $library = Library::factory()->withSettings(['media.video_codec' => 'av1'])->create();
    Setting::put('media.video_codec', 'hevc');
    Setting::put('queue_max_workers', 2);

    runMediaSettingsMigration('down');

    expect(Schema::hasTable('settings'))->toBeFalse()
        ->and(Schema::hasColumn('libraries', 'media_settings'))->toBeFalse()
        ->and($library->fresh())->not->toBeNull();

    runMediaSettingsMigration('up');
    expect($library->settings()->count())->toBe(0)
        ->and(Setting::query()->count())->toBe(0);
});

it('restores global uniqueness when recreating the settings table', function (): void {
    runMediaSettingsMigration('down');
    runMediaSettingsMigration('up');
    Setting::put('media.video_codec', 'hevc');

    expect(fn () => Setting::factory()->create(['key' => 'media.video_codec', 'value' => 'av1']))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('retains other owner models when rolling back the settings table', function (): void {
    $user = User::factory()->create();
    Setting::factory()->for($user, 'settingable')->create(['key' => 'media.video_codec', 'value' => 'av1']);
    Setting::put('media.video_codec', 'hevc');

    runMediaSettingsMigration('down');

    expect(Schema::hasTable('settings'))->toBeFalse()
        ->and($user->fresh())->not->toBeNull();
});
