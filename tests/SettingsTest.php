<?php

declare(strict_types=1);

namespace Tests;

use App\Settings;

final class SettingsTest extends DatabaseTestCase
{
    public function testReadsSeededValues(): void
    {
        self::assertSame('Mahaveer Ice Cream', Settings::get('shop_name_en'));
        self::assertSame('महावीर आईस्क्रीम', Settings::get('shop_name_mr'));
        self::assertSame('22:30', Settings::get('close_time'));
    }

    public function testIntAndPaise(): void
    {
        self::assertSame(10, Settings::int('slot_capacity'));
        self::assertSame(500, Settings::paise('parcel_charge'));
    }

    public function testDefaults(): void
    {
        self::assertSame('', Settings::get('no_such_key'));
        self::assertSame('x', Settings::get('no_such_key', 'x'));
        self::assertSame(7, Settings::int('close_time', 7), 'non-numeric value falls back to the default');
        self::assertSame('', Settings::get('whatsapp_number'));
    }

    public function testValuesAreCachedUntilCleared(): void
    {
        self::assertSame('5', Settings::get('parcel_charge'));
        $this->db()->exec("UPDATE settings SET setting_value = '7' WHERE setting_key = 'parcel_charge'");

        self::assertSame('5', Settings::get('parcel_charge'));
        Settings::clearCache();
        self::assertSame('7', Settings::get('parcel_charge'));
    }
}
