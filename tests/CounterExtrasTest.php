<?php

declare(strict_types=1);

namespace Tests;

use App\Auth;
use App\Lang;
use App\Services\MenuService;
use App\Services\OrderService;
use DateTimeImmutable;

/** Owner PIN approvals, daily stock reset and the printed bill. */
final class CounterExtrasTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SERVER['REMOTE_ADDR'] = '192.0.2.' . random_int(1, 250);
    }

    protected function tearDown(): void
    {
        Lang::set(Lang::DEFAULT);
        parent::tearDown();
    }

    private function person(string $name, string $role, string $pin): int
    {
        $this->db()->prepare('INSERT INTO staff (name, role, pin_hash) VALUES (?, ?, ?)')
            ->execute([$name, $role, password_hash($pin, PASSWORD_DEFAULT)]);
        return (int) $this->db()->lastInsertId();
    }

    // ---------- owner PIN ----------

    public function testOwnerPinApprovesAndStaffPinDoesNot(): void
    {
        $owner = $this->person('Owner', 'owner', '9999');
        $this->person('Ramesh', 'staff', '1111');

        $ok = Auth::verifyOwnerPin('9999');
        self::assertTrue($ok['ok']);
        self::assertSame(['id' => $owner, 'name' => 'Owner'], $ok['owner']);
        self::assertSame('auth.wrong_owner_pin', Auth::verifyOwnerPin('1111')['error']);
    }

    public function testFiveWrongOwnerPinsBlockApprovals(): void
    {
        $this->person('Owner', 'owner', '9999');
        for ($i = 0; $i < Auth::IP_MAX_OWNER_PIN_FAILURES; $i++) {
            Auth::verifyOwnerPin('0000');
        }
        self::assertSame('auth.too_many', Auth::verifyOwnerPin('9999')['error']);
    }

    // ---------- daily stock ----------

    public function testSoldOutMarksResetOnceADay(): void
    {
        $menu = new MenuService($this->db());
        $owner = $this->makeOwner();
        $vanilla = (int) $this->db()->query("SELECT id FROM items WHERE name_en = 'Vanilla'")->fetchColumn();
        $available = fn (): int => (int) $this->db()->query("SELECT is_available FROM items WHERE id = {$vanilla}")->fetchColumn();
        $today = new DateTimeImmutable('2026-11-26 10:00');

        self::assertTrue($menu->resetStockIfNewDay($today), 'first call of the day resets');
        $menu->setAvailability($vanilla, false, $owner);
        self::assertSame(0, $available());

        self::assertFalse($menu->resetStockIfNewDay($today->setTime(21, 0)), 'later the same day: no reset');
        self::assertSame(0, $available());

        self::assertTrue($menu->resetStockIfNewDay($today->modify('+1 day')));
        self::assertSame(1, $available());

        $actions = $this->db()->query('SELECT action FROM audit_log ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['stock_toggle', 'stock_reset'], $actions);
    }

    // ---------- printed bill ----------

    private function render(array $order, bool $copy = false): string
    {
        Lang::set('mr');
        ob_start();
        view('print_bill', ['order' => $order, 'copy' => $copy, 'shop' => \App\Settings::all()]);
        return (string) ob_get_clean();
    }

    private function savedOrder(): array
    {
        $staff = $this->person('Ramesh', 'staff', '1111');
        $badam = (int) $this->db()->query(
            "SELECT v.id FROM item_variants v JOIN items i ON i.id = v.item_id WHERE i.name_en = 'Badam Shake' AND v.label_en = 'Full'"
        )->fetchColumn();
        return (new OrderService($this->db()))->createCounterOrder([
            'order_key' => bin2hex(random_bytes(16)),
            'lines' => [['variant_id' => $badam, 'qty' => 2]],
            'service_type' => 'parcel',
            'payment_method' => 'cash',
        ], $staff);
    }

    public function testBillPrintsMarathiNamesTokenAndTotals(): void
    {
        $html = $this->render($this->savedOrder());

        self::assertStringContainsString('बदाम शेक (फुल)', $html);
        self::assertStringNotContainsString('Badam Shake', $html, 'item names in Marathi only');
        self::assertStringContainsString('महावीर आईस्क्रीम', $html);
        self::assertStringContainsString('Mahavir Ice Cream', $html);
        self::assertMatchesRegularExpression('/<strong>1<\/strong>/', $html, 'token');
        self::assertStringContainsString('₹165', $html);
        self::assertStringContainsString('पार्सल चार्ज', $html);
        self::assertStringContainsString('धन्यवाद! पुन्हा भेट द्या', $html);
        self::assertStringNotContainsString('राउंड ऑफ', $html, 'no round-off line when it is zero');
        self::assertStringNotContainsString('DUPLICATE', $html);
    }

    public function testReprintIsMarkedDuplicate(): void
    {
        self::assertStringContainsString('DUPLICATE / प्रत', $this->render($this->savedOrder(), true));
    }

    public function testRoundOffLineAppearsWhenNeeded(): void
    {
        $order = $this->savedOrder();
        $order['subtotal'] = '83.33';
        $order['parcel_charge'] = '0.00';
        $order['total'] = '83.00';
        self::assertStringContainsString('राउंड ऑफ', $this->render($order));
        self::assertStringContainsString('-₹0.33', $this->render($order));
    }
}
