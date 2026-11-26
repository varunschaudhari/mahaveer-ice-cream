<?php

declare(strict_types=1);

namespace Tests;

use App\RecentOrders;
use App\Services\CustomerService;
use App\Services\OrderService;
use App\Services\QrService;
use App\Services\ReportService;
use App\Services\SlotService;
use App\ValidationException;
use DateTimeImmutable;

/** Takeaway orders end to end in the service layer (SPEC §3, §6; BUILD_PLAN M3). */
final class TakeawayOrderTest extends DatabaseTestCase
{
    private OrderService $orders;
    private DateTimeImmutable $now;
    private int $staffId;
    private int $ownerId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->orders = new OrderService($this->db());
        $this->now = new DateTimeImmutable('2026-11-26 18:00');
        $this->ownerId = $this->makeOwner();
        $this->db()->exec("INSERT INTO staff (name, role) VALUES ('Ramesh', 'staff')");
        $this->staffId = (int) $this->db()->lastInsertId();
    }

    private function variant(string $itemEn, string $labelEn): int
    {
        $stmt = $this->db()->prepare(
            'SELECT v.id FROM item_variants v JOIN items i ON i.id = v.item_id WHERE i.name_en = ? AND v.label_en = ?'
        );
        $stmt->execute([$itemEn, $labelEn]);
        return (int) $stmt->fetchColumn();
    }

    private function place(array $changes = [], ?DateTimeImmutable $at = null): array
    {
        return $this->orders->placeTakeaway($changes + [
            'order_key' => bin2hex(random_bytes(16)),
            'name' => 'Asha',
            'mobile' => '98765 43210',
            'slot' => '19:30',
            'note' => 'कमी साखर',
            'lines' => [['variant_id' => $this->variant('Badam Shake', 'Full'), 'qty' => 2]],
        ], $at ?? $this->now);
    }

    private function errorOf(callable $fn): string|array
    {
        try {
            $fn();
        } catch (ValidationException $e) {
            return $e->first();
        }
        self::fail('Expected a ValidationException');
    }

    private function statusOf(int $id): string
    {
        return (string) $this->db()->query("SELECT status FROM orders WHERE id = {$id}")->fetchColumn();
    }

    // ---------- placing ----------

    public function testPlacedOrderIsReceivedWithTokenButNoBillNumber(): void
    {
        $order = $this->place();

        self::assertSame('takeaway', $order['order_type']);
        self::assertSame('received', $order['status']);
        self::assertSame('parcel', $order['service_type']);
        self::assertSame(1, (int) $order['token_no']);
        self::assertNull($order['bill_no']);
        self::assertSame('2026-11-26 19:30:00', $order['pickup_at']);
        self::assertSame('160.00', $order['subtotal']);
        self::assertSame('5.00', $order['parcel_charge'], '₹5 parcel charge once per takeaway order');
        self::assertSame('165.00', $order['total']);
        self::assertSame('0.00', $order['amount_paid']);
        self::assertSame('कमी साखर', $order['note']);
        self::assertSame('Asha', $order['customer_name']);
        self::assertSame('9876543210', $order['customer_mobile']);
        self::assertSame([], $order['payments']);
    }

    public function testCustomerIsCreatedOnceAndCounted(): void
    {
        $this->place();
        $this->place(['name' => 'Asha Patil']);
        $customer = $this->db()->query("SELECT * FROM customers WHERE mobile = '9876543210'")->fetch();
        self::assertSame('Asha Patil', $customer['name']);
        self::assertSame(2, (int) $customer['order_count']);
    }

    public function testTokensAreSharedWithCounterBills(): void
    {
        $this->place();
        $counter = $this->orders->createCounterOrder([
            'order_key' => bin2hex(random_bytes(16)),
            'lines' => [['variant_id' => $this->variant('Vanilla', 'Half'), 'qty' => 1]],
            'service_type' => 'dine_in',
            'payment_method' => 'cash',
        ], $this->staffId, $this->now);
        self::assertSame(2, (int) $counter['token_no']);
    }

    public function testEleventhOrderInASlotIsRefusedAndTheSlotShowsFull(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->place(['mobile' => '90000000' . str_pad((string) $i, 2, '0', STR_PAD_LEFT)]);
        }
        self::assertSame('checkout.error.slot_full', $this->errorOf(fn () => $this->place(['mobile' => '9111111111'])));

        $slot = array_values(array_filter((new SlotService($this->db()))->today($this->now)['slots'], static fn ($s) => $s['start'] === '19:30'))[0];
        self::assertTrue($slot['full']);
        self::assertSame(10, $slot['count']);
        self::assertGreaterThan(0, (int) $this->place(['slot' => '19:45', 'mobile' => '9111111111'])['id'], 'the next slot is still open');
    }

    public function testCancelledOrdersFreeTheirSlot(): void
    {
        $ids = [];
        for ($i = 0; $i < 10; $i++) {
            $ids[] = (int) $this->place(['mobile' => '90000000' . str_pad((string) $i, 2, '0', STR_PAD_LEFT)])['id'];
        }
        $this->orders->cancel($ids[0], 'test', null, $this->staffId, $this->now);
        self::assertGreaterThan(0, (int) $this->place(['mobile' => '9111111111'])['id']);
    }

    public function testClosedAtTenFivePm(): void
    {
        self::assertSame('checkout.error.closed', $this->errorOf(fn () => $this->place([], new DateTimeImmutable('2026-11-26 22:05'))));
    }

    public function testSlotMustBeOneOfTodaysChoosableSlots(): void
    {
        self::assertSame('checkout.error.slot', $this->errorOf(fn () => $this->place(['slot' => '18:05'])), 'too soon');
        self::assertSame('checkout.error.slot', $this->errorOf(fn () => $this->place(['slot' => '19:40'])), 'not a slot start');
        self::assertSame('checkout.error.slot', $this->errorOf(fn () => $this->place(['slot' => '22:00'])), 'after the last slot');
    }

    public function testInputIsValidated(): void
    {
        try {
            $this->place(['name' => ' ', 'mobile' => '12345', 'note' => str_repeat('x', 201)]);
            self::fail('accepted bad input');
        } catch (ValidationException $e) {
            self::assertSame(['name' => 'checkout.error.name', 'mobile' => 'checkout.error.mobile', 'note' => ['checkout.error.note_max', ['max' => 200]]], $e->errors);
        }
        self::assertSame('9876543210', $this->place(['mobile' => '+91-98765-43210'])['customer_mobile']);
    }

    public function testCounterOnlyItemsCannotBeOrderedOnline(): void
    {
        $this->db()->exec("UPDATE items SET online_enabled = 0 WHERE name_en = 'Badam Shake'");
        self::assertSame('cart.error.unavailable', $this->errorOf(fn () => $this->place())[0]);
    }

    public function testSameKeyReturnsTheSameOrder(): void
    {
        $key = bin2hex(random_bytes(16));
        $first = $this->place(['order_key' => $key]);
        $again = $this->place(['order_key' => $key, 'slot' => '20:00']);
        self::assertTrue($again['duplicate']);
        self::assertSame($first['id'], $again['id']);
    }

    // ---------- status changes ----------

    public function testHappyPathAcceptReadyPickup(): void
    {
        $id = (int) $this->place()['id'];
        $this->orders->accept($id, $this->staffId, $this->now);
        self::assertSame('preparing', $this->statusOf($id));
        $this->orders->markReady($id, $this->staffId, $this->now);
        self::assertSame('ready', $this->statusOf($id));

        $order = $this->orders->pickup($id, 'cash', 0, $this->staffId, $this->now->modify('+90 minutes'));
        self::assertSame('completed', $order['status']);
        self::assertSame(1, (int) $order['bill_no'], 'bill number assigned at pickup');
        self::assertSame('165.00', $order['amount_paid']);
        self::assertSame([['mode' => 'cash', 'amount' => '165.00', 'status' => 'success', 'created_at' => '2026-11-26 19:30:00']], $order['payments']);
        self::assertSame('Ramesh', $order['staff_name']);
    }

    public function testPickupMaySkipReady(): void
    {
        $id = (int) $this->place()['id'];
        $this->orders->accept($id, $this->staffId, $this->now);
        $order = $this->orders->pickup($id, 'mixed', 10000, $this->staffId, $this->now);
        self::assertSame(['100.00', '65.00'], array_column($order['payments'], 'amount'));
    }

    public function testForbiddenTransitionsAreRefused(): void
    {
        $id = (int) $this->place()['id'];
        self::assertSame('order.error.wrong_status', $this->errorOf(fn () => $this->orders->markReady($id, $this->staffId)));
        self::assertSame('order.error.wrong_status', $this->errorOf(fn () => $this->orders->pickup($id, 'cash', 0, $this->staffId)));
        $this->orders->accept($id, $this->staffId);
        self::assertSame('order.error.wrong_status', $this->errorOf(fn () => $this->orders->accept($id, $this->staffId)), 'accepted twice');

        $counterBill = $this->orders->createCounterOrder([
            'order_key' => bin2hex(random_bytes(16)),
            'lines' => [['variant_id' => $this->variant('Vanilla', 'Half'), 'qty' => 1]],
            'service_type' => 'dine_in',
            'payment_method' => 'cash',
        ], $this->staffId, $this->now);
        self::assertSame('order.error.wrong_status', $this->errorOf(fn () => $this->orders->accept((int) $counterBill['id'], $this->staffId)));
    }

    // ---------- cancelling ----------

    public function testStaffCanCancelANewOrderWithoutTheOwner(): void
    {
        $id = (int) $this->place()['id'];
        $this->orders->cancel($id, 'ग्राहकाने फोन करून रद्द केले', null, $this->staffId, $this->now);
        self::assertSame('cancelled', $this->statusOf($id));
    }

    public function testAcceptedOrderNeedsTheOwnerToCancel(): void
    {
        $id = (int) $this->place()['id'];
        $this->orders->accept($id, $this->staffId, $this->now);
        self::assertSame('cancel.error.owner_needed', $this->errorOf(fn () => $this->orders->cancel($id, 'x', null, $this->staffId, $this->now)));
        $this->orders->cancel($id, 'x', $this->ownerId, $this->staffId, $this->now);
        self::assertSame('cancelled', $this->statusOf($id));
    }

    public function testCustomerCanCancelOnlyBeforeAcceptanceAndOnlyWithTheKey(): void
    {
        $order = $this->place();
        $id = (int) $order['id'];
        self::assertSame('status.not_found', $this->errorOf(fn () => $this->orders->cancelByCustomer($id, str_repeat('0', 32), $this->now)));

        $this->orders->cancelByCustomer($id, $order['order_key'], $this->now);
        self::assertSame('cancelled', $this->statusOf($id));
        self::assertSame(OrderService::CUSTOMER_CANCEL_REASON, $this->orders->find($id)['cancel_reason']);

        $accepted = $this->place();
        $this->orders->accept((int) $accepted['id'], $this->staffId, $this->now);
        self::assertSame('order.error.cannot_cancel', $this->errorOf(fn () => $this->orders->cancelByCustomer((int) $accepted['id'], $accepted['order_key'], $this->now)));
    }

    public function testStatusPageNeedsTheSecretKey(): void
    {
        $order = $this->place();
        self::assertNotNull($this->orders->findForCustomer((int) $order['id'], $order['order_key']));
        self::assertNull($this->orders->findForCustomer((int) $order['id'], ''));
        self::assertNull($this->orders->findForCustomer((int) $order['id'], strrev($order['order_key'])));
    }

    // ---------- no-shows ----------

    public function testUncollectedOrderBecomesNoShowAfterSlotEndPlusThirtyMinutes(): void
    {
        $id = (int) $this->place()['id']; // slot 19:30–19:45 → no-show from 20:15
        self::assertSame(0, $this->orders->markNoShows(new DateTimeImmutable('2026-11-26 20:14')));
        self::assertSame(1, $this->orders->markNoShows(new DateTimeImmutable('2026-11-26 20:15')));
        self::assertSame('no_show', $this->statusOf($id));

        $customer = $this->db()->query("SELECT no_show_count, is_blocked FROM customers WHERE mobile = '9876543210'")->fetch();
        self::assertSame([1, 0], [(int) $customer['no_show_count'], (int) $customer['is_blocked']]);
    }

    public function testCollectedAndCancelledOrdersAreNotNoShows(): void
    {
        $collected = (int) $this->place()['id'];
        $this->orders->accept($collected, $this->staffId, $this->now);
        $this->orders->pickup($collected, 'upi', 0, $this->staffId, $this->now);
        $cancelled = (int) $this->place()['id'];
        $this->orders->cancel($cancelled, 'x', null, $this->staffId, $this->now);

        self::assertSame(0, $this->orders->markNoShows(new DateTimeImmutable('2026-11-26 23:00')));
    }

    public function testTwoNoShowsBlockTheNumber(): void
    {
        $this->place();
        $this->place(['slot' => '19:45']);
        self::assertSame(2, $this->orders->markNoShows(new DateTimeImmutable('2026-11-26 21:00')));

        self::assertTrue((new CustomerService($this->db()))->isBlocked('9876543210'));
        self::assertSame('checkout.error.blocked', $this->errorOf(fn () => $this->place([], $this->now->modify('+1 day'))));
        self::assertSame(2, (int) $this->db()->query("SELECT COUNT(*) FROM audit_log WHERE action = 'no_show'")->fetchColumn());
    }

    public function testUnblockLetsTheNumberOrderAgain(): void
    {
        $this->place();
        $this->place(['slot' => '19:45']);
        $this->orders->markNoShows(new DateTimeImmutable('2026-11-26 21:00'));
        $customers = new CustomerService($this->db());
        $customerId = (int) $customers->blocked()[0]['id'];

        $customers->unblock($customerId, $this->ownerId);
        self::assertFalse($customers->isBlocked('9876543210'));
        self::assertSame(0, (int) $this->db()->query("SELECT no_show_count FROM customers WHERE id = {$customerId}")->fetchColumn());
        self::assertSame('received', $this->place([], $this->now->modify('+1 day'))['status']);
    }

    // ---------- counter screens ----------

    public function testOnlineTabAndPollSummary(): void
    {
        $first = (int) $this->place()['id'];
        $second = (int) $this->place(['slot' => '20:00'])['id'];
        $this->orders->accept($first, $this->staffId, $this->now);

        self::assertSame(['new_count' => 1, 'latest_new_id' => $second, 'alert_count' => 0, 'latest_alert_id' => 0], $this->orders->newOnlineSummary($this->now));
        $active = $this->orders->activeOnline($this->now);
        self::assertSame([$first, $second], array_map('intval', array_column($active, 'id')));
        self::assertSame('Asha', $active[0]['customer_name']);
        self::assertCount(1, $active[0]['items']);
    }

    public function testDaySummaryCountsTakeawayOnlyOncePickedUp(): void
    {
        $picked = (int) $this->place()['id'];
        $this->orders->accept($picked, $this->staffId, $this->now);
        $this->orders->pickup($picked, 'upi', 0, $this->staffId, $this->now);
        $this->place(); // still waiting

        $summary = (new ReportService($this->db()))->daySummary($this->now);
        self::assertSame(16500, $summary['by_mode']['upi_counter']);
        self::assertSame(1, $summary['bill_count']);
        self::assertSame(2, $summary['by_type']['takeaway']);
    }

    // ---------- small pieces ----------

    public function testRecentOrdersCookieIsParsedSafely(): void
    {
        $key = str_repeat('ab', 16);
        $_COOKIE['mahavir_recent'] = "12.{$key},bad,13.zz,14." . strtoupper($key);
        self::assertSame([['id' => 12, 'key' => $key]], RecentOrders::read());
        unset($_COOKIE['mahavir_recent']);
        self::assertSame([], RecentOrders::read());
    }

    public function testQrCodeIsAnSvgDataUri(): void
    {
        self::assertStringStartsWith('data:image/svg+xml;base64,', QrService::svgDataUri('https://order.mahaveericecream.in/'));
    }

    public function testMobileNumbersAreNormalised(): void
    {
        self::assertSame('9876543210', normalize_mobile('+91 98765 43210'));
        self::assertSame('9876543210', normalize_mobile('09876543210'));
        self::assertSame('6123456789', normalize_mobile('6123456789'));
        self::assertNull(normalize_mobile('5123456789'), 'must start with 6–9');
        self::assertNull(normalize_mobile('98765'));
    }
}
