<?php

declare(strict_types=1);

namespace Tests;

use App\Lang;
use App\Services\BulkService;
use App\Services\OrderService;
use App\Services\ReportExport;
use App\Services\ReportService;
use App\Services\TableService;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Date-range reports (SPEC §12, BUILD_PLAN M7). A three-day fixture made through the real
 * services; prices from the seed: Badam Shake Full ₹80, Vanilla Half ₹15.
 *
 * 24/11  counter A (Ramesh) 2 Badam + 1 Vanilla, parcel, cash ₹180
 *        counter B (Sita) 1 Badam + 2 Vanilla, dine-in, ₹10 discount, UPI ₹100
 *        counter C (Ramesh) 1 Vanilla, cash ₹15, cancelled by the owner
 *        bulk request 5 Badam (₹400) for 26/11, approved, ₹200 advance in cash
 * 25/11  takeaway 1 Badam + parcel (Sita), UPI ₹85
 *        table T4: 2 Badam (+1 Vanilla cancelled before settling), Ramesh, cash ₹100 + UPI ₹60
 *        table T5: 1 Vanilla, cash ₹15, bill cancelled by the owner
 * 26/11  bulk picked up (Sita), balance UPI ₹200, bill ₹400
 *        counter D (Sita) 1 Badam, cash ₹80
 */
final class ReportRangeTest extends DatabaseTestCase
{
    private ReportService $reports;
    private DateTimeImmutable $day1;
    private DateTimeImmutable $day2;
    private DateTimeImmutable $day3;
    private int $ownerId;
    private int $ramesh;
    private int $sita;
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->reports = new ReportService($this->db());
        $this->day1 = new DateTimeImmutable('2026-11-24 00:00');
        $this->day2 = new DateTimeImmutable('2026-11-25 00:00');
        $this->day3 = new DateTimeImmutable('2026-11-26 00:00');
        $this->ownerId = $this->makeOwner();
        $this->db()->exec("INSERT INTO staff (name, role) VALUES ('Ramesh', 'staff'), ('Sita', 'staff')");
        $this->ramesh = (int) $this->db()->query("SELECT id FROM staff WHERE name = 'Ramesh'")->fetchColumn();
        $this->sita = (int) $this->db()->query("SELECT id FROM staff WHERE name = 'Sita'")->fetchColumn();
        $this->makeFixture();
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

    private function at(DateTimeImmutable $day, string $time): DateTimeImmutable
    {
        [$h, $m] = array_map('intval', explode(':', $time));
        return $day->setTime($h, $m);
    }

