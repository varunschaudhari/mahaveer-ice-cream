<?php

declare(strict_types=1);

namespace Tests;

use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\ReportService;
use App\ValidationException;
use DateTimeImmutable;

/** Counter bills end to end in the service layer: save, numbering, discount, cancel, day summary. */
final class CounterOrderTest extends DatabaseTestCase
{
    private OrderService $orders;
    private int $staffId;
    private int $ownerId;
    private DateTimeImmutable $today;

    protected function setUp(): void
    {
        parent::setUp();
        $this->orders = new OrderService($this->db());
        $this->ownerId = $this->makeOwner();
        $this->db()->exec("INSERT INTO staff (name, role) VALUES ('Ramesh', 'staff')");
        $this->staffId = (int) $this->db()->lastInsertId();
        $this->today = new DateTimeImmutable('2026-11-26 19:30');
    }

    private function variant(string $itemEn, string $labelEn): int
    {
        $stmt = $this->db()->prepare(
            'SELECT v.id FROM item_variants v JOIN items i ON i.id = v.item_id WHERE i.name_en = ? AND v.label_en = ?'
        );
        $stmt->execute([$itemEn, $labelEn]);
        return (int) $stmt->fetchColumn();
    }

    /** The BUILD_PLAN M2 example: 2× Badam Shake Full + 1× Vanilla Half, Parcel, cash. */
    private function bill(array $changes = [], ?DateTimeImmutable $at = null): array
    {
        return $this->orders->createCounterOrder($changes + [
            'order_key' => bin2hex(random_bytes(16)),
            'lines' => [
                ['variant_id' => $this->variant('Badam Shake', 'Full'), 'qty' => 2],
                ['variant_id' => $this->variant('Vanilla', 'Half'), 'qty' => 1],
            ],
            'service_type' => 'parcel',
            'payment_method' => 'cash',
        ], $this->staffId, $at ?? $this->today);
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

    // ---------- saving ----------

    public function testTheBuildPlanExampleBill(): void
    {
        $order = $this->bill();

        self::assertSame('counter', $order['order_type']);
        self::assertSame('completed', $order['status']);
        self::assertSame('parcel', $order['service_type']);
        self::assertSame('175.00', $order['subtotal']);
        self::assertSame('5.00', $order['parcel_charge']);
        self::assertSame('180.00', $order['total']);
        self::assertSame('180.00', $order['amount_paid']);
        self::assertSame(1, (int) $order['bill_no']);
        self::assertSame(1, (int) $order['token_no']);
        self::assertSame('Ramesh', $order['staff_name']);
        self::assertSame(2000, PaymentService::change(paise_from_decimal($order['total']), 20000), 'cash ₹200 → change ₹20');

        self::assertSame(
            [['बदाम शेक (फुल) / Badam Shake (Full)', '80.00', '2.00', '160.00'], ['वॅनिला (हाफ) / Vanilla (Half)', '15.00', '1.00', '15.00']],
            array_map(static fn ($l) => [$l['item_name'], $l['unit_price'], $l['qty'], $l['line_total']], $order['items'])
        );
        self::assertSame([['mode' => 'cash', 'amount' => '180.00', 'status' => 'success', 'created_at' => '2026-11-26 19:30:00']], $order['payments']);
        self::assertFalse($order['duplicate']);
    }

    public function testDineInHasNoParcelCharge(): void
    {
        self::assertSame('175.00', $this->bill(['service_type' => 'dine_in'])['total']);
    }

    public function testMixedPayment(): void
    {
        $order = $this->bill(['payment_method' => 'mixed', 'cash_paise' => 10000]);
        self::assertSame(['100.00', '80.00'], array_column($order['payments'], 'amount'));
    }

    public function testTokensResetNextDayWhileBillNumbersContinue(): void
    {
        $first = $this->bill();
        $second = $this->bill();
        $tomorrow = $this->bill([], $this->today->modify('+1 day')->setTime(10, 15));

        self::assertSame([1, 2, 1], [(int) $first['token_no'], (int) $second['token_no'], (int) $tomorrow['token_no']]);
        self::assertSame([1, 2, 3], [(int) $first['bill_no'], (int) $second['bill_no'], (int) $tomorrow['bill_no']]);
    }

    public function testSavingTheSameKeyTwiceReturnsTheSameBill(): void
    {
        $key = bin2hex(random_bytes(16));
        $first = $this->bill(['order_key' => $key]);
        $retry = $this->bill(['order_key' => $key, 'service_type' => 'dine_in']);

        self::assertTrue($retry['duplicate']);
        self::assertSame($first['id'], $retry['id']);
        self::assertSame('180.00', $retry['total'], 'the saved bill, not the changed cart');
        self::assertSame(1, (int) $this->db()->query('SELECT COUNT(*) FROM orders')->fetchColumn());
    }

    public function testChangedPricesAreDetected(): void
    {
        self::assertSame('billing.error.price_changed', $this->errorOf(fn () => $this->bill(['expected_total' => 17000])));
        self::assertSame(1, (int) $this->bill(['expected_total' => 18000])['bill_no'], 'no number used by the refused save');
    }

    public function testBadInputIsRefused(): void
    {
        self::assertSame('error.generic', $this->errorOf(fn () => $this->bill(['order_key' => 'abc'])));
        self::assertSame('error.generic', $this->errorOf(fn () => $this->bill(['service_type' => 'delivery'])));
        self::assertSame('payment.error.method', $this->errorOf(fn () => $this->bill(['payment_method' => 'card'])));
        self::assertSame('cart.error.empty', $this->errorOf(fn () => $this->bill(['lines' => []])));
    }

    // ---------- discount ----------

    public function testApprovedDiscountIsAppliedAndAuditLogged(): void
    {
        $order = $this->bill(['discount' => ['amount' => 1000, 'reason' => 'नियमित ग्राहक', 'owner_id' => $this->ownerId]]);

        self::assertSame('10.00', $order['discount']);
        self::assertSame('170.00', $order['total']);
        $audit = $this->db()->query("SELECT * FROM audit_log WHERE action = 'discount'")->fetch();
        self::assertSame($this->ownerId, (int) $audit['staff_id']);
        self::assertSame(['bill_no' => 1, 'amount' => '10.00', 'reason' => 'नियमित ग्राहक', 'billed_by' => $this->staffId], json_decode($audit['details'], true));
    }

    public function testDiscountCannotExceedTheBill(): void
    {
        self::assertSame('discount.error.too_big', $this->errorOf(
            fn () => $this->bill(['discount' => ['amount' => 18100, 'reason' => 'x', 'owner_id' => $this->ownerId]])
        ));
    }

    // ---------- cancel ----------

    public function testCancelKeepsTheBillNumberAndRefundsPayments(): void
    {
        $order = $this->bill();
        $this->orders->cancel((int) $order['id'], 'चुकीचे बिल', $this->ownerId, $this->staffId, $this->today);

        $cancelled = $this->orders->find((int) $order['id']);
        self::assertSame('cancelled', $cancelled['status']);
        self::assertSame(1, (int) $cancelled['bill_no']);
        self::assertSame('चुकीचे बिल', $cancelled['cancel_reason']);
        self::assertSame($this->ownerId, (int) $cancelled['cancelled_by']);
        self::assertSame(['refunded'], array_column($cancelled['payments'], 'status'));
        self::assertSame(1, (int) $this->db()->query("SELECT COUNT(*) FROM audit_log WHERE action = 'cancel_order'")->fetchColumn());
        self::assertSame(2, (int) $this->bill()['bill_no'], 'the cancelled number is never reused');
    }

    public function testCancelRules(): void
    {
        $order = $this->bill();
        $id = (int) $order['id'];
        self::assertSame('cancel.error.reason', $this->errorOf(fn () => $this->orders->cancel($id, '  ', $this->ownerId, $this->staffId, $this->today)));
        self::assertSame('cancel.error.not_today', $this->errorOf(fn () => $this->orders->cancel($id, 'x', $this->ownerId, $this->staffId, $this->today->modify('+1 day'))));

        $this->orders->cancel($id, 'x', $this->ownerId, $this->staffId, $this->today);
        self::assertSame('cancel.error.already', $this->errorOf(fn () => $this->orders->cancel($id, 'x', $this->ownerId, $this->staffId, $this->today)));
        self::assertSame('cancel.error.not_found', $this->errorOf(fn () => $this->orders->cancel(99999, 'x', $this->ownerId, $this->staffId, $this->today)));
    }

    // ---------- day summary ----------

    public function testDaySummaryLeavesCancelledBillsOutOfTheTotals(): void
    {
        $this->bill();                                                             // cash 180
        $this->bill(['payment_method' => 'upi', 'service_type' => 'dine_in']);     // UPI 175
        $this->bill(['payment_method' => 'mixed', 'cash_paise' => 5000]);          // cash 50 + UPI 130
        $wrong = $this->bill();                                                    // cash 180, then cancelled
        $this->orders->cancel((int) $wrong['id'], 'चुकीचे बिल', $this->ownerId, $this->staffId, $this->today);
        $this->bill([], $this->today->modify('+1 day'));                           // tomorrow: not counted

        $summary = (new ReportService($this->db()))->daySummary($this->today);

        self::assertSame('2026-11-26', $summary['date']);
        self::assertSame(23000, $summary['by_mode']['cash']);
        self::assertSame(30500, $summary['by_mode']['upi_counter']);
        self::assertSame(53500, $summary['total']);
        self::assertSame(3, $summary['bill_count']);
        self::assertSame(3, $summary['by_type']['counter']);
        self::assertCount(1, $summary['cancelled']);
        self::assertSame((int) $wrong['bill_no'], (int) $summary['cancelled'][0]['bill_no']);
    }

    public function testDayListShowsTodaysBillsNewestFirst(): void
    {
        $first = $this->bill();
        $second = $this->bill(['payment_method' => 'mixed', 'cash_paise' => 5000]);
        $this->bill([], $this->today->modify('-1 day'));

        $list = $this->orders->forDay($this->today);
        self::assertSame([(int) $second['id'], (int) $first['id']], array_map('intval', array_column($list, 'id')));
        self::assertSame(['cash', 'upi_counter'], array_column($list[0]['payments'], 'mode'));
    }

    public function testReprintIsAuditLogged(): void
    {
        $order = $this->bill();
        $this->orders->logReprint((int) $order['id'], $this->staffId);
        self::assertSame(1, (int) $this->db()->query("SELECT COUNT(*) FROM audit_log WHERE action = 'bill_reprint'")->fetchColumn());
    }
}
