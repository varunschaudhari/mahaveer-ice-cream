<?php

declare(strict_types=1);

namespace App\Services;

use App\Db;
use DateTimeImmutable;
use PDO;

/**
 * The day close (M2) and the date-range reports (M7, SPEC §12). Every report uses one of three
 * bases, the same ones the day close uses, so a one-day report always equals that day's close:
 *
 * - money: payments received in the range, by payment time (a bulk advance counts on the day it
 *   was paid, so cash matches the drawer); refunded payments (cancelled bills) are left out
 * - bills: bill numbers issued in the range (orders by completed_at, table visits by closed_at);
 *   bills cancelled later are left out of counts and amounts and listed separately. Item sales
 *   are the lines of these bills (line totals, before discount / parcel / round-off).
 * - orders placed: orders by token date, not cancelled / no-show
 *
 * Ranges are whole days, both ends included. Money is integer paise.
 */
final class ReportService
{
    public const MAX_DAYS = 366;
    public const PRESETS = ['today', 'yesterday', 'week', 'month', 'last_month', 'custom'];
    public const MODES = ['cash', 'upi_counter', 'upi_online'];
    public const TYPES = ['counter', 'takeaway', 'table', 'bulk'];

    private readonly PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Db::pdo();
    }

    // ---------------------------------------------------------------
    // Date range from the report form
    // ---------------------------------------------------------------

    /**
     * The range for a preset (week starts Monday) or custom from / to (Y-m-d). Bad or too long
     * custom ranges fall back to today with an error key for the page.
     *
     * @return array{preset: string, from: DateTimeImmutable, to: DateTimeImmutable, error: ?string}
     */
    public static function range(mixed $preset, mixed $from, mixed $to, DateTimeImmutable $today): array
    {
        $today = $today->setTime(0, 0);
        $preset = is_string($preset) && in_array($preset, self::PRESETS, true) ? $preset : 'today';
        $result = static fn (string $p, DateTimeImmutable $f, DateTimeImmutable $t, ?string $error = null): array
            => ['preset' => $p, 'from' => $f, 'to' => $t, 'error' => $error];

        switch ($preset) {
            case 'yesterday':
                $day = $today->modify('-1 day');
                return $result($preset, $day, $day);
            case 'week':
                return $result($preset, $today->modify('-' . ((int) $today->format('N') - 1) . ' days'), $today);
            case 'month':
                return $result($preset, $today->modify('first day of this month'), $today);
            case 'last_month':
                return $result($preset, $today->modify('first day of last month'), $today->modify('last day of last month'));
            case 'custom':
                $f = self::parseDate($from, $today);
                $t = self::parseDate($to, $today);
                if ($f === null || $t === null || $f > $t) {
                    return $result('today', $today, $today, 'report.error.dates');
                }
                if ((int) $f->diff($t)->days + 1 > self::MAX_DAYS) {
                    return $result('today', $today, $today, 'report.error.too_long');
                }
                return $result($preset, $f, $t);
            default:
                return $result('today', $today, $today);
        }
    }

    private static function parseDate(mixed $value, DateTimeImmutable $today): ?DateTimeImmutable
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $today->getTimezone());
        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }

    // ---------------------------------------------------------------
    // Day close and range totals
    // ---------------------------------------------------------------

    /**
     * One day's close (SPEC §9 Today tab, §10 day summary). See the class comment for the bases.
     *
     * @return array{date: string, total: int, by_mode: array<string, int>, bill_count: int, bill_total: int,
     *               by_type: array<string, int>, discount_total: int, cancelled: list<array>}
     */
    public function daySummary(DateTimeImmutable $day): array
    {
        return ['date' => $day->format('Y-m-d')] + $this->summary($day, $day);
    }

    /**
     * Totals for a range: money received by mode, bills issued (count, amount, discounts),
     * orders placed by type, cancelled bills.
     *
     * @return array{total: int, by_mode: array<string, int>, bill_count: int, bill_total: int,
     *               by_type: array<string, int>, discount_total: int, cancelled: list<array>}
     */
    public function summary(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $byMode = $this->paymentSplit($from, $to);
        [$sql, $params] = $this->bills($from, $to);
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) AS n, COALESCE(SUM(b.total), 0) AS amount, COALESCE(SUM(b.discount), 0) AS discount
             FROM ({$sql}) b WHERE b.cancelled = 0"
        );
        $stmt->execute($params);
        $bills = $stmt->fetch();

        return [
            'total' => array_sum($byMode),
            'by_mode' => $byMode,
            'bill_count' => (int) $bills['n'],
            'bill_total' => paise_from_decimal((string) $bills['amount']),
            'by_type' => $this->ordersPlaced($from, $to),
            'discount_total' => paise_from_decimal((string) $bills['discount']),
            'cancelled' => $this->cancelledBills($from, $to),
        ];
    }

    /** Money received by payment mode. @return array<string, int> paise per mode (all modes present) */
    public function paymentSplit(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $byMode = array_fill_keys(self::MODES, 0);
        $stmt = $this->db->prepare(
            "SELECT mode, SUM(amount) AS amount FROM payments
             WHERE status = 'success' AND created_at >= ? AND created_at < ? GROUP BY mode"
        );
        $stmt->execute(self::bounds($from, $to));
        foreach ($stmt->fetchAll() as $row) {
            $byMode[$row['mode']] += paise_from_decimal((string) $row['amount']);
        }
        return $byMode;
    }

    /** Orders placed by type, not cancelled / no-show. @return array<string, int> */
    public function ordersPlaced(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $byType = array_fill_keys(self::TYPES, 0);
        $stmt = $this->db->prepare(
            "SELECT order_type, COUNT(*) AS n FROM orders
             WHERE token_date >= ? AND token_date <= ? AND status NOT IN ('cancelled', 'no_show') GROUP BY order_type"
        );
        $stmt->execute([$from->format('Y-m-d'), $to->format('Y-m-d')]);
        foreach ($stmt->fetchAll() as $row) {
            $byType[$row['order_type']] = (int) $row['n'];
        }
        return $byType;
    }

    // ---------------------------------------------------------------
    // Range reports (admin → Reports)
    // ---------------------------------------------------------------

    /**
     * Sales by day: every day of the range (oldest first), also days without sales.
     *
     * @return list<array{date: string, by_mode: array<string, int>, total: int, bills: int, bill_total: int,
     *                    discount: int, cancelled: int}>
     */
    public function salesByDay(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $days = [];
        for ($day = $from->setTime(0, 0); $day <= $to; $day = $day->modify('+1 day')) {
            $days[$day->format('Y-m-d')] = [
                'date' => $day->format('Y-m-d'),
                'by_mode' => array_fill_keys(self::MODES, 0),
                'total' => 0,
                'bills' => 0,
                'bill_total' => 0,
                'discount' => 0,
                'cancelled' => 0,
            ];
        }

        $stmt = $this->db->prepare(
            "SELECT DATE(created_at) AS day, mode, SUM(amount) AS amount FROM payments
             WHERE status = 'success' AND created_at >= ? AND created_at < ? GROUP BY DATE(created_at), mode"
        );
        $stmt->execute(self::bounds($from, $to));
        foreach ($stmt->fetchAll() as $row) {
            $paise = paise_from_decimal((string) $row['amount']);
            $days[$row['day']]['by_mode'][$row['mode']] += $paise;
            $days[$row['day']]['total'] += $paise;
        }

        [$sql, $params] = $this->bills($from, $to);
        $stmt = $this->db->prepare(
            "SELECT DATE(b.billed_at) AS day,
                    SUM(b.cancelled = 0) AS bills,
                    COALESCE(SUM(CASE WHEN b.cancelled = 0 THEN b.total END), 0) AS amount,
                    COALESCE(SUM(CASE WHEN b.cancelled = 0 THEN b.discount END), 0) AS discount,
                    SUM(b.cancelled = 1) AS cancelled
             FROM ({$sql}) b GROUP BY DATE(b.billed_at)"
        );
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $row) {
            $days[$row['day']]['bills'] = (int) $row['bills'];
            $days[$row['day']]['bill_total'] = paise_from_decimal((string) $row['amount']);
            $days[$row['day']]['discount'] = paise_from_decimal((string) $row['discount']);
            $days[$row['day']]['cancelled'] = (int) $row['cancelled'];
        }
        return array_values($days);
    }

    /**
     * Order types: orders placed (as the day close counts them) and bills issued with their amount.
     *
     * @return array<string, array{orders: int, bills: int, amount: int}>
     */
    public function orderTypes(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $types = [];
        foreach ($this->ordersPlaced($from, $to) as $type => $count) {
            $types[$type] = ['orders' => $count, 'bills' => 0, 'amount' => 0];
        }
        [$sql, $params] = $this->bills($from, $to);
        $stmt = $this->db->prepare(
            "SELECT b.type, COUNT(*) AS n, SUM(b.total) AS amount FROM ({$sql}) b WHERE b.cancelled = 0 GROUP BY b.type"
        );
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $row) {
            $types[$row['type']]['bills'] = (int) $row['n'];
            $types[$row['type']]['amount'] = paise_from_decimal((string) $row['amount']);
        }
        return $types;
    }

    /**
     * Item-wise sales per size (variant), in menu order. Names are today's menu names.
     *
     * @return list<array{variant_id: int, item_id: int, name_mr: string, name_en: string, label_mr: string,
     *                    label_en: string, qty: string, amount: int}>
     */
    public function itemSales(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        [$sql, $params] = $this->soldLines($from, $to);
        $stmt = $this->db->prepare(
            "SELECT s.variant_id, s.qty, s.amount, v.label_mr, v.label_en, i.id AS item_id, i.name_mr, i.name_en
             FROM ({$sql}) s
             JOIN item_variants v ON v.id = s.variant_id
             JOIN items i ON i.id = v.item_id
             JOIN categories c ON c.id = i.category_id
             ORDER BY c.sort_order, c.id, i.sort_order, i.id, v.sort_order, v.id"
        );
        $stmt->execute($params);
        return array_map(static fn (array $row): array => [
            'variant_id' => (int) $row['variant_id'],
            'item_id' => (int) $row['item_id'],
            'name_mr' => $row['name_mr'],
            'name_en' => $row['name_en'],
            'label_mr' => $row['label_mr'],
            'label_en' => $row['label_en'],
            'qty' => (string) $row['qty'],
            'amount' => paise_from_decimal((string) $row['amount']),
        ], $stmt->fetchAll());
    }

    /**
     * Top flavours: items with all their sizes together, highest amount first (developer
     * decision: ranked by ₹, quantity shown alongside since Half / Full / 1 kg all count as 1).
     *
     * @return list<array{item_id: int, name_mr: string, name_en: string, qty: string, amount: int}>
     */
    public function topItems(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $items = [];
        foreach ($this->itemSales($from, $to) as $line) {
            $id = $line['item_id'];
            $items[$id] ??= ['item_id' => $id, 'name_mr' => $line['name_mr'], 'name_en' => $line['name_en'], 'qty' => 0, 'amount' => 0];
            $items[$id]['qty'] += decimal_to_hundredths($line['qty']);
            $items[$id]['amount'] += $line['amount'];
        }
        usort($items, static fn (array $a, array $b): int
            => [$b['amount'], $b['qty'], $a['item_id']] <=> [$a['amount'], $a['qty'], $b['item_id']]);
        return array_map(static function (array $item): array {
            $item['qty'] = hundredths_to_decimal($item['qty']);
            return $item;
        }, $items);
    }

    /**
     * Bills per staff member who billed / settled them, most amount first.
     *
     * @return list<array{staff_id: ?int, name: ?string, bills: int, amount: int, discount: int}>
     */
    public function staffBills(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        [$sql, $params] = $this->bills($from, $to);
        $stmt = $this->db->prepare(
            "SELECT x.*, st.name FROM (
                 SELECT b.staff_id, COUNT(*) AS bills, SUM(b.total) AS amount, SUM(b.discount) AS discount
                 FROM ({$sql}) b WHERE b.cancelled = 0 GROUP BY b.staff_id
             ) x LEFT JOIN staff st ON st.id = x.staff_id
             ORDER BY x.amount DESC, x.bills DESC, st.name"
        );
        $stmt->execute($params);
        return array_map(static fn (array $row): array => [
            'staff_id' => $row['staff_id'] === null ? null : (int) $row['staff_id'],
            'name' => $row['name'],
            'bills' => (int) $row['bills'],
            'amount' => paise_from_decimal((string) $row['amount']),
            'discount' => paise_from_decimal((string) $row['discount']),
        ], $stmt->fetchAll());
    }

    /**
     * Bills issued in the range that were cancelled afterwards (orders and table visits), by bill
     * number. Keys: bill_no, type, token_no, table_code, total, cancel_reason, cancelled_by_name, billed_at.
     *
     * @return list<array>
     */
    public function cancelledBills(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        [$sql, $params] = $this->bills($from, $to);
        $stmt = $this->db->prepare(
            "SELECT b.bill_no, b.type, b.token_no, b.table_code, b.total, b.cancel_reason, b.billed_at,
                    st.name AS cancelled_by_name
             FROM ({$sql}) b LEFT JOIN staff st ON st.id = b.cancelled_by
             WHERE b.cancelled = 1 ORDER BY b.bill_no"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    // ---------------------------------------------------------------
    // Shared building blocks
    // ---------------------------------------------------------------

    /** @return array{0: string, 1: string} [from 00:00, day after "to" 00:00) as DATETIME strings */
    private static function bounds(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return [$from->setTime(0, 0)->format('Y-m-d H:i:s'), $to->setTime(0, 0)->modify('+1 day')->format('Y-m-d H:i:s')];
    }

    /**
     * Every bill issued in the range as one row set: orders billed on their own (counter,
     * takeaway, bulk) plus settled table visits. A visit counts as cancelled when none of its
     * orders is left (TableService::cancelBill cancels them all).
     *
     * @return array{0: string, 1: list<string>} SQL for a derived table, and its parameters
     */
    private function bills(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        [$start, $end] = self::bounds($from, $to);
        $sql = "SELECT o.bill_no, o.order_type AS type, o.total, o.discount, o.staff_id, o.completed_at AS billed_at,
                       CASE WHEN o.status = 'cancelled' THEN 1 ELSE 0 END AS cancelled,
                       o.token_no, NULL AS table_code, o.cancel_reason, o.cancelled_by
                FROM orders o
                WHERE o.bill_no IS NOT NULL AND o.completed_at >= ? AND o.completed_at < ?
                UNION ALL
                SELECT s.bill_no, 'table', s.total, s.discount, s.closed_by, s.closed_at,
                       CASE WHEN EXISTS (SELECT 1 FROM orders x WHERE x.table_session_id = s.id AND x.status <> 'cancelled')
                            THEN 0 ELSE 1 END,
                       NULL, t.code,
                       (SELECT x.cancel_reason FROM orders x WHERE x.table_session_id = s.id ORDER BY x.id LIMIT 1),
                       (SELECT x.cancelled_by FROM orders x WHERE x.table_session_id = s.id ORDER BY x.id LIMIT 1)
                FROM table_sessions s JOIN dining_tables t ON t.id = s.table_id
                WHERE s.bill_no IS NOT NULL AND s.closed_at >= ? AND s.closed_at < ?";
        return [$sql, [$start, $end, $start, $end]];
    }

    /**
     * Quantity and amount per variant over the lines of the bills issued in the range, left out:
     * cancelled orders (a cancelled bill, or a table order cancelled before its visit was settled).
     *
     * @return array{0: string, 1: list<string>}
     */
    private function soldLines(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        [$start, $end] = self::bounds($from, $to);
        $sql = "SELECT oi.variant_id, SUM(oi.qty) AS qty, SUM(oi.line_total) AS amount
                FROM order_items oi
                JOIN orders o ON o.id = oi.order_id
                LEFT JOIN table_sessions s ON s.id = o.table_session_id
                WHERE o.status <> 'cancelled'
                  AND ((o.bill_no IS NOT NULL AND o.completed_at >= ? AND o.completed_at < ?)
                    OR (o.order_type = 'table' AND s.bill_no IS NOT NULL AND s.closed_at >= ? AND s.closed_at < ?))
                GROUP BY oi.variant_id";
        return [$sql, [$start, $end, $start, $end]];
    }
}