    private function makeFixture(): void
    {
        $orders = new OrderService($this->db());
        $tables = new TableService($this->db());
        $bulk = new BulkService($this->db());
        $badam = $this->variant('Badam Shake', 'Full');
        $vanilla = $this->variant('Vanilla', 'Half');
        $counter = function (array $lines, string $service, string $method, int $staff, DateTimeImmutable $at, ?array $discount = null) use ($orders): array {
            return $orders->createCounterOrder([
                'order_key' => bin2hex(random_bytes(16)),
                'lines' => $lines,
                'service_type' => $service,
                'payment_method' => $method,
                'discount' => $discount,
            ], $staff, $at);
        };
        $tableOrder = function (string $code, array $lines, DateTimeImmutable $at) use ($orders): array {
            $token = (string) $this->db()->query("SELECT qr_token FROM dining_tables WHERE code = '{$code}'")->fetchColumn();
            return $orders->placeTableOrder([
                'table_token' => $token, 'order_key' => bin2hex(random_bytes(16)),
                'name' => 'आशा', 'mobile' => '9876543210', 'note' => '', 'lines' => $lines,
            ], $at);
        };

        // ---- day 1
        $this->ids['A'] = $counter([['variant_id' => $badam, 'qty' => 2], ['variant_id' => $vanilla, 'qty' => 1]], 'parcel', 'cash', $this->ramesh, $this->at($this->day1, '11:00'));
        $this->ids['B'] = $counter([['variant_id' => $badam, 'qty' => 1], ['variant_id' => $vanilla, 'qty' => 2]], 'dine_in', 'upi', $this->sita, $this->at($this->day1, '12:00'),
            ['amount' => 1000, 'reason' => 'नियमित ग्राहक', 'owner_id' => $this->ownerId]);
        $this->ids['C'] = $counter([['variant_id' => $vanilla, 'qty' => 1]], 'dine_in', 'cash', $this->ramesh, $this->at($this->day1, '13:00'));
        $orders->cancel((int) $this->ids['C']['id'], 'चुकीचे बिल', $this->ownerId, $this->ramesh, $this->at($this->day1, '13:05'));
        $this->ids['bulk'] = $bulk->request([
            'order_key' => bin2hex(random_bytes(16)), 'name' => 'सुनीता', 'mobile' => '9822000000', 'note' => '',
            'event_date' => '2026-11-26', 'pickup_time' => '18:00', 'lines' => [['variant_id' => $badam, 'qty' => '5']],
        ], $this->at($this->day1, '14:00'));
        $bulkId = (int) $this->ids['bulk']['id'];
        $bulk->approve($bulkId, 20000, $this->ownerId, $this->at($this->day1, '15:00'));
        $bulk->recordPayment($bulkId, 'cash', 20000, $this->ramesh, $this->at($this->day1, '16:00'));

        // ---- day 2
        $takeaway = $orders->placeTakeaway([
            'order_key' => bin2hex(random_bytes(16)), 'name' => 'Asha', 'mobile' => '9876543210', 'slot' => '19:30',
            'note' => '', 'lines' => [['variant_id' => $badam, 'qty' => 1]],
        ], $this->at($this->day2, '18:00'));
        $orders->accept((int) $takeaway['id'], $this->sita, $this->at($this->day2, '18:05'));
        $this->ids['takeaway'] = $orders->pickup((int) $takeaway['id'], 'upi', 0, $this->sita, $this->at($this->day2, '19:35'));

        $t4 = $tableOrder('T4', [['variant_id' => $badam, 'qty' => 2]], $this->at($this->day2, '19:00'));
        $t4Extra = $tableOrder('T4', [['variant_id' => $vanilla, 'qty' => 1]], $this->at($this->day2, '19:10'));
        $orders->cancel((int) $t4Extra['id'], 'ग्राहकाने नको सांगितले', null, $this->ramesh, $this->at($this->day2, '19:12'));
        $this->ids['T4'] = $tables->settle((int) $t4['table_session_id'], 'mixed', 10000, null, $this->ramesh, null, $this->at($this->day2, '19:40'));

        $t5 = $tableOrder('T5', [['variant_id' => $vanilla, 'qty' => 1]], $this->at($this->day2, '19:20'));
        $this->ids['T5'] = $tables->settle((int) $t5['table_session_id'], 'cash', 0, null, $this->ramesh, null, $this->at($this->day2, '19:45'));
        $tables->cancelBill((int) $t5['table_session_id'], 'चुकीचे टेबल', $this->ownerId, $this->ramesh, $this->at($this->day2, '19:50'));

        // ---- day 3
        $bulk->markReady($bulkId, $this->sita, $this->at($this->day3, '17:00'));
        $bulk->complete($bulkId, 'upi', 0, $this->sita, $this->at($this->day3, '18:00'));
        $this->ids['D'] = $counter([['variant_id' => $badam, 'qty' => 1]], 'dine_in', 'cash', $this->sita, $this->at($this->day3, '19:00'));
    }

    // ---------------------------------------------------------------
    // BUILD_PLAN M7: report totals for a day equal that day's close
    // ---------------------------------------------------------------

    public function testEachDayOfTheSalesByDayReportEqualsTheDayClose(): void
    {
        $days = $this->reports->salesByDay($this->day1, $this->day3);
        self::assertSame(['2026-11-24', '2026-11-25', '2026-11-26'], array_column($days, 'date'));

        foreach ($days as $day) {
            $close = $this->reports->daySummary(new DateTimeImmutable($day['date']));
            self::assertSame($close['by_mode'], $day['by_mode'], $day['date']);
            self::assertSame($close['total'], $day['total'], $day['date']);
            self::assertSame($close['bill_count'], $day['bills'], $day['date']);
            self::assertSame($close['bill_total'], $day['bill_total'], $day['date']);
            self::assertSame($close['discount_total'], $day['discount'], $day['date']);
            self::assertSame(count($close['cancelled']), $day['cancelled'], $day['date']);
        }
    }

