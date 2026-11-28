<?php

declare(strict_types=1);

namespace Tests;

use App\Services\OrderCard;
use App\Services\OrderService;
use App\Services\ReportService;
use App\Services\SettingsService;
use App\Services\SlotService;
use App\Services\UpiService;
use App\Settings;
use App\ValidationException;
use DateTimeImmutable;

/** Pay-first takeaway by UPI without a gateway (decision 2026-11-28, SPEC §6). */
final class PrepayTest extends DatabaseTestCase
{
    private const UPI_ID = 'mahavir.icecream@okaxis';

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
        $this->setUpi(self::UPI_ID);
    }

    private function setUpi(string $id): void
    {
        $this->db()->prepare(
            "INSERT INTO settings (setting_key, setting_value) VALUES ('upi_id', ?) ON DUPLICATE KEY UPDATE setting_value = ?"
        )->execute([$id, $id]);
        Settings::clearCache();
    }

    private function place(array $changes = []): array
    {
        $stmt = $this->db()->prepare(
            "SELECT v.id FROM item_variants v JOIN items i ON i.id = v.item_id WHERE i.name_en = 'Badam Shake' AND v.label_en = 'Full'"
        );
        $stmt->execute();
        return $this->orders->placeTakeaway($changes + [
            'order_key' => bin2hex(random_bytes(16)),
            'name' => 'Asha',
            'mobile' => '98765 43210',
            'slot' => '19:30',
            'note' => '',
            'lines' => [['variant_id' => (int) $stmt->fetchColumn(), 'qty' => 2]],
        ], $this->now);
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

    private function order(int $id): array
    {
        return $this->orders->find($id);
    }

    private function paymentRows(int $orderId): array
    {
        $stmt = $this->db()->prepare('SELECT mode, amount, status, staff_id FROM payments WHERE order_id = ? ORDER BY id');
        $stmt->execute([$orderId]);
        return $stmt->fetchAll();
    }

    // ---------- UPI ID and link ----------

    public function testUpiIdsAreChecked(): void
    {
        foreach (['mahavir.icecream@okaxis', '9922220000@ybl', 'shop-1_a@paytm', 'ab@upi'] as $ok) {
            self::assertTrue(UpiService::isValidId($ok), $ok);
        }
        foreach (['', 'shop', '@okaxis', 'shop@', 'shop@@bank', 'shop name@bank', 'shop@1bank', 'a@b'] as $bad) {
            self::assertFalse(UpiService::isValidId($bad), $bad);
        }
    }

    public function testPayLinkHasShopAmountAndToken(): void
    {
        self::assertSame(
            'upi://pay?pa=mahavir.icecream@okaxis&pn=Mahavir%20Ice%20Cream&am=120.00&cu=INR&tn=Mahavir%20token%2042',
            UpiService::payLink('mahavir.icecream@okaxis', 'Mahavir Ice Cream', 12000, 42)
        );
    }

    public function testSettingsSaveTheUpiIdInLowerCaseOrEmpty(): void
    {
        $service = new SettingsService($this->db());
        $input = Settings::all() + SettingsService::DEFAULTS;
        $service->update(['upi_id' => 'Mahavir.IceCream@OKAXIS'] + $input, $this->ownerId);
        self::assertSame('mahavir.icecream@okaxis', Settings::get('upi_id'));

        $service->update(['upi_id' => ''] + $input, $this->ownerId);
        self::assertFalse(UpiService::enabled());

        $error = $this->errorOf(fn () => $service->update(['upi_id' => 'not an id'] + $input, $this->ownerId));
        self::assertSame('settings.error.upi', $error);
    }

    // ---------- placing ----------

    public function testWithAUpiIdTheOrderWaitsForPaymentAndHoldsItsSlot(): void
    {
        $order = $this->place();

        self::assertSame('pending_payment', $order['status']);
        self::assertSame('online', $order['payment_pref']);
        self::assertSame('0.00', $order['amount_paid']);
        self::assertSame(1, (new SlotService($this->db()))->countAt('2026-11-26 19:30:00'));
        // Not yet a new order for the counter's beep; no payment to check either.
        $summary = $this->orders->newOnlineSummary($this->now);
        self::assertSame(0, $summary['new_count']);
        self::assertSame(0, $summary['pay_count']);
    }

    public function testWithoutAUpiIdCustomersPayAtTheCounterAsBefore(): void
    {
        $this->setUpi('');
        $order = $this->place();

        self::assertSame('received', $order['status']);
        self::assertSame('counter', $order['payment_pref']);
    }

    // ---------- paying ----------

    public function testCustomerSaysPaidThenTheCounterConfirms(): void
    {
        $order = $this->place();
        $id = (int) $order['id'];

        $this->orders->customerSaysPaid($id, $order['order_key'], $this->now);
        $this->orders->customerSaysPaid($id, $order['order_key'], $this->now); // a second tap changes nothing
        self::assertCount(1, $this->paymentRows($id));
        self::assertSame('created', $this->paymentRows($id)[0]['status']);
        self::assertTrue(OrderService::hasPaymentClaim($this->order($id)));

        $summary = $this->orders->newOnlineSummary($this->now);
        self::assertSame(1, $summary['pay_count']);
        self::assertGreaterThan(0, $summary['latest_pay_id']);
        // An unchecked claim is not money yet.
        self::assertSame(0, (new ReportService($this->db()))->daySummary($this->now)['total']);

        $confirmed = $this->orders->confirmPayment($id, $this->staffId, $this->now->modify('+2 minutes'));

        self::assertSame('preparing', $confirmed['status']);
        self::assertSame($confirmed['total'], $confirmed['amount_paid']);
        self::assertSame('2026-11-26 18:02:00', $confirmed['accepted_at']);
        self::assertTrue(OrderService::isPrepaid($confirmed));
        $rows = $this->paymentRows($id);
        self::assertCount(1, $rows);
        self::assertSame(['upi_online', $confirmed['total'], 'success', $this->staffId], [$rows[0]['mode'], $rows[0]['amount'], $rows[0]['status'], (int) $rows[0]['staff_id']]);
        $day = (new ReportService($this->db()))->daySummary($this->now);
        self::assertSame(paise_from_decimal($confirmed['total']), $day['by_mode']['upi_online']);
        self::assertSame(0, $this->orders->newOnlineSummary($this->now)['pay_count']);

        // Confirming twice (two screens) is refused.
        self::assertSame('order.error.wrong_status', $this->errorOf(fn () => $this->orders->confirmPayment($id, $this->staffId, $this->now)));
    }

    public function testTheCounterCanConfirmAPaymentTheCustomerDidNotTapFor(): void
    {
        $order = $this->place();
        $confirmed = $this->orders->confirmPayment((int) $order['id'], $this->staffId, $this->now);

        self::assertSame('preparing', $confirmed['status']);
        $rows = $this->paymentRows((int) $order['id']);
        self::assertCount(1, $rows);
        self::assertSame('success', $rows[0]['status']);
    }

    public function testAPaidOrderIsHandedOverWithoutASecondPayment(): void
    {
        $order = $this->place();
        $id = (int) $order['id'];
        $this->orders->confirmPayment($id, $this->staffId, $this->now);
        $this->orders->markReady($id, $this->staffId, $this->now);

        $done = $this->orders->pickup($id, '', 0, $this->staffId, $this->now);

        self::assertSame('completed', $done['status']);
        self::assertNotNull($done['bill_no']);
        self::assertCount(1, $this->paymentRows($id));
        self::assertSame(paise_from_decimal($done['total']), (new ReportService($this->db()))->daySummary($this->now)['total']);
    }

    // ---------- not paid ----------

    public function testNotReceivedCancelsWithoutTheOwner(): void
    {
        $order = $this->place();
        $id = (int) $order['id'];
        $this->orders->customerSaysPaid($id, $order['order_key'], $this->now);

        $this->orders->paymentMissing($id, $this->staffId, $this->now);

        $cancelled = $this->order($id);
        self::assertSame('cancelled', $cancelled['status']);
        self::assertSame(OrderService::PAYMENT_MISSING_REASON, $cancelled['cancel_reason']);
        self::assertSame('failed', $this->paymentRows($id)[0]['status']);
        self::assertSame(0, (new SlotService($this->db()))->countAt('2026-11-26 19:30:00'));
    }

    public function testCustomerMayCancelOnlyUntilTheySayTheyPaid(): void
    {
        $first = $this->place();
        $this->orders->cancelByCustomer((int) $first['id'], $first['order_key'], $this->now);
        self::assertSame('cancelled', $this->order((int) $first['id'])['status']);

        $second = $this->place();
        $this->orders->customerSaysPaid((int) $second['id'], $second['order_key'], $this->now);
        self::assertSame('order.error.cannot_cancel', $this->errorOf(
            fn () => $this->orders->cancelByCustomer((int) $second['id'], $second['order_key'], $this->now)
        ));
    }

    public function testUnpaidOrdersAreCancelledAfterTheWaitButOnesSaidPaidWait(): void
    {
        $unpaid = $this->place();
        $claimed = $this->place();
        $this->orders->customerSaysPaid((int) $claimed['id'], $claimed['order_key'], $this->now);

        self::assertSame(0, $this->orders->cancelUnpaid($this->now->modify('+14 minutes')));
        self::assertSame(1, $this->orders->cancelUnpaid($this->now->modify('+15 minutes')));

        $cancelled = $this->order((int) $unpaid['id']);
        self::assertSame('cancelled', $cancelled['status']);
        self::assertSame(OrderService::UNPAID_REASON, $cancelled['cancel_reason']);
        self::assertSame('pending_payment', $this->order((int) $claimed['id'])['status']);
        self::assertSame(1, (new SlotService($this->db()))->countAt('2026-11-26 19:30:00'));
        // Not a no-show: the customer's number is not counted against.
        self::assertSame(0, (int) $this->db()->query("SELECT no_show_count FROM customers WHERE mobile = '9876543210'")->fetchColumn());
    }

    public function testCancellingAPaidOrderNeedsTheOwnerAndTakesTheMoneyOutOfTheDay(): void
    {
        $order = $this->place();
        $id = (int) $order['id'];
        $this->orders->confirmPayment($id, $this->staffId, $this->now);

        self::assertSame('cancel.error.owner_needed', $this->errorOf(
            fn () => $this->orders->cancel($id, 'Item ran out', null, $this->staffId, $this->now)
        ));
        $this->orders->cancel($id, 'Item ran out', $this->ownerId, $this->staffId, $this->now);

        self::assertSame('refunded', $this->paymentRows($id)[0]['status']);
        self::assertSame(0, (new ReportService($this->db()))->daySummary($this->now)['total']);
    }

    // ---------- what customers and staff see ----------

    public function testStatusPageAndCounterCardFollowThePayment(): void
    {
        $order = $this->place();
        $id = (int) $order['id'];

        $view = customer_status($this->order($id));
        self::assertSame(t('status.pending_payment'), $view['label']);
        self::assertTrue($view['can_cancel']);
        self::assertSame(['pending_payment', 'preparing', 'ready', 'completed'], array_column($view['steps'], 'key'));
        self::assertStringContainsString(format_time('18:15'), $view['help']); // pay by: placed 18:00 + 15 min

        $card = OrderCard::make($this->orders->activeOnline($this->now)[0], $this->now);
        self::assertSame('pending_payment', $card['status']);
        self::assertFalse($card['pay_claimed']);
        self::assertSame(format_time('18:15'), $card['pay_by']);
        self::assertFalse($card['paid']);

        $this->orders->customerSaysPaid($id, $order['order_key'], $this->now);
        $view = customer_status($this->order($id));
        self::assertSame(t('status.payment_checking'), $view['label']);
        self::assertFalse($view['can_cancel']);
        $card = OrderCard::make($this->orders->activeOnline($this->now)[0], $this->now);
        self::assertTrue($card['pay_claimed']);
        self::assertSame(t('online.check_payment'), $card['status_label']);
        self::assertSame('', $card['pay_by']);

        $this->orders->confirmPayment($id, $this->staffId, $this->now);
        $card = OrderCard::make($this->orders->activeOnline($this->now)[0], $this->now);
        self::assertTrue($card['paid']);
        self::assertSame(t('status.help.ready_paid', ['token' => $order['token_no']]), customer_status(['status' => 'ready'] + $this->order($id))['help']);
    }
}
