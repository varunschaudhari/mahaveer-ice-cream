<?php

declare(strict_types=1);

namespace Tests;

use App\Services\SettingsService;
use App\Settings;
use App\ValidationException;

final class SettingsServiceTest extends DatabaseTestCase
{
    private SettingsService $service;
    private int $ownerId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SettingsService($this->db());
        $this->ownerId = $this->makeOwner();
    }

    /** All current values (as the settings form would post them) with some changes. */
    private function form(array $changes = []): array
    {
        return array_merge(array_intersect_key(Settings::all(), SettingsService::FIELDS), $changes);
    }

    private function errorsOf(array $changes): array
    {
        try {
            $this->service->update($this->form($changes), $this->ownerId);
        } catch (ValidationException $e) {
            return $e->errors;
        }
        self::fail('Expected a ValidationException');
    }

    public function testSavingTheSeededValuesChangesNothing(): void
    {
        self::assertSame([], $this->service->update($this->form(), $this->ownerId));
        self::assertSame(0, (int) $this->db()->query('SELECT COUNT(*) FROM audit_log')->fetchColumn());
    }

    public function testChangeIsSavedAndAuditLoggedPerKey(): void
    {
        $changed = $this->service->update($this->form(['parcel_charge' => '7', 'slot_capacity' => '12']), $this->ownerId);

        self::assertSame(['parcel_charge', 'slot_capacity'], $changed);
        self::assertSame(700, Settings::paise('parcel_charge'));
        self::assertSame(12, Settings::int('slot_capacity'));

        $row = $this->db()->query("SELECT * FROM audit_log WHERE entity_id = 'parcel_charge'")->fetch();
        self::assertSame('settings_change', $row['action']);
        self::assertSame(['before' => '5', 'after' => '7'], json_decode($row['details'], true));
    }

    public function testValuesAreNormalised(): void
    {
        $this->service->update($this->form([
            'parcel_charge' => '₹5.50',
            'whatsapp_number' => '+91 98765 43210',
            'slot_mins' => '015',
        ]), $this->ownerId);
        self::assertSame('5.50', Settings::get('parcel_charge'));
        self::assertSame('9876543210', Settings::get('whatsapp_number'));
        self::assertSame('15', Settings::get('slot_mins'));
    }

    public function testOptionalFieldsMayBeEmpty(): void
    {
        $this->service->update($this->form(['whatsapp_number' => '', 'google_maps_url' => '', 'bill_footer_en' => '']), $this->ownerId);
        self::assertSame('', Settings::get('bill_footer_en'));
    }

    public function testBadValuesAreRejectedPerField(): void
    {
        $errors = $this->errorsOf([
            'open_time' => '25:00',
            'slot_capacity' => '0',
            'parcel_charge' => '-5',
            'shop_name_en' => '',
            'whatsapp_number' => '12345',
            'google_maps_url' => 'http://maps.example.com',
        ]);

        self::assertSame('settings.error.time', $errors['open_time']);
        self::assertSame(['settings.error.range', ['min' => 1, 'max' => 100]], $errors['slot_capacity']);
        self::assertSame('settings.error.money', $errors['parcel_charge']);
        self::assertSame('settings.error.required', $errors['shop_name_en']);
        self::assertSame('settings.error.mobile', $errors['whatsapp_number']);
        self::assertSame('settings.error.url', $errors['google_maps_url']);
        self::assertSame('5', Settings::get('parcel_charge'), 'nothing saved');
    }

    public function testClosingMustBeAfterOpening(): void
    {
        self::assertSame('settings.error.close_before_open', $this->errorsOf(['open_time' => '22:00', 'close_time' => '10:00'])['close_time']);
    }

    public function testLastOrderCannotEndBeforeOpening(): void
    {
        self::assertSame('settings.error.last_order', $this->errorsOf([
            'open_time' => '10:00', 'close_time' => '11:00', 'last_order_mins' => '60',
        ])['last_order_mins']);
    }
}
