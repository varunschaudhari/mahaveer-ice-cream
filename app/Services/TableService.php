<?php

declare(strict_types=1);

namespace App\Services;

use App\Db;
use App\ValidationException;
use DateTimeImmutable;
use PDO;

/**
 * Dine-in tables (SPEC §7, §9). A "visit" is a table_sessions row: it opens with the table's first
 * order, collects every order placed at that table (from any phone), and closes when staff settle
 * it with ONE bill number for the whole visit (SPEC §4). Table orders themselves never get a bill
 * number and never pay a parcel charge.
 */
final class TableService
{
    public const ALERT_TYPES = ['call_staff', 'request_bill'];
    private const OPEN = ['open', 'bill_requested'];

    private readonly PDO $db;
    private readonly AuditService $audit;
    private readonly SequenceService $sequences;
    private readonly PaymentService $payments;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Db::pdo();
        $this->audit = new AuditService($this->db);
        $this->sequences = new SequenceService($this->db);
        $this->payments = new PaymentService($this->db);
    }

    // ---------------------------------------------------------------
    // Tables
    // ---------------------------------------------------------------

    /** Active table by its QR code; null for unknown, regenerated or inactive codes. */
    public function findByToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            return null;
        }
        $stmt = $this->db->prepare('SELECT * FROM dining_tables WHERE qr_token = ? AND is_active = 1');
        $stmt->execute([$token]);
        return $stmt->fetch() ?: null;
    }

    public function find(int $tableId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM dining_tables WHERE id = ?');
        $stmt->execute([$tableId]);
        return $stmt->fetch() ?: null;
    }

    /** @return list<array> all tables in display order */
    public function tables(): array
    {
        return $this->db->query('SELECT * FROM dining_tables ORDER BY sort_order, id')->fetchAll();
    }

    /**
     * Counter Tables tab: every active table with its open visit (if any) and open alerts.
     * @return list<array{id:int, code:string, area:string, status:string, session_id:?int, total_paise:int,
     *                    first_order_at:?string, minutes:int, unserved:int, alerts:list<array>}>
     */
    public function overview(DateTimeImmutable $now): array
    {
        $tables = $this->db->query('SELECT * FROM dining_tables WHERE is_active = 1 ORDER BY sort_order, id')->fetchAll();
        $sessions = [];
        foreach ($this->db->query(
            "SELECT s.id, s.table_id, s.status, s.opened_at,
                    COALESCE(SUM(CASE WHEN o.status <> 'cancelled' THEN o.total ELSE 0 END), 0) AS running,
                    MIN(o.created_at) AS first_order_at,
                    COALESCE(SUM(CASE WHEN o.status IN ('received', 'preparing') THEN 1 ELSE 0 END), 0) AS unserved
             FROM table_sessions s LEFT JOIN orders o ON o.table_session_id = s.id
             WHERE s.status IN ('open', 'bill_requested')
             GROUP BY s.id, s.table_id, s.status, s.opened_at"
        ) as $row) {
            $sessions[(int) $row['table_id']] = $row;
        }
        $alerts = [];
        foreach ($this->openAlerts() as $alert) {
            $alerts[(int) $alert['table_id']][] = $alert;
        }

        return array_map(static function (array $table) use ($sessions, $alerts, $now): array {
            $session = $sessions[(int) $table['id']] ?? null;
            $since = $session['first_order_at'] ?? $session['opened_at'] ?? null;
            return [
                'id' => (int) $table['id'],
                'code' => $table['code'],
                'area' => $table['area'],
                'status' => $session === null ? 'free' : ($session['status'] === 'bill_requested' ? 'bill_requested' : 'occupied'),
                'session_id' => $session === null ? null : (int) $session['id'],
                'total_paise' => $session === null ? 0 : paise_from_decimal((string) $session['running']),
                'first_order_at' => $since,
                'minutes' => $since === null ? 0 : max(0, intdiv($now->getTimestamp() - strtotime($since), 60)),
                'unserved' => $session === null ? 0 : (int) $session['unserved'],
                'alerts' => $alerts[(int) $table['id']] ?? [],
            ];
        }, $tables);
    }

    public function setActive(int $tableId, bool $active, int $staffId): void
    {
        $table = $this->find($tableId) ?? throw ValidationException::one('_', 'table.error.not_found');
        $this->db->prepare('UPDATE dining_tables SET is_active = ? WHERE id = ?')->execute([$active ? 1 : 0, $tableId]);
        $this->audit->log($staffId, $active ? 'table_activate' : 'table_deactivate', 'table', $tableId, ['table' => $table['code']]);
    }

    /** New random QR code: the old sticker stops working at once (SPEC §7.7). */
    public function regenerateToken(int $tableId, int $staffId): string
    {
        $table = $this->find($tableId) ?? throw ValidationException::one('_', 'table.error.not_found');
        $token = bin2hex(random_bytes(16));
        $this->db->prepare('UPDATE dining_tables SET qr_token = ? WHERE id = ?')->execute([$token, $tableId]);
        $this->audit->log($staffId, 'table_qr_regenerate', 'table', $tableId, ['table' => $table['code']]);
        return $token;
    }

    // ---------------------------------------------------------------
    // Visits (table_sessions)
    // ---------------------------------------------------------------

    /** The table's open visit, or null when the table is free. */
    public function openSession(int $tableId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM table_sessions WHERE table_id = ? AND status IN ('open', 'bill_requested') ORDER BY id DESC LIMIT 1");
        $stmt->execute([$tableId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * The open visit for a new order, opening one if the table is free. Call inside the order's
     * transaction: the table row is locked so two phones ordering at once share one visit.
     */
    public function sessionForNewOrder(int $tableId, ?int $customerId, DateTimeImmutable $now): array
    {
        $this->db->prepare('SELECT id FROM dining_tables WHERE id = ? FOR UPDATE')->execute([$tableId]);
        $session = $this->openSession($tableId);
        if ($session !== null) {
            return $session;
        }
        $this->db->prepare(
            "INSERT INTO table_sessions (table_id, customer_id, session_key, status, opened_at) VALUES (?, ?, ?, 'open', ?)"
        )->execute([$tableId, $customerId, bin2hex(random_bytes(16)), $now->format('Y-m-d H:i:s')]);
        return $this->findSession((int) $this->db->lastInsertId());
    }

    public function findSession(int $sessionId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT s.*, t.code AS table_code, t.area AS table_area, c.name AS closed_by_name
             FROM table_sessions s
             JOIN dining_tables t ON t.id = s.table_id
             LEFT JOIN staff c ON c.id = s.closed_by
             WHERE s.id = ?'
        );
        $stmt->execute([$sessionId]);
        return $stmt->fetch() ?: null;
    }

    /** The visit's orders (oldest first) with their lines and who ordered. */
    public function sessionOrders(int $sessionId): array
    {
        $stmt = $this->db->prepare(
            'SELECT o.*, cu.name AS customer_name, cu.mobile AS customer_mobile
             FROM orders o LEFT JOIN customers cu ON cu.id = o.customer_id
             WHERE o.table_session_id = ? ORDER BY o.id'
        );
        $stmt->execute([$sessionId]);
        $orders = $stmt->fetchAll();
        $lines = $this->db->prepare('SELECT * FROM order_items WHERE order_id = ? ORDER BY id');
        foreach ($orders as $i => $order) {
            $lines->execute([$order['id']]);
            $orders[$i]['items'] = $lines->fetchAll();
        }
        return $orders;
    }

    /** Running total of the visit (cancelled orders left out), in paise. */
    public function runningTotal(int $sessionId): int
    {
        $stmt = $this->db->prepare("SELECT COALESCE(SUM(total), 0) FROM orders WHERE table_session_id = ? AND status <> 'cancelled'");
        $stmt->execute([$sessionId]);
        return paise_from_decimal((string) $stmt->fetchColumn());
    }

    /**
     * The combined bill's lines: the same item at the same price from different orders becomes
     * one line (2 + 1 Badam Shake → 3 Badam Shake).
     * @return list<array{item_name: string, unit_price: string, qty: string, line_total: string}>
     */
    public function combinedLines(int $sessionId): array
    {
        $stmt = $this->db->prepare(
            "SELECT oi.item_name, oi.unit_price, SUM(oi.qty) AS qty, SUM(oi.line_total) AS line_total
             FROM order_items oi JOIN orders o ON o.id = oi.order_id
             WHERE o.table_session_id = ? AND o.status <> 'cancelled'
             GROUP BY oi.variant_id, oi.item_name, oi.unit_price
             ORDER BY MIN(oi.id)"
        );
        $stmt->execute([$sessionId]);
        return $stmt->fetchAll();
    }

    /** Payments of a settled visit. */
    public function sessionPayments(int $sessionId): array
    {
        $stmt = $this->db->prepare('SELECT mode, amount, status FROM payments WHERE table_session_id = ? ORDER BY id');
        $stmt->execute([$sessionId]);
        return $stmt->fetchAll();
    }

    // ---------------------------------------------------------------
    // Customer buttons: Call staff / Request bill (SPEC §7.5)
    // ---------------------------------------------------------------

    /** Raises an alert for the counter. One per 30 s per table; Request bill needs an open visit. */
    public function raiseAlert(int $tableId, string $type, DateTimeImmutable $now): void
    {
        if (!in_array($type, self::ALERT_TYPES, true)) {
            throw ValidationException::one('_', 'error.generic');
        }
        if ($this->alertedWithin($tableId, 30, $now)) {
            throw ValidationException::one('_', 'table.error.wait');
        }
        $session = $this->openSession($tableId);
        if ($type === 'request_bill' && $session === null) {
            throw ValidationException::one('_', 'table.error.no_bill');
        }

        Db::transaction(function () use ($tableId, $type, $session, $now): void {
            // A second tap of the same button while it is still open does not add another alert.
            $stmt = $this->db->prepare("SELECT id FROM table_alerts WHERE table_id = ? AND type = ? AND status = 'open'");
            $stmt->execute([$tableId, $type]);
            if ($stmt->fetchColumn() === false) {
                $this->db->prepare(
                    "INSERT INTO table_alerts (table_id, session_id, type, status, created_at) VALUES (?, ?, ?, 'open', ?)"
                )->execute([$tableId, $session['id'] ?? null, $type, $now->format('Y-m-d H:i:s')]);
            }
            if ($type === 'request_bill') {
                $this->db->prepare("UPDATE table_sessions SET status = 'bill_requested' WHERE id = ?")->execute([$session['id']]);
            }
        });
    }

    /** ARCHITECTURE §6: at most one alert per table every 30 seconds. */
    private function alertedWithin(int $tableId, int $seconds, DateTimeImmutable $now): bool
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM table_alerts WHERE table_id = ? AND created_at > ?');
        $stmt->execute([$tableId, $now->modify("-{$seconds} seconds")->format('Y-m-d H:i:s')]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /** @return list<array> open alerts, oldest first, with the table code */
    public function openAlerts(): array
    {
        return $this->db->query(
            "SELECT a.*, t.code AS table_code FROM table_alerts a JOIN dining_tables t ON t.id = a.table_id
             WHERE a.status = 'open' ORDER BY a.id"
        )->fetchAll();
    }

    public function dismissAlert(int $alertId, int $staffId, ?DateTimeImmutable $now = null): void
    {
        $this->db->prepare("UPDATE table_alerts SET status = 'done', done_by = ?, done_at = ? WHERE id = ? AND status = 'open'")
            ->execute([$staffId, ($now ?? now())->format('Y-m-d H:i:s'), $alertId]);
    }

    // ---------------------------------------------------------------
    // Counter actions on a visit
    // ---------------------------------------------------------------

    /** Customers changed tables: the order moves to the other table's visit (opened if needed). */
    public function moveOrder(int $orderId, int $toTableId, int $staffId, ?DateTimeImmutable $now = null): void
    {
        $now ??= now();
        Db::transaction(function () use ($orderId, $toTableId, $staffId, $now): void {
            $stmt = $this->db->prepare(
                "SELECT o.*, s.table_id FROM orders o JOIN table_sessions s ON s.id = o.table_session_id
                 WHERE o.id = ? AND o.order_type = 'table' FOR UPDATE"
            );
            $stmt->execute([$orderId]);
            $order = $stmt->fetch() ?: throw ValidationException::one('_', 'table.error.not_found');
            if (in_array($order['status'], ['completed', 'cancelled'], true)) {
                throw ValidationException::one('_', 'order.error.wrong_status');
            }
            $target = $this->find($toTableId);
            if ($target === null || !$target['is_active'] || (int) $order['table_id'] === $toTableId) {
                throw ValidationException::one('_', 'table.error.move_target');
            }

            $to = $this->sessionForNewOrder($toTableId, $order['customer_id'] !== null ? (int) $order['customer_id'] : null, $now);
            $this->db->prepare('UPDATE orders SET table_session_id = ? WHERE id = ?')->execute([$to['id'], $orderId]);
            $this->closeIfEmpty((int) $order['table_session_id'], $staffId, $now);
            $this->audit->log($staffId, 'table_move', 'order', $orderId, [
                'token' => $order['token_no'],
                'from' => $this->find((int) $order['table_id'])['code'],
                'to' => $target['code'],
            ]);
        });
    }

    /**
     * Settles a visit: one combined bill with one bill number, optional owner-approved discount,
     * payment, every order completed, alerts cleared, the table free again.
     *
     * @param ?array $discount ['amount' => paise, 'reason' => …, 'owner_id' => …] approved by owner PIN
     */
    public function settle(
        int $sessionId,
        string $method,
        int $cashPaise,
        ?array $discount,
        int $staffId,
        ?int $expectedTotal = null,
        ?DateTimeImmutable $now = null,
    ): array {
        $now ??= now();
        Db::transaction(function () use ($sessionId, $method, $cashPaise, $discount, $staffId, $expectedTotal, $now): void {
            $stmt = $this->db->prepare('SELECT * FROM table_sessions WHERE id = ? FOR UPDATE');
            $stmt->execute([$sessionId]);
            $session = $stmt->fetch() ?: throw ValidationException::one('_', 'table.error.not_found');
            if (!in_array($session['status'], self::OPEN, true)) {
                throw ValidationException::one('_', 'table.error.already_settled');
            }

            $subtotal = $this->runningTotal($sessionId);
            if ($subtotal === 0) {
                throw ValidationException::one('_', 'table.error.nothing_to_bill');
            }
            $discountPaise = $discount !== null ? (int) $discount['amount'] : 0;
            if ($discountPaise > $subtotal) {
                throw ValidationException::one('_', 'discount.error.too_big');
            }
            $total = PricingService::roundToRupee($subtotal - $discountPaise);
            if ($expectedTotal !== null && $expectedTotal !== $total) {
                throw ValidationException::one('_', 'billing.error.price_changed');
            }

            $payments = PaymentService::split($method, $total, $cashPaise);
            $billNo = $this->sequences->nextBillNo();
            $at = $now->format('Y-m-d H:i:s');

            $this->db->prepare(
                "UPDATE table_sessions SET status = 'closed', bill_no = ?, subtotal = ?, discount = ?, total = ?,
                        closed_at = ?, closed_by = ? WHERE id = ?"
            )->execute([$billNo, decimal_from_paise($subtotal), decimal_from_paise($discountPaise), decimal_from_paise($total), $at, $staffId, $sessionId]);
            $this->db->prepare(
                "UPDATE orders SET status = 'completed', completed_at = ? WHERE table_session_id = ? AND status <> 'cancelled'"
            )->execute([$at, $sessionId]);
            $this->db->prepare(
                "UPDATE table_alerts SET status = 'done', done_by = ?, done_at = ? WHERE table_id = ? AND status = 'open'"
            )->execute([$staffId, $at, $session['table_id']]);
            $this->payments->record(null, $payments, $staffId, $now, $sessionId);

            if ($discountPaise > 0) {
                $this->audit->log((int) $discount['owner_id'], 'discount', 'table_session', $sessionId, [
                    'bill_no' => $billNo,
                    'amount' => decimal_from_paise($discountPaise),
                    'reason' => (string) $discount['reason'],
                    'billed_by' => $staffId,
                ]);
            }
        });
        return $this->findSession($sessionId);
    }

    /** Closes a visit that has nothing to bill (every order cancelled or moved away). */
    public function closeIfEmpty(int $sessionId, ?int $staffId, ?DateTimeImmutable $now = null): bool
    {
        $session = $this->findSession($sessionId);
        if ($session === null || !in_array($session['status'], self::OPEN, true)) {
            return false;
        }
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM orders WHERE table_session_id = ? AND status <> 'cancelled'");
        $stmt->execute([$sessionId]);
        if ((int) $stmt->fetchColumn() > 0) {
            return false;
        }
        $at = ($now ?? now())->format('Y-m-d H:i:s');
        $this->db->prepare("UPDATE table_sessions SET status = 'closed', closed_at = ?, closed_by = ? WHERE id = ?")
            ->execute([$at, $staffId, $sessionId]);
        $this->db->prepare("UPDATE table_alerts SET status = 'done', done_by = ?, done_at = ? WHERE table_id = ? AND status = 'open'")
            ->execute([$staffId, $at, $session['table_id']]);
        return true;
    }

    /**
     * Cancels a settled table bill (Today tab, owner PIN, today only). The visit keeps its bill
     * number; its orders become cancelled and its payments refunded, so day totals leave it out.
     */
    public function cancelBill(int $sessionId, string $reason, int $ownerId, int $staffId, ?DateTimeImmutable $now = null): void
    {
        $now ??= now();
        $reason = trim($reason);
        if (!is_valid_name($reason, 200)) {
            throw ValidationException::one('reason', 'cancel.error.reason');
        }
        Db::transaction(function () use ($sessionId, $reason, $ownerId, $staffId, $now): void {
            $stmt = $this->db->prepare('SELECT * FROM table_sessions WHERE id = ? FOR UPDATE');
            $stmt->execute([$sessionId]);
            $session = $stmt->fetch() ?: throw ValidationException::one('_', 'cancel.error.not_found');
            if ($session['bill_no'] === null) {
                throw ValidationException::one('_', 'cancel.error.not_found');
            }
            if (substr((string) $session['closed_at'], 0, 10) !== $now->format('Y-m-d')) {
                throw ValidationException::one('_', 'cancel.error.not_today');
            }
            $stmt = $this->db->prepare(
                "UPDATE orders SET status = 'cancelled', cancel_reason = ?, cancelled_by = ? WHERE table_session_id = ? AND status <> 'cancelled'"
            );
            $stmt->execute([$reason, $ownerId, $sessionId]);
            if ($stmt->rowCount() === 0) {
                throw ValidationException::one('_', 'cancel.error.already');
            }
            $this->db->prepare("UPDATE payments SET status = 'refunded' WHERE table_session_id = ? AND status = 'success'")
                ->execute([$sessionId]);
            $this->audit->log($ownerId, 'cancel_table_bill', 'table_session', $sessionId, [
                'bill_no' => $session['bill_no'],
                'total' => $session['total'],
                'reason' => $reason,
                'requested_by' => $staffId,
            ]);
        });
    }

    /**
     * Table visits settled on $day (Today tab rows), newest first.
     * @return list<array>
     */
    public function settledOn(DateTimeImmutable $day): array
    {
        $stmt = $this->db->prepare(
            "SELECT s.*, t.code AS table_code, st.name AS staff_name,
                    NOT EXISTS (SELECT 1 FROM orders o WHERE o.table_session_id = s.id AND o.status <> 'cancelled') AS is_cancelled,
                    (SELECT o.cancel_reason FROM orders o WHERE o.table_session_id = s.id ORDER BY o.id LIMIT 1) AS cancel_reason
             FROM table_sessions s
             JOIN dining_tables t ON t.id = s.table_id
             LEFT JOIN staff st ON st.id = s.closed_by
             WHERE s.bill_no IS NOT NULL AND s.closed_at >= ? AND s.closed_at < ?
             ORDER BY s.closed_at DESC"
        );
        $stmt->execute([$day->setTime(0, 0)->format('Y-m-d H:i:s'), $day->setTime(0, 0)->modify('+1 day')->format('Y-m-d H:i:s')]);
        $sessions = $stmt->fetchAll();
        foreach ($sessions as $i => $session) {
            $sessions[$i]['payments'] = $this->sessionPayments((int) $session['id']);
        }
        return $sessions;
    }
}
