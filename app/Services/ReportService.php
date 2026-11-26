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
     * Totals for one day (by token date). Cancelled bills are listed but left out of every total.
     *
     * @return array{date: string, total: int, by_mode: array<string, int>, bill_count: int,
     *               by_type: array<string, int>, discount_total: int, cancelled: list<array>}
     */
    public function daySummary(DateTimeImmutable $day): array
    {
        $date = $day->format('Y-m-d');

        $byMode = ['cash' => 0, 'upi_counter' => 0, 'upi_online' => 0];
        $stmt = $this->db->prepare(
            "SELECT p.mode, p.amount
             FROM payments p JOIN orders o ON o.id = p.order_id
             WHERE o.token_date = ? AND o.status <> 'cancelled' AND p.status = 'success'"
        );
        $stmt->execute([$date]);
        foreach ($stmt->fetchAll() as $payment) {
            $byMode[$payment['mode']] += paise_from_decimal((string) $payment['amount']);
        }

        $byType = ['counter' => 0, 'takeaway' => 0, 'table' => 0, 'bulk' => 0];
        $discount = 0;
        $billCount = 0;
        $stmt = $this->db->prepare(
            "SELECT order_type, bill_no, discount FROM orders WHERE token_date = ? AND status NOT IN ('cancelled', 'no_show')"
        );
        $stmt->execute([$date]);
        foreach ($stmt->fetchAll() as $order) {
            $byType[$order['order_type']]++;
            $discount += paise_from_decimal((string) $order['discount']);
            if ($order['bill_no'] !== null) {
                $billCount++;
            }
        }

        $stmt = $this->db->prepare(
            "SELECT o.id, o.bill_no, o.token_no, o.total, o.cancel_reason, o.created_at, c.name AS cancelled_by_name
             FROM orders o LEFT JOIN staff c ON c.id = o.cancelled_by
             WHERE o.token_date = ? AND o.status = 'cancelled'
             ORDER BY o.id"
        );
        $stmt->execute([$date]);

        return [
            'date' => $date,
            'total' => array_sum($byMode),
            'by_mode' => $byMode,
            'bill_count' => $billCount,
            'by_type' => $byType,
            'discount_total' => $discount,
            'cancelled' => $stmt->fetchAll(),
        ];
    }
}
