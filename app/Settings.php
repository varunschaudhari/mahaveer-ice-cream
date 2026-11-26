<?php

declare(strict_types=1);

namespace App;

/** Key/value settings from the `settings` table, loaded once per request. */
final class Settings
{
    /** @var array<string, string>|null */
    private static ?array $cache = null;

    /** @return array<string, string> */
    public static function all(): array
    {
        if (self::$cache === null) {
            $rows = Db::pdo()->query('SELECT setting_key, setting_value FROM settings')->fetchAll();
            self::$cache = array_column($rows, 'setting_value', 'setting_key');
        }
        return self::$cache;
    }

    public static function get(string $key, string $default = ''): string
    {
        return self::all()[$key] ?? $default;
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key);
        return preg_match('/^-?\d+$/', $value) ? (int) $value : $default;
    }

    /** A rupee amount setting (e.g. parcel_charge '5') as integer paise. */
    public static function paise(string $key): int
    {
        return paise_from_decimal(self::get($key, '0'));
    }

    public static function clearCache(): void
    {
        self::$cache = null;
    }
}
