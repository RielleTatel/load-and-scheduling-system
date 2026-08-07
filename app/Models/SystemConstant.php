<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemConstant extends Model
{
    protected $fillable = ['key', 'value', 'description'];

    /** Per-request cache so repeated lookups don't re-query. */
    protected static array $cache = [];

    public static function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, static::$cache)) {
            return static::$cache[$key];
        }

        $value = static::query()->where('key', $key)->value('value');

        return static::$cache[$key] = $value ?? $default;
    }

    public static function set(string $key, string $value, ?string $description = null): void
    {
        $attributes = ['value' => $value];
        if ($description !== null) {
            $attributes['description'] = $description;
        }

        static::updateOrCreate(['key' => $key], $attributes);
        static::$cache[$key] = $value;
    }
}
