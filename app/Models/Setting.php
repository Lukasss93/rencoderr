<?php

namespace App\Models;

use Database\Factories\SettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['key', 'value'])]
class Setting extends Model
{
    /** @use HasFactory<SettingFactory> */
    use HasFactory;

    public static function valueOf(string $key, mixed $default = null): mixed
    {
        $setting = self::query()->global()->where('key', $key)->first();

        return $setting === null ? $default : $setting->value;
    }

    public static function put(string $key, mixed $value): void
    {
        self::query()->global()->updateOrCreate(['key' => $key], ['value' => $value]);
    }

    /** @return MorphTo<Model, $this> */
    public function settingable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @param Builder<Setting> $query */
    #[Scope]
    protected function global(Builder $query): void
    {
        $query->whereNull('settingable_type')->whereNull('settingable_id');
    }

    protected function casts(): array
    {
        return ['value' => 'json'];
    }
}
