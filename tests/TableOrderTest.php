<?php

declare(strict_types=1);

namespace Tests;

use App\Lang;
use App\Services\BillFormatter;
use App\Services\OrderService;
use App\Services\ReportService;
use App\Services\TableService;
use App\ValidationException;
use DateTimeImmutable;

/** Table ordering end to end in the service layer (SPEC §4, §7, §9; BUILD_PLAN M4). */
final class TableOrderTest extends DatabaseTestCase
{
    private OrderService $orders;
    private TableService $tables;
    private DateTimeImmutable $now;
    private int $staffId;
    private int $ownerId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->orders = new OrderService($this->db());
        $this->tables = new TableService($this->db());
        $this->now = new DateTimeImmutable('2026-11-26 19:00');
        $this->ownerId = $this->makeOwner();
        $this->db()->exec("INSERT INTO staff (name, role) VALUES ('Ramesh', 'staff')");
        $this->staffId = (int) $this->db()->lastInsertId();
    }

    protected function tearDown(): void
    {
        Lang::set(Lang::DEFAULT);
        parent::tearDown();
    }

    private function table(string $code): array
    {
        $stmt = $this->db()->prepare('SELECT * FROM dining_tables WHERE code = ?');
        $stmt->execute([$code]);
        return $stmt->fetch();
    }

    private function variant(string $itemEn, string $labelEn): int
    {
        $stmt = $this->db()->prepare(
            'SELECT v.id FROM item_variants v JOIN items i ON i.id = v.item_id WHERE i.name_en = ? AND v.label_en = ?'
        );
        $stmt->execute([$itemEn, $labelEn]);
        return (int) $stmt->fetchColumn();
    }

    private function order(string $code = 'T4', array $changes = [], ?DateTimeImmutable $at = null): array
    {
        return $this->orders->placeTableOrder($changes + [
            'table_token' => $this->table($code)['qr_token'],
            'order_key' => bin2hex(random_bytes(16)),
            'name' => 'Asha',
            'mobile' => '9876543210',
            'note' => '',
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

    private function statusOf(int $orderId): string
    {
        return (string) $this->db()->query("SELECT status FROM orders WHERE id = {$orderId}")->fetchColumn();
    }

    // ---------- ordering ----------

    public function testTwoOrdersFromTwoPhonesMakeOneRunningBill(): void
    {
        $first = $this->order();
        $second = $this->order('T4', [
            'name' => 'Ravi',
            'mobile' => '9123456780',
            'lines' => [['variant_id' => $this->variant('Badam Shake', 'Full'), 'qty' => 1], ['variant_id' => $this->variant('Vanilla', 'Half'), 'qty' => 1]],
        ]);

        self::assertSame('table', $first['order_type']);
        self::assertSame('received', $first['status']);
        self::assertSame('dine_in', $first['service_type']);
        self::assertNull($first['bill_no'], 'table orders are billed per visit');
        self::assertSame('0.00', $first['parcel_charge'], 'never a parcel charge on table orders');
        self::assertSame('T4', $first['table_code']);
        self::assertSame($first['table_session_id'], $second['table_session_id'], 'the second phone joins the open visit');
        self::assertSame([1, 2], [(int) $first['token_no'], (int) $second['token_no']]);
        self::assertSame(16000 + 9500, $this->tables->runningTotal((int) $first['table_session_id']));
    }

    public function testCombinedBillMergesTheSameItem(): void
    {
        $first = $this->order();
        $this->order('T4', ['lines' => [['variant_id' => $this->variant('Badam Shake', 'Full'), 'qty' => 1], ['variant_id' => $this->variant('Vanilla', 'Half'), 'qty' => 1]]]);

        $lines = $this->tables->combinedLines((int) $first['table_session_id']);
        self::assertSame([['बदाम शेक (फुल) / Badam Shake (Full)', '3.00', '240.00'], ['वॅनिला (हाफ) / Vanilla (Half)', '1.00', '15.00']],
            array_map(static fn ($l) => [$l['item_name'], (string) $l['qty'], (string) $l['line_total']], $lines));
    }

    public function testUnknownOrRegeneratedQrIsRefused(): void
    {
        self::assertSame('table.error.invalid', $this->errorOf(fn () => $this->order('T4', ['table_token' => str_repeat('0', 32)])));

        $old = $this->table('T4')['qr_token'];
        $this->tables->regenerateToken((int) $this->table('T4')['id'], $this->ownerId);
        self::assertNull($this->tables->findByToken($old), 'the old sticker stops working');
        self::assertNotNull($this->tables->findByToken($this->table('T4')['qr_token']));
        self::assertSame(1, (int) $this->db()->query("SELECT COUNT(*) FROM audit_log WHERE action = 'table_qr_regenerate'")->fetchColumn());
    }

    public function testInactiveTableCannotOrder(): void
    {
        $this->tables->setActive((int) $this->table('T6')['id'], false, $this->ownerId);
        self::assertSame('table.error.invalid', $this->errorOf(fn () => $this->order('T6')));
    }

    // ---------- status ----------

    public function testAcceptThenServe(): void
    {
        $id = (int) $this->order()['id'];
        self::assertSame('order.error.wrong_status', $this->errorOf(fn () => $this->orders->markServed($id, $this->staffId)));
        $this->orders->accept($id, $this->staffId, $this->now);
        $this->orders->markServed($id, $this->staffId, $this->now);
        self::assertSame('served', $this->statusOf($id));
    }

    public function testTableOrdersAppearOnTheOnlineTab(): void
    {
        $order = $this->order();
        $active = $this->orders->activeOnline($this->now);
        self::assertSame('table', $active[0]['order_type']);
        self::assertSame('T4', $active[0]['table_code']);
        self::assertSame(1, $this->orders->newOnlineSummary($this->now)['new_count']);
        self::assertSame((int) $order['id'], $this->orders->newOnlineSummary($this->now)['latest_new_id']);
    }

    // ---------- settling ----------

    public function testSettleMakesOneBillAndFreesTheTable(): void
    {
        $first = $this->order();
        $second = $this->order('T4', ['mobile' => '9123456780']);
        $this->orders->accept((int) $first['id'], $this->staffId, $this->now);
        $this->orders->markServed((int) $first['id'], $this->staffId, $this->now);
        $sessionId = (int) $first['table_session_id'];
        $this->tables->raiseAlert((int) $this->table('T4')['id'], 'request_bill', $this->now);

        $session = $this->tables->settle($sessionId, 'cash', 0, null, $this->staffId, 32000, $this->now->modify('+40 minutes'));

        self::assertSame('closed', $session['status']);
        self::assertSame(1, (int) $session['bill_no']);
        self::assertSame('320.00', $session['total']);
        self::assertSame(['completed', 'completed'], [$this->statusOf((int) $first['id']), $this->statusOf((int) $second['id'])]);
        self::assertNull($this->db()->query("SELECT bill_no FROM orders WHERE id = {$first['id']}")->fetchColumn() ?: null, 'orders keep bill_no NULL');
        self::assertSame([['mode' => 'cash', 'amount' => '320.00', 'status' => 'success']], $this->tables->sessionPayments($sessionId));
        self::assertNull($this->tables->openSession((int) $this->table('T4')['id']), 'T4 is free');
        self::assertSame([], $this->tables->openAlerts(), 'alerts cleared');

        $next = $this->order('T4', [], $this->now->modify('+50 minutes'));
        self::assertNotSame($sessionId, (int) $next['table_session_id'], 'the next guests start a new visit');
    }

    public function testSettleWithDiscountAndChecks(): void
    {
        $sessionId = (int) $this->order()['table_session_id'];
        $discount = ['amount' => 2000, 'reason' => 'नियमित ग्राहक', 'owner_id' => $this->ownerId];

        self::assertSame('discount.error.too_big', $this->errorOf(fn () => $this->tables->settle($sessionId, 'upi', 0, ['amount' => 20000] + $discount, $this->staffId)));
        self::assertSame('billing.error.price_changed', $this->errorOf(fn () => $this->tables->settle($sessionId, 'upi', 0, null, $this->staffId, 99900)));

        $session = $this->tables->settle($sessionId, 'mixed', 10000, $discount, $this->staffId, 14000, $this->now);
        self::assertSame(['160.00', '20.00', '140.00'], [$session['subtotal'], $session['discount'], $session['total']]);
        self::assertSame(['100.00', '40.00'], array_column($this->tables->sessionPayments($sessionId), 'amount'));
        self::assertSame(1, (int) $this->db()->query("SELECT COUNT(*) FROM audit_log WHERE action = 'discount' AND entity = 'table_session'")->fetchColumn());
        self::assertSame('table.error.already_settled', $this->errorOf(fn () => $this->tables->settle($sessionId, 'cash', 0, null, $this->staffId)));
    }

    public function testBillNumbersAreSharedWithCounterBills(): void
    {
        $this->orders->createCounterOrder([
            'order_key' => bin2hex(random_bytes(16)),
            'lines' => [['variant_id' => $this->variant('Vanilla', 'Half'), 'qty' => 1]],
            'service_type' => 'dine_in',
            'payment_method' => 'cash',
        ], $this->staffId, $this->now);
        $sessionId = (int) $this->order()['table_session_id'];
        self::assertSame(2, (int) $this->tables->settle($sessionId, 'cash', 0, null, $this->staffId, null, $this->now)['bill_no']);
    }

    // ---------- move, cancel ----------

    public function testMoveOrderToAnotherTable(): void
    {
        $order = $this->order('T4');
        $this->tables->moveOrder((int) $order['id'], (int) $this->table('T5')['id'], $this->staffId, $this->now);

        $moved = $this->orders->find((int) $order['id']);
        self::assertSame('T5', $moved['table_code']);
        self::assertNull($this->tables->openSession((int) $this->table('T4')['id']), 'T4 had nothing else, so it is free');
        self::assertNotNull($this->tables->openSession((int) $this->table('T5')['id']));
        self::assertSame('table.error.move_target', $this->errorOf(fn () => $this->tables->moveOrder((int) $order['id'], (int) $this->table('T5')['id'], $this->staffId)));
        self::assertSame(1, (int) $this->db()->query("SELECT COUNT(*) FROM audit_log WHERE action = 'table_move'")->fetchColumn());
    }

    public function testCancellingTheOnlyOrderFreesTheTable(): void
    {
        $order = $this->order();
        $this->orders->cancel((int) $order['id'], 'ग्राहकाने रद्द केले', null, $this->staffId, $this->now);
        self::assertNull($this->tables->openSession((int) $this->table('T4')['id']));
    }

    public function testSettledTableOrderIsCancelledOnlyWithItsBill(): void
    {
        $order = $this->order();
        $sessionId = (int) $order['table_session_id'];
        $this->tables->settle($sessionId, 'cash', 0, null, $this->staffId, null, $this->now);
        self::assertSame('order.error.wrong_status', $this->errorOf(fn () => $this->orders->cancel((int) $order['id'], 'x', $this->ownerId, $this->staffId, $this->now)));

        $this->tables->cancelBill($sessionId, 'चुकीचे बिल', $this->ownerId, $this->staffId, $this->now);
        self::assertSame('cancelled', $this->statusOf((int) $order['id']));
        self::assertSame(['refunded'], array_column($this->tables->sessionPayments($sessionId), 'status'));
        self::assertSame('cancel.error.already', $this->errorOf(fn () => $this->tables->cancelBill($sessionId, 'x', $this->ownerId, $this->staffId, $this->now)));
        self::assertSame('cancel.error.not_today', $this->errorOf(fn () => $this->tables->cancelBill($sessionId, 'x', $this->ownerId, $this->staffId, $this->now->modify('+1 day'))));
    }

    // ---------- alerts ----------

    public function testCallStaffAndRequestBill(): void
    {
        $t7 = (int) $this->table('T7')['id'];
        $this->tables->raiseAlert($t7, 'call_staff', $this->now);
        self::assertSame('table.error.wait', $this->errorOf(fn () => $this->tables->raiseAlert($t7, 'call_staff', $this->now->modify('+20 seconds'))));
        self::assertSame('table.error.no_bill', $this->errorOf(fn () => $this->tables->raiseAlert($t7, 'request_bill', $this->now->modify('+40 seconds'))));

        $summary = $this->orders->newOnlineSummary($this->now);
        self::assertSame(1, $summary['alert_count']);
        $tile = array_values(array_filter($this->tables->overview($this->now), static fn ($t) => $t['code'] === 'T7'))[0];
        self::assertSame(['call_staff'], array_column($tile['alerts'], 'type'));
        self::assertSame('outside', $tile['area']);

        $this->order('T7');
        $this->tables->raiseAlert($t7, 'request_bill', $this->now->modify('+2 minutes'));
        $tile = array_values(array_filter($this->tables->overview($this->now->modify('+3 minutes')), static fn ($t) => $t['code'] === 'T7'))[0];
        self::assertSame('bill_requested', $tile['status']);
        self::assertSame(16000, $tile['total_paise']);
        self::assertSame(1, $tile['unserved']);

        foreach ($this->tables->openAlerts() as $alert) {
            $this->tables->dismissAlert((int) $alert['id'], $this->staffId, $this->now);
        }
        self::assertSame(0, $this->orders->newOnlineSummary($this->now)['alert_count']);
    }

    // ---------- day summary + printing ----------

    public function testDaySummaryCountsSettledTablesAndLeavesCancelledOnesOut(): void
    {
        $kept = (int) $this->order('T4')['table_session_id'];
        $this->tables->settle($kept, 'upi', 0, null, $this->staffId, null, $this->now);
        $wrong = (int) $this->order('T5')['table_session_id'];
        $this->tables->settle($wrong, 'cash', 0, null, $this->staffId, null, $this->now);
        $this->tables->cancelBill($wrong, 'चुकीचे बिल', $this->ownerId, $this->staffId, $this->now);

        $summary = (new ReportService($this->db()))->daySummary($this->now);
        self::assertSame(16000, $summary['by_mode']['upi_counter']);
        self::assertSame(0, $summary['by_mode']['cash']);
        self::assertSame(1, $summary['bill_count']);
        self::assertSame(1, $summary['by_type']['table']);
        self::assertCount(1, $summary['cancelled']);
        self::assertSame('चुकीचे बिल', $summary['cancelled'][0]['cancel_reason']);
    }

    public function testCombinedTableBillAndKitchenTicketPrint(): void
    {
        $first = $this->order('T4', ['note' => 'कमी साखर']);
        $this->order('T4', ['lines' => [['variant_id' => $this->variant('Badam Shake', 'Full'), 'qty' => 1]]]);
        $sessionId = (int) $first['table_session_id'];
        $this->tables->settle($sessionId, 'cash', 0, null, $this->staffId, null, $this->now);

        Lang::set('mr');
        $bill = BillFormatter::fromSession($this->tables->findSession($sessionId), $this->tables->combinedLines($sessionId), $this->tables->sessionPayments($sessionId), false);
        self::assertSame('4', $bill['headline']);
        self::assertSame([['name' => 'बदाम शेक (फुल)', 'qty' => '3', 'rate' => 8000, 'amount' => 24000]], $bill['lines']);
        self::assertSame(0, $bill['parcel']);

        ob_start();
        view('print_bill', ['bill' => $bill, 'copy' => false, 'shop' => \App\Settings::all()]);
        $html = (string) ob_get_clean();
        self::assertStringContainsString('टेबल बिल', $html);
        self::assertStringContainsString('₹240', $html);
        self::assertStringNotContainsString('पार्सल चार्ज', $html);

        ob_start();
        view('print_kot', ['order' => $this->orders->find((int) $first['id'])]);
        $kot = (string) ob_get_clean();
        self::assertStringContainsString('टेबल 4', $kot);
        self::assertStringContainsString('TABLE 4', $kot);
        self::assertStringContainsString('बदाम शेक (फुल)', $kot);
        self::assertStringContainsString('कमी साखर', $kot);
        self::assertStringNotContainsString('₹', $kot, 'no prices on a kitchen ticket');
    }
}