    public function testDayCloseNumbersOfTheFixture(): void
    {
        $day1 = $this->reports->daySummary($this->day1);
        self::assertSame(['cash' => 38000, 'upi_counter' => 10000, 'upi_online' => 0], $day1['by_mode'], 'bulk advance counts on the day paid');
        self::assertSame([2, 28000, 1000, 1], [$day1['bill_count'], $day1['bill_total'], $day1['discount_total'], count($day1['cancelled'])]);
        self::assertSame(['counter' => 2, 'takeaway' => 0, 'table' => 0, 'bulk' => 1], $day1['by_type']);

        $day2 = $this->reports->daySummary($this->day2);
        self::assertSame(['cash' => 10000, 'upi_counter' => 14500, 'upi_online' => 0], $day2['by_mode'], 'cancelled table bill left out');
        self::assertSame([2, 24500, 0, 1], [$day2['bill_count'], $day2['bill_total'], $day2['discount_total'], count($day2['cancelled'])]);
        self::assertSame(['counter' => 0, 'takeaway' => 1, 'table' => 1, 'bulk' => 0], $day2['by_type']);

        $day3 = $this->reports->daySummary($this->day3);
        self::assertSame(['cash' => 8000, 'upi_counter' => 20000, 'upi_online' => 0], $day3['by_mode']);
        self::assertSame([2, 48000], [$day3['bill_count'], $day3['bill_total']], 'the bulk bill counts on the pickup day');
    }

    public function testRangeTotalsAreTheSumOfTheDays(): void
    {
        $range = $this->reports->summary($this->day1, $this->day3);
        $days = $this->reports->salesByDay($this->day1, $this->day3);

        self::assertSame(array_sum(array_column($days, 'total')), $range['total']);
        self::assertSame(['cash' => 56000, 'upi_counter' => 44500, 'upi_online' => 0], $range['by_mode']);
        self::assertSame($range['by_mode'], $this->reports->paymentSplit($this->day1, $this->day3));
        self::assertSame(100500, $range['total']);
        self::assertSame(6, $range['bill_count']);
        self::assertSame(100500, $range['bill_total'], 'every bill fully paid inside the range: money = bills');
        self::assertSame(1000, $range['discount_total']);
        self::assertCount(2, $range['cancelled']);
        self::assertSame(['counter' => 3, 'takeaway' => 1, 'table' => 1, 'bulk' => 1], $range['by_type']);
    }

    public function testDaysWithoutSalesAreListedWithZeros(): void
    {
        $days = $this->reports->salesByDay($this->day1->modify('-1 day'), $this->day1);
        self::assertSame('2026-11-23', $days[0]['date']);
        self::assertSame([0, 0, 0, 0], [$days[0]['total'], $days[0]['bills'], $days[0]['discount'], $days[0]['cancelled']]);
        self::assertSame(48000, $days[1]['total']);
    }

    // ---------------------------------------------------------------
    // Order types, items, top flavours, staff, cancelled
    // ---------------------------------------------------------------

    public function testOrderTypes(): void
    {
        self::assertSame([
            'counter' => ['orders' => 3, 'bills' => 3, 'amount' => 36000],
            'takeaway' => ['orders' => 1, 'bills' => 1, 'amount' => 8500],
            'table' => ['orders' => 1, 'bills' => 1, 'amount' => 16000],
            'bulk' => ['orders' => 1, 'bills' => 1, 'amount' => 40000],
        ], $this->reports->orderTypes($this->day1, $this->day3));
    }

    public function testItemSalesLeaveOutCancelledOrdersAndCountBulkOnItsBillDay(): void
    {
        $byVariant = static fn (array $lines): array => array_combine(
            array_map(static fn (array $l): string => $l['name_en'] . ' ' . $l['label_en'], $lines),
            array_map(static fn (array $l): array => [$l['qty'], $l['amount']], $lines),
        );

        self::assertSame([
            'Vanilla Half' => ['3.00', 4500],
            'Badam Shake Full' => ['12.00', 96000],
        ], $byVariant($this->reports->itemSales($this->day1, $this->day3)), 'menu order; C, the cancelled T4 order and T5 left out');

        self::assertSame([
            'Vanilla Half' => ['3.00', 4500],
            'Badam Shake Full' => ['3.00', 24000],
        ], $byVariant($this->reports->itemSales($this->day1, $this->day1)), 'the bulk lines are not sold yet on day 1');
    }

    public function testTopFlavoursRankedByAmount(): void
    {
        $top = $this->reports->topItems($this->day1, $this->day3);
        self::assertSame(['Badam Shake', 'Vanilla'], array_column($top, 'name_en'));
        self::assertSame(['12.00', '3.00'], array_column($top, 'qty'));
        self::assertSame([96000, 4500], array_column($top, 'amount'));
    }

    public function testStaffWiseBills(): void
    {
        $staff = $this->reports->staffBills($this->day1, $this->day3);
        self::assertSame([
            ['staff_id' => $this->sita, 'name' => 'Sita', 'bills' => 4, 'amount' => 66500, 'discount' => 1000],
            ['staff_id' => $this->ramesh, 'name' => 'Ramesh', 'bills' => 2, 'amount' => 34000, 'discount' => 0],
        ], $staff);
    }

