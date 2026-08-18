<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SystemSettingService
{
    protected const CACHE_PREFIX = 'system_settings:';
    protected const CACHE_TTL = 3600; // 1 hour

    /**
     * Get a setting value by key.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return Cache::remember(
            self::CACHE_PREFIX . $key,
            self::CACHE_TTL,
            function () use ($key, $default) {
                $row = DB::table('system_settings')->where('key', $key)->first();

                if (! $row) {
                    return $default;
                }

                return $this->castValue($row->value, $row->type);
            }
        );
    }

    /**
     * Set a setting value.
     */
    public function set(string $key, mixed $value, ?string $type = null): void
    {
        $stored = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;

        DB::table('system_settings')->updateOrInsert(
            ['key' => $key],
            [
                'value' => $stored,
                'type' => $type ?? $this->inferType($value),
                'updated_at' => now(),
            ]
        );

        Cache::forget(self::CACHE_PREFIX . $key);
    }

    /**
     * Delete a setting entirely, so reads fall back to their default again.
     *
     * Distinct from setting it to null or to an empty string, both of which
     * leave a row that keeps overriding the fallback with nothing.
     */
    public function forget(string $key): void
    {
        DB::table('system_settings')->where('key', $key)->delete();

        Cache::forget(self::CACHE_PREFIX . $key);
    }

    /**
     * Get a boolean setting.
     */
    public function getBoolean(string $key, bool $default = false): bool
    {
        $value = $this->get($key);

        if ($value === null) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Get an integer setting.
     */
    public function getInteger(string $key, int $default = 0): int
    {
        $value = $this->get($key);

        if ($value === null) {
            return $default;
        }

        return (int) $value;
    }

    /**
     * Get all settings as key-value pairs.
     */
    public function all(): array
    {
        return DB::table('system_settings')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->key => $this->castValue($row->value, $row->type)])
            ->toArray();
    }

    protected function castValue(string $value, string $type): mixed
    {
        return match ($type) {
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'integer' => (int) $value,
            'json' => json_decode($value, true),
            default => $value,
        };
    }

    protected function inferType(mixed $value): string
    {
        if (is_bool($value)) {
            return 'boolean';
        }
        if (is_int($value)) {
            return 'integer';
        }
        if (is_array($value)) {
            return 'json';
        }

        return 'string';
    }
}
