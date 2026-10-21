<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BdpSetting extends Model
{
    protected $table = 'bdp_settings';

    protected $fillable = [
        'key',
        'value',
    ];

    public static function getValue(string $key, $default = null)
    {
        $record = static::query()->where('key', $key)->first();

        return $record ? $record->value : $default;
    }

    public static function setValue(string $key, $value): void
    {
        static::query()->updateOrCreate(
            ['key' => $key],
            ['value' => is_bool($value) ? ($value ? '1' : '0') : (string) $value]
        );
    }

    public static function enabled(string $key, bool $default = false): bool
    {
        $value = static::getValue($key, $default ? '1' : '0');

        return in_array((string) $value, ['1', 'true', 'yes', 'on'], true);
    }
}