    public function testCancelledBillsWithReasonAndWhoCancelled(): void
    {
        $cancelled = $this->reports->cancelledBills($this->day1, $this->day3);

        self::assertSame([(int) $this->ids['C']['bill_no'], (int) $this->ids['T5']['bill_no']], array_map('intval', array_column($cancelled, 'bill_no')));
        self::assertSame(['counter', 'table'], array_column($cancelled, 'type'));
        self::assertSame(['चुकीचे बिल', 'चुकीचे टेबल'], array_column($cancelled, 'cancel_reason'));
        self::assertSame(['Test Owner', 'Test Owner'], array_column($cancelled, 'cancelled_by_name'));
        self::assertSame('T5', $cancelled[1]['table_code']);
        self::assertSame(['15.00', '15.00'], array_column($cancelled, 'total'));
    }

    // ---------------------------------------------------------------
    // CSV versions
    // ---------------------------------------------------------------

    public function testCsvExportsInThePageLanguage(): void
    {
        $export = new ReportExport($this->reports);
        Lang::set('en');

        $items = $export->build('items', $this->day1, $this->day3);
        self::assertSame('mahavir-items-2026-11-24_2026-11-26', $items['name']);
        self::assertSame(['Item', 'Quantity', 'Amount'], $items['header']);
        self::assertSame([['Vanilla (Half)', '3', '45.00'], ['Badam Shake (Full)', '12', '960.00']], $items['rows']);

        $days = $export->build('days', $this->day1, $this->day3);
        self::assertSame(['2026-11-24', 2, '380.00', '100.00', '0.00', '480.00', '280.00', '10.00', 1], $days['rows'][0]);

        $cancelled = $export->build('cancelled', $this->day1, $this->day3);
        self::assertSame('Table 5', $cancelled['rows'][1][3]);

        Lang::set('mr');
        $top = $export->build('top', $this->day1, $this->day3);
        self::assertSame([1, 'बदाम शेक', '12', '960.00'], $top['rows'][0]);

        foreach (ReportExport::REPORTS as $report) {
            $table = $export->build($report, $this->day1, $this->day3);
            self::assertNotNull($table, $report);
            foreach ($table['rows'] as $row) {
                self::assertCount(count($table['header']), $row, $report);
            }
        }
        self::assertNull($export->build('salaries', $this->day1, $this->day3));
    }

    // ---------------------------------------------------------------
    // Date range from the form
    // ---------------------------------------------------------------

    public function testRangePresetsAndCustomDates(): void
    {
        $today = new DateTimeImmutable('2026-11-26 15:30', new DateTimeZone('Asia/Kolkata')); // a Thursday
        $range = static fn (mixed $preset, mixed $from = null, mixed $to = null): array => (static function (array $r): array {
            return [$r['preset'], $r['from']->format('Y-m-d'), $r['to']->format('Y-m-d'), $r['error']];
        })(ReportService::range($preset, $from, $to, $today));

        self::assertSame(['today', '2026-11-26', '2026-11-26', null], $range(null));
        self::assertSame(['today', '2026-11-26', '2026-11-26', null], $range('everything'));
        self::assertSame(['yesterday', '2026-11-25', '2026-11-25', null], $range('yesterday'));
        self::assertSame(['week', '2026-11-23', '2026-11-26', null], $range('week'), 'weeks start on Monday');
        self::assertSame(['month', '2026-11-01', '2026-11-26', null], $range('month'));
        self::assertSame(['last_month', '2026-10-01', '2026-10-31', null], $range('last_month'));
        self::assertSame(['custom', '2026-01-01', '2026-03-31', null], $range('custom', '2026-01-01', '2026-03-31'));
        self::assertSame(['today', '2026-11-26', '2026-11-26', 'report.error.dates'], $range('custom', '2026-03-31', '2026-01-01'));
        self::assertSame(['today', '2026-11-26', '2026-11-26', 'report.error.dates'], $range('custom', '2026-02-30', '2026-03-01'));
        self::assertSame(['today', '2026-11-26', '2026-11-26', 'report.error.dates'], $range('custom', ['x'], '2026-03-01'));
        self::assertSame(['custom', '2025-11-26', '2026-11-26', null], $range('custom', '2025-11-26', '2026-11-26'), '366 days is fine');
        self::assertSame(['today', '2026-11-26', '2026-11-26', 'report.error.too_long'], $range('custom', '2025-11-25', '2026-11-26'));
    }
}
