<?php

declare(strict_types=1);

namespace App\Services;

use App\Db;
use DateTimeImmutable;
use PDO;

/** M2: the day-close summary. Date-range reports and CSV exports join in M7. */
final class ReportService
{
    private readonly PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Db::pdo();
    }

    /**
     * One day's close (SPEC §9 Today tab, §10 day summary):
     * - money by payment mode = payments received that day (by payment time, so a bulk advance
     *   counts on the day it was paid and the cash total matches the drawer); refunded
     *   payments (cancelled bills) are left out
     * - bills = bill numbers issued that day (orders and table visits), cancelled ones left out
     * - by_type = orders placed that day, not cancelled / no-show
     * - cancelled = bills issued that day and later cancelled
     *
     * @return array{date: string, total: int, by_mode: array<string, int>, bill_count: int,
     *               by_type: array<string, int>, discount_total: int, cancelled: list<array>}
     */
    public function daySummary(DateTimeImmutable $day): array
    {
        $date = $day->format('Y-m-d');
        $range = [$day->setTime(0, 0)->format('Y-m-d H:i:s'), $day->setTime(0, 0)->modify('+1 day')->format('Y-m-d H:i:s')];

        $byMode = ['cash' => 0, 'upi_counter' => 0, 'upi_online' => 0];
        $stmt = $this->db->prepare(
            "SELECT mode, amount FROM payments WHERE status = 'success' AND created_at >= ? AND created_at < ?"
        );
        $stmt->execute($range);
        foreach ($stmt->fetchAll() as $payment) {
            $byMode[$payment['mode']] += paise_from_decimal((string) $payment['amount']);
        }

        // Bills issued today: orders billed on their own + table visits settled today.
        $stmt = $this->db->prepare(
            "SELECT discount FROM orders
             WHERE bill_no IS NOT NULL AND status <> 'cancelled' AND completed_at >= ? AND completed_at < ?"
        );
        $stmt->execute($range);
        $bills = $stmt->fetchAll();
        $stmt = $this->db->prepare(
            "SELECT s.discount FROM table_sessions s
             WHERE s.bill_no IS NOT NULL AND s.closed_at >= ? AND s.closed_at < ?
               AND EXISTS (SELECT 1 FROM orders o WHERE o.table_session_id = s.id AND o.status <> 'cancelled')"
        );
        $stmt->execute($range);
        $bills = array_merge($bills, $stmt->fetchAll());
        $discount = array_sum(array_map(static fn (array $b): int => paise_from_decimal((string) $b['discount']), $bills));

        $byType = ['counter' => 0, 'takeaway' => 0, 'table' => 0, 'bulk' => 0];
        $stmt = $this->db->prepare(
            "SELECT order_type, COUNT(*) AS n FROM orders
             WHERE token_date = ? AND status NOT IN ('cancelled', 'no_show') GROUP BY order_type"
        );
        $stmt->execute([$date]);
        foreach ($stmt->fetchAll() as $row) {
            $byType[$row['order_type']] = (int) $row['n'];
        }

        return [
            'date' => $date,
            'total' => array_sum($byMode),
            'by_mode' => $byMode,
            'bill_count' => count($bills),
            'by_type' => $byType,
            'discount_total' => $discount,
            'cancelled' => $this->cancelledBills($range),
        ];
    }

    /** Bills issued in the range that were cancelled afterwards (orders and table visits). */
    private function cancelledBills(array $range): array
    {
        $stmt = $this->db->prepare(
            "SELECT o.bill_no, o.token_no, o.total, o.cancel_reason, o.completed_at AS billed_at
             FROM orders o
             WHERE o.bill_no IS NOT NULL AND o.status = 'cancelled' AND o.completed_at >= ? AND o.completed_at < ?
             ORDER BY o.bill_no"
        );
        $stmt->execute($range);
        $orders = $stmt->fetchAll();

        $stmt = $this->db->prepare(
            "SELECT s.bill_no, NULL AS token_no, s.total,
                    (SELECT o.cancel_reason FROM orders o WHERE o.table_session_id = s.id ORDER BY o.id LIMIT 1) AS cancel_reason,
                    s.closed_at AS billed_at
             FROM table_sessions s
             WHERE s.bill_no IS NOT NULL AND s.closed_at >= ? AND s.closed_at < ?
               AND NOT EXISTS (SELECT 1 FROM orders o WHERE o.table_session_id = s.id AND o.status <> 'cancelled')
             ORDER BY s.bill_no"
        );
        $stmt->execute($range);

        $all = array_merge($orders, $stmt->fetchAll());
        usort($all, static fn (array $a, array $b): int => (int) $a['bill_no'] <=> (int) $b['bill_no']);
        return $all;
    }
}
