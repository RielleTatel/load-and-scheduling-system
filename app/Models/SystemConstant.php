<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemConstant extends Model
{
    protected $fillable = ['key', 'value', 'description'];

    /** Per-request cache so repeated lookups don't re-query. */
    protected static array $cache = [];

    /**
     * Any write (including a direct Eloquent update) invalidates the cached
     * value so the next read reflects it — keeps get() honest.
     */
    protected static function booted(): void
    {
        static::saved(fn (self $constant) => static::forget($constant->key));
        static::deleted(fn (self $constant) => static::forget($constant->key));
    }

    public static function forget(string $key): void
    {
        unset(static::$cache[$key]);
    }

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
