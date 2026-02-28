<?php
declare(strict_types=1);

namespace Kalix;

final class Registry
{
    private static array $kv = [];



    /*
     * Set value.
     *
     * Stores a value into the in-memory key-value registry.
     */

    public static function set(string $key, mixed $value): void
    {
        self::$kv[$key] = $value;
    }



    /*
     * Get value.
     *
     * Returns a stored value or the provided default.
     */

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::$kv[$key] ?? $default;
    }



    /*
     * Check key.
     *
     * Returns true when a key exists in the registry.
     */

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::$kv);
    }



    /*
     * Delete by prefix.
     *
     * Removes every key that starts with the provided prefix.
     */

    public static function deletePrefix(string $prefix): void
    {
        foreach (array_keys(self::$kv) as $k) {
            if (str_starts_with($k, $prefix)) {
                unset(self::$kv[$k]);
            }
        }
    }



    /*
     * Clear registry.
     *
     * Empties the full in-memory registry.
     */

    public static function clear(): void
    {
        self::$kv = [];
    }
}
