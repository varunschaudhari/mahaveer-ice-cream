<?php

declare(strict_types=1);

namespace Tests;

use App\Lang;
use App\Services\BillFormatter;
use App\Services\BulkService;
use App\Services\OrderService;
use App\Services\ReportService;
use App\ValidationException;
use DateTimeImmutable;

/** Bulk / party orders (SPEC §8; BUILD_PLAN M5). */
final class BulkOrderTest extends DatabaseTestCase
{
    private BulkService $bulk;
    private OrderService $orders;
    private DateTimeImmutable $now;
    private int $ownerId;
    private int $staffId;
    private int $mawaKg;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bulk = new BulkService($this->db());
        $this->orders = new OrderService($this->db());
        $this->now = new DateTimeImmutable('2026-11-26 12:00');
        $this->ownerId = $this->makeOwner();
        $this->db()->exec("INSERT INTO staff (name, role) VALUES ('Ramesh', 'staff')");
        $this->staffId = (int) $this->db()->lastInsertId();
        // The owner adds a per-kg variant to Mawa in admin (developer decision for M5).
        $mawa = (int) $this->db()->query("SELECT id FROM items WHERE name_en = 'Mawa'")->fetchColumn();
        $this->db()->exec("INSERT INTO item_variants (item_id, label_mr, label_en, price, sort_order) VALUES ({$mawa}, '1 किलो', '1 kg', 400, 3)");
        $this->mawaKg = (int) $this->db()->lastInsertId();
    }

    protected function tearDown(): void
    {
        Lang::set(Lang::DEFAULT);
        parent::tearDown();
    }

    private function variant(string $itemEn, string $labelEn): int
    {
        $stmt = $this->db()->prepare(
            'SELECT v.id FROM item_variants v JOIN items i ON i.id = v.item_id WHERE i.name_en = ? AND v.label_en = ?'
        );
        $stmt->execute([$itemEn, $labelEn]);
        return (int) $stmt->fetchColumn();
    }

    /** BUILD_PLAN M5: 3 kg Mawa + 2× Shrikhand 500 g for the day after tomorrow. */
    private function request(array $changes = [], ?DateTimeImmutable $at = null): array
    {
        return $this->bulk->request($changes + [
            'order_key' => bin2hex(random_bytes(16)),
            'name' => 'Sunita',
            'mobile' => '9822000000',
            'note' => 'लग्न, 50 लोक',
            'event_date' => '2026-11-28',
            'pickup_time' => '18:00',
            'lines' => [
                ['variant_id' => $this->mawaKg, 'qty' => '3'],
                ['variant_id' => $this->variant('Kesari Dryfruit Shrikhand', '500 g'), 'qty' => 2],
            ],
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

    public function testTheBuildPlanExampleFromRequestToPickup(): void
    {
        $order = $this->request();
        $id = (int) $order['id'];
        self::assertSame('bulk', $order['order_type']);
        self::assertSame('pending_approval', $order['status']);
        self::assertSame('2026-11-28', $order['event_date']);
        self::assertSame('2026-11-28 18:00:00', $order['pickup_at']);
        self::assertSame('1600.00', $order['total'], '3 × ₹400 + 2 × ₹200');
        self::assertSame('0.00', $order['parcel_charge'], 'no parcel charge on bulk orders');
        self::assertNull($order['bill_no']);

        $this->bulk->approve($id, 50000, $this->ownerId, $this->now);
        $order = $this->orders->find($id);
        self::assertSame('preparing', $order['status']);
        self::assertSame('500.00', $order['advance_amount']);
        self::assertSame(50000, BulkService::advanceDue($order));

        $order = $this->bulk->recordPayment($id, 'cash', 50000, $this->staffId, $this->now->modify('+2 hours'));
        self::assertSame('500.00', $order['amount_paid']);
        self::assertSame(110000, BulkService::balance($order), 'balance ₹1,100 at pickup');
        self::assertSame(0, BulkService::advanceDue($order));

        $eventDay = new DateTimeImmutable('2026-11-28 18:10');
        $this->bulk->markReady($id, $this->staffId, $eventDay);
        $order = $this->bulk->complete($id, 'upi', 0, $this->staffId, $eventDay);
        self::assertSame('completed', $order['status']);
        self::assertSame(1, (int) $order['bill_no'], 'bill number when the balance is paid');
        self::assertSame('1600.00', $order['amount_paid']);
        self::assertSame([['cash', '500.00'], ['upi_counter', '1100.00']], array_map(static fn ($p) => [$p['mode'], $p['amount']], $order['payments']));

        $reports = new ReportService($this->db());
        $requestDay = $reports->daySummary($this->now);
        self::assertSame(50000, $requestDay['by_mode']['cash'], 'the advance counts on the day it was paid');
        self::assertSame(0, $requestDay['bill_count']);
        $pickupDay = $reports->daySummary($eventDay);
        self::assertSame(110000, $pickupDay['by_mode']['upi_counter']);
        self::assertSame(1, $pickupDay['bill_count']);
    }

    public function testEventDateMustBeAtLeastOneDayAhead(): void
    {
        self::assertSame(['bulk.error.date_too_soon', ['days' => 1]], $this->errorOf(fn () => $this->request(['event_date' => '2026-11-26'])));
        self::assertSame('pending_approval', $this->request(['event_date' => '2026-11-27'])['status'], 'tomorrow is fine');
        self::assertSame('bulk.error.date', $this->errorOf(fn () => $this->request(['event_date' => '2026-02-30'])));
        self::assertSame(['bulk.error.date_too_far', ['days' => 90]], $this->errorOf(fn () => $this->request(['event_date' => '2027-06-01'])));
    }

    public function testPickupTimeWithinShopHours(): void
    {
        self::assertSame('bulk.error.time', $this->errorOf(fn () => $this->request(['pickup_time' => '09:30']))[0]);
        self::assertSame('bulk.error.time', $this->errorOf(fn () => $this->request(['pickup_time' => '22:30']))[0]);
        self::assertSame('2026-11-28 10:00:00', $this->request(['pickup_time' => '10:00'])['pickup_at']);
    }

    public function testDecimalQuantities(): void
    {
        $order = $this->request(['lines' => [['variant_id' => $this->mawaKg, 'qty' => '2.5'], ['variant_id' => $this->mawaKg, 'qty' => '0.75']]]);
        self::assertSame('3.25', $order['items'][0]['qty'], 'same item merged');
        self::assertSame('1300.00', $order['total']);
        self::assertSame('cart.error.bulk_qty', $this->errorOf(fn () => $this->request(['lines' => [['variant_id' => $this->mawaKg, 'qty' => '1000']]])));
        self::assertSame('cart.error.bulk_qty', $this->errorOf(fn () => $this->request(['lines' => [['variant_id' => $this->mawaKg, 'qty' => '1.234']]])));
    }

    public function testTodaysSoldOutDoesNotMatterButZeroPriceDoes(): void
    {
        $this->db()->exec("UPDATE items SET is_available = 0 WHERE name_en = 'Mawa'");
        self::assertSame('pending_approval', $this->request()['status']);
        $this->db()->exec("UPDATE items SET is_active = 1 WHERE name_en = 'Kulfi'");
        self::assertSame('cart.error.unavailable', $this->errorOf(fn () => $this->request(['lines' => [['variant_id' => $this->variant('Kulfi', 'Piece'), 'qty' => 10]]]))[0]);
    }

    public function testApproveRules(): void
    {
        $id = (int) $this->request()['id'];
        self::assertSame('bulk.error.advance', $this->errorOf(fn () => $this->bulk->approve($id, 200000, $this->ownerId)));
        self::assertSame('order.error.wrong_status', $this->errorOf(fn () => $this->bulk->recordPayment($id, 'cash', 1000, $this->staffId)), 'no payments before approval');
        $this->bulk->approve($id, 0, $this->ownerId, $this->now);
        self::assertSame('order.error.wrong_status', $this->errorOf(fn () => $this->bulk->approve($id, 0, $this->ownerId)));
        self::assertSame(1, (int) $this->db()->query("SELECT COUNT(*) FROM audit_log WHERE action = 'bulk_approve'")->fetchColumn());
    }

    public function testPaymentsCannotExceedTheBalance(): void
    {
        $id = (int) $this->request()['id'];
        $this->bulk->approve($id, 50000, $this->ownerId, $this->now);
        self::assertSame('bulk.error.payment', $this->errorOf(fn () => $this->bulk->recordPayment($id, 'cash', 160001, $this->staffId)));
        self::assertSame('bulk.error.payment', $this->errorOf(fn () => $this->bulk->recordPayment($id, 'cash', 0, $this->staffId)));
        self::assertSame('payment.error.method', $this->errorOf(fn () => $this->bulk->recordPayment($id, 'card', 100, $this->staffId)));
    }

    public function testFullyPrepaidOrderIsHandedOverWithoutAnotherPayment(): void
    {
        $id = (int) $this->request()['id'];
        $this->bulk->approve($id, 160000, $this->ownerId, $this->now);
        $this->bulk->recordPayment($id, 'upi', 160000, $this->staffId, $this->now);
        $order = $this->bulk->complete($id, 'cash', 0, $this->staffId, $this->now->modify('+2 days'));
        self::assertSame('completed', $order['status']);
        self::assertCount(1, $order['payments']);
    }

    public function testOwnerRejectsWithAReasonTheCustomerSees(): void
    {
        $id = (int) $this->request()['id'];
        $this->bulk->cancel($id, 'त्या दिवशी दुकान बंद', $this->ownerId, $this->now);

        $order = $this->orders->find($id);
        self::assertSame('cancelled', $order['status']);
        self::assertStringContainsString('त्या दिवशी दुकान बंद', customer_status($order)['help']);
        self::assertSame(1, (int) $this->db()->query("SELECT COUNT(*) FROM audit_log WHERE action = 'bulk_reject'")->fetchColumn());
    }

    public function testCancellingAnApprovedOrderRefundsTheAdvance(): void
    {
        $id = (int) $this->request()['id'];
        $this->bulk->approve($id, 50000, $this->ownerId, $this->now);
        $this->bulk->recordPayment($id, 'cash', 50000, $this->staffId, $this->now);
        $this->bulk->cancel($id, 'ग्राहकाने रद्द केले', $this->ownerId, $this->now);

        self::assertSame(['refunded'], array_column($this->orders->find($id)['payments'], 'status'));
        self::assertSame(0, (new ReportService($this->db()))->daySummary($this->now)['by_mode']['cash']);
    }

    public function testCustomerCanCancelOnlyWhilePending(): void
    {
        $pending = $this->request();
        $this->orders->cancelByCustomer((int) $pending['id'], $pending['order_key'], $this->now);
        self::assertSame('cancelled', $this->orders->find((int) $pending['id'])['status']);

        $approved = $this->request();
        $this->bulk->approve((int) $approved['id'], 0, $this->ownerId, $this->now);
        self::assertSame('order.error.cannot_cancel', $this->errorOf(fn () => $this->orders->cancelByCustomer((int) $approved['id'], $approved['order_key'], $this->now)));
    }

    public function testOwnerEditsLinesAndPrices(): void
    {
        $order = $this->request();
        $id = (int) $order['id'];
        $shrikhand = $this->variant('Kesari Dryfruit Shrikhand', '500 g');

        $this->bulk->updateLines($id, [
            ['variant_id' => $this->mawaKg, 'qty' => '4', 'price' => '380'],
            ['variant_id' => $shrikhand, 'qty' => '2', 'price' => ''],            // blank → menu price ₹200
            ['variant_id' => $this->variant('Vanilla', 'Full'), 'qty' => '10', 'price' => '25'],
        ], $this->ownerId);
        $order = $this->orders->find($id);
        self::assertSame('2170.00', $order['total'], '4×380 + 2×200 + 10×25');
        self::assertCount(3, $order['items']);
        self::assertSame(1, (int) $this->db()->query("SELECT COUNT(*) FROM audit_log WHERE action = 'bulk_update'")->fetchColumn());

        $this->bulk->approve($id, 100000, $this->ownerId, $this->now);
        $this->bulk->recordPayment($id, 'cash', 100000, $this->staffId, $this->now);
        self::assertSame('bulk.error.below_paid', $this->errorOf(fn () => $this->bulk->updateLines($id, [['variant_id' => $this->mawaKg, 'qty' => '1', 'price' => '400']], $this->ownerId)));
        self::assertSame('cart.error.unknown', $this->errorOf(fn () => $this->bulk->updateLines($id, [['variant_id' => 999999, 'qty' => '1', 'price' => '1']], $this->ownerId)));
    }

    public function testStatusPageAndBill(): void
    {
        $id = (int) $this->request()['id'];
        Lang::set('en');
        self::assertSame('Pending approval', customer_status($this->orders->find($id))['label']);
        self::assertTrue(customer_status($this->orders->find($id))['can_cancel']);

        $this->bulk->approve($id, 50000, $this->ownerId, $this->now);
        $view = customer_status($this->orders->find($id));
        self::assertSame('Approved', $view['label']);
        self::assertStringContainsString('₹500', $view['help']);
        self::assertFalse($view['can_cancel']);

        $this->bulk->recordPayment($id, 'cash', 50000, $this->staffId, $this->now);
        $order = $this->bulk->complete($id, 'cash', 0, $this->staffId, $this->now->modify('+2 days'));
        Lang::set('mr');
        $bill = BillFormatter::fromOrder($order);
        self::assertStringContainsString('मोठी ऑर्डर · Sunita', $bill['type']);
        self::assertSame(['कार्यक्रम', 'आगाऊ भरले', 'आत्ता भरले'], array_column($bill['extras'], 'label'));
        self::assertSame(['28/11/2026', '₹500', '₹1,100'], array_column($bill['extras'], 'value'));
    }

    public function testOpenListShowsPendingFirst(): void
    {
        $approved = (int) $this->request(['event_date' => '2026-11-27'])['id'];
        $this->bulk->approve($approved, 0, $this->ownerId, $this->now);
        $pending = (int) $this->request(['event_date' => '2026-12-05'])['id'];

        self::assertSame([$pending, $approved], array_map('intval', array_column($this->bulk->open(), 'id')));
        self::assertSame([], $this->bulk->recent());
    }
}
