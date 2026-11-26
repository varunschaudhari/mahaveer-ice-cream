<?php

declare(strict_types=1);

namespace App\Services;

use App\Db;
use App\Settings;
use App\ValidationException;
use DateTimeImmutable;
use DomainException;
use PDO;
use PDOException;

/**
 * Orders (SPEC §3). Counter bills are saved straight as completed. Takeaway orders go
 * received → (accept) preparing → (ready) ready → (pickup + payment) completed, or cancelled /
 * no_show. Table and bulk orders join in M4–M5.
 */
final class OrderService
{
    /** Text saved as cancel_reason when a customer cancels from the status page. */
    public const CUSTOMER_CANCEL_REASON = 'ग्राहकाने रद्द केले / Cancelled by customer';
    /** Statuses of an order that still needs the shop's attention. */
    public const ACTIVE_STATUSES = ['received', 'preparing', 'ready'];

    private readonly PDO $db;
    private readonly SequenceService $sequences;
    private readonly CartService $cart;
    private readonly PaymentService $payments;
    private readonly AuditService $audit;
    private readonly CustomerService $customers;
    private readonly SlotService $slots;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Db::pdo();
        $this->sequences = new SequenceService($this->db);
        $this->cart = new CartService($this->db);
        $this->payments = new PaymentService($this->db);
        $this->audit = new AuditService($this->db);
        $this->customers = new CustomerService($this->db);
        $this->slots = new SlotService($this->db);
    }

    // ---------------------------------------------------------------
    // Takeaway (customer's phone, SPEC §6)
    // ---------------------------------------------------------------

    /**
     * Places a takeaway order: status received, today's next token, no bill number until pickup.
     *
     * $input: order_key (32 hex, made by the phone; a repeat returns the saved order), name,
     * mobile, slot ('19:30'), note, lines [['variant_id' => …, 'qty' => …]].
     *
     * @return array the saved order (see find()) plus 'duplicate' => bool
     */
    public function placeTakeaway(array $input, ?DateTimeImmutable $now = null): array
    {
        $now ??= now();
        $key = (string) ($input['order_key'] ?? '');
        if (!preg_match('/^[a-f0-9]{32}$/', $key)) {
            throw ValidationException::one('_', 'error.generic');
        }
        $existing = $this->findByKey($key);
        if ($existing !== null) {
            return $existing + ['duplicate' => true];
        }

        [$name, $mobile, $note] = $this->customerFields($input);
        if ($this->customers->isBlocked($mobile)) {
            throw ValidationException::one('_', 'checkout.error.blocked');
        }

        $lines = $this->cart->validate(is_array($input['lines'] ?? null) ? $input['lines'] : [], 'online');

        $today = $this->slots->today($now);
        if (!$today['open']) {
            throw ValidationException::one('_', 'checkout.error.closed');
        }
        $slot = null;
        foreach ($today['slots'] as $candidate) {
            if ($candidate['start'] === (string) ($input['slot'] ?? '')) {
                $slot = $candidate;
            }
        }
        if ($slot === null) {
            throw ValidationException::one('slot', 'checkout.error.slot');
        }
        if ($slot['full']) {
            throw ValidationException::one('slot', 'checkout.error.slot_full');
        }

        $totals = PricingService::totals(
            'takeaway',
            array_map(static fn (array $l): array => ['unit_paise' => $l['unit_paise'], 'qty' => $l['qty']], $lines),
            true,
            Settings::paise('parcel_charge'),
        );

        try {
            $orderId = Db::transaction(function () use ($key, $name, $mobile, $note, $lines, $slot, $totals, $now): int {
                // The token lock serialises all orders of the day, so this count cannot race.
                $token = $this->sequences->nextToken($now);
                if ($this->slots->countAt($slot['at']) >= Settings::int('slot_capacity', 10)) {
                    throw ValidationException::one('slot', 'checkout.error.slot_full');
                }
                $customerId = $this->customers->recordOrder($mobile, $name, $now);

                $this->db->prepare(
                    "INSERT INTO orders (order_key, token_no, token_date, order_type, service_type, customer_id, status,
                                         pickup_at, subtotal, parcel_charge, discount, total, amount_paid, payment_pref,
                                         note, created_at)
                     VALUES (?, ?, ?, 'takeaway', 'parcel', ?, 'received', ?, ?, ?, 0, ?, 0, 'counter', ?, ?)"
                )->execute([
                    $key, $token, $now->format('Y-m-d'), $customerId, $slot['at'],
                    decimal_from_paise($totals['subtotal']), decimal_from_paise($totals['parcel_charge']),
                    decimal_from_paise($totals['total']), $note === '' ? null : $note, $now->format('Y-m-d H:i:s'),
                ]);
                $orderId = (int) $this->db->lastInsertId();
                $this->insertLines($orderId, $lines, $totals['lines']);
                return $orderId;
            });
        } catch (PDOException $e) {
            $existing = ($e->errorInfo[1] ?? null) === 1062 ? $this->findByKey($key) : null;
            if ($existing === null) {
                throw $e;
            }
            return $existing + ['duplicate' => true];
        }

        return $this->find($orderId) + ['duplicate' => false];
    }

    /** Staff accepts a new takeaway or table order: received → preparing (table orders print a KOT). */
    public function accept(int $orderId, int $staffId, ?DateTimeImmutable $now = null): void
    {
        $at = ($now ?? now())->format('Y-m-d H:i:s');
        $this->transition($orderId, ['received'], 'preparing', ['takeaway', 'table'], ['accepted_at' => $at, 'staff_id' => $staffId]);
    }

    /** Table order brought to the table: preparing → served (completed when the table settles). */
    public function markServed(int $orderId, int $staffId, ?DateTimeImmutable $now = null): void
    {
        $this->transition($orderId, ['preparing'], 'served', ['table'], ['ready_at' => ($now ?? now())->format('Y-m-d H:i:s')]);
    }

    /**
     * Customer orders at a table (SPEC §7): no slot, no parcel charge, added to the table's
     * open visit (opened by the first order; any phone at the table joins it).
     *
     * $input: table_token, order_key, name, mobile, note, lines.
     */
    public function placeTableOrder(array $input, ?DateTimeImmutable $now = null): array
    {
        $now ??= now();
        $tables = new TableService($this->db);
        $table = $tables->findByToken((string) ($input['table_token'] ?? ''))
            ?? throw ValidationException::one('_', 'table.error.invalid');

        $key = (string) ($input['order_key'] ?? '');
        if (!preg_match('/^[a-f0-9]{32}$/', $key)) {
            throw ValidationException::one('_', 'error.generic');
        }
        $existing = $this->findByKey($key);
        if ($existing !== null) {
            return $existing + ['duplicate' => true];
        }

        [$name, $mobile, $note] = $this->customerFields($input);
        $lines = $this->cart->validate(is_array($input['lines'] ?? null) ? $input['lines'] : [], 'online');
        $totals = PricingService::totals(
            'table',
            array_map(static fn (array $l): array => ['unit_paise' => $l['unit_paise'], 'qty' => $l['qty']], $lines),
            false,
            0,
        );

        try {
            $orderId = Db::transaction(function () use ($tables, $table, $key, $name, $mobile, $note, $lines, $totals, $now): int {
                $token = $this->sequences->nextToken($now);
                $customerId = $this->customers->recordOrder($mobile, $name, $now);
                $session = $tables->sessionForNewOrder((int) $table['id'], $customerId, $now);

                $this->db->prepare(
                    "INSERT INTO orders (order_key, token_no, token_date, order_type, service_type, customer_id, table_session_id,
                                         status, subtotal, parcel_charge, discount, total, amount_paid, payment_pref, note, created_at)
                     VALUES (?, ?, ?, 'table', 'dine_in', ?, ?, 'received', ?, 0, 0, ?, 0, 'counter', ?, ?)"
                )->execute([
                    $key, $token, $now->format('Y-m-d'), $customerId, $session['id'],
                    decimal_from_paise($totals['subtotal']), decimal_from_paise($totals['total']),
                    $note === '' ? null : $note, $now->format('Y-m-d H:i:s'),
                ]);
                $orderId = (int) $this->db->lastInsertId();
                $this->insertLines($orderId, $lines, $totals['lines']);
                return $orderId;
            });
        } catch (PDOException $e) {
            $existing = ($e->errorInfo[1] ?? null) === 1062 ? $this->findByKey($key) : null;
            if ($existing === null) {
                throw $e;
            }
            return $existing + ['duplicate' => true];
        }
        return $this->find($orderId) + ['duplicate' => false];
    }

    /** @return array{0: string, 1: string, 2: string} name, mobile, note */
    private function customerFields(array $input): array
    {
        $contact = CustomerService::validateContact($input);
        return [$contact['name'], $contact['mobile'], $contact['note']];
    }

    /** preparing → ready (takeaway: the status page shows "Ready"; bulk: ready for pickup). */
    public function markReady(int $orderId, int $staffId, ?DateTimeImmutable $now = null): void
    {
        $this->transition($orderId, ['preparing'], 'ready', ['takeaway', 'bulk'], ['ready_at' => ($now ?? now())->format('Y-m-d H:i:s')]);
    }

    /**
     * Customer collects and pays: preparing|ready → completed, with the bill number assigned now
     * (SPEC §4) and the payment recorded. Staff may skip "Ready".
     */
    public function pickup(int $orderId, string $method, int $cashPaise, int $staffId, ?DateTimeImmutable $now = null): array
    {
        $now ??= now();
        Db::transaction(function () use ($orderId, $method, $cashPaise, $staffId, $now): void {
            $order = $this->lockOrder($orderId);
            if ($order['order_type'] !== 'takeaway' || !in_array($order['status'], ['preparing', 'ready'], true)) {
                throw ValidationException::one('_', 'order.error.wrong_status');
            }
            $total = paise_from_decimal((string) $order['total']);
            $payments = PaymentService::split($method, $total, $cashPaise);
            $billNo = $this->sequences->nextBillNo();
            $this->db->prepare(
                "UPDATE orders SET status = 'completed', bill_no = ?, amount_paid = ?, completed_at = ?, staff_id = ? WHERE id = ?"
            )->execute([$billNo, $order['total'], $now->format('Y-m-d H:i:s'), $staffId, $orderId]);
            $this->payments->record($orderId, $payments, $staffId, $now);
        });
        return $this->find($orderId);
    }

    /** Customer cancels from the status page: only their own order (secret key), only before it is accepted. */
    public function cancelByCustomer(int $orderId, string $key, ?DateTimeImmutable $now = null): void
    {
        $order = $this->findForCustomer($orderId, $key) ?? throw ValidationException::one('_', 'status.not_found');
        if ($order['order_type'] === 'bulk' && $order['status'] === 'pending_approval') {
            (new BulkService($this->db))->cancel($orderId, self::CUSTOMER_CANCEL_REASON, null, $now);
            return;
        }
        if ($order['order_type'] !== 'takeaway' || $order['status'] !== 'received') {
            throw ValidationException::one('_', 'order.error.cannot_cancel');
        }
        $this->cancel($orderId, self::CUSTOMER_CANCEL_REASON, null, null, $now);
    }

    /**
     * Takeaway orders not collected noshow_after_mins after their slot ended become no_show and
     * count against the customer's number (SPEC §3, run by cron/auto_noshow.php every 5 min).
     *
     * @return int how many orders were marked
     */
    public function markNoShows(?DateTimeImmutable $now = null): int
    {
        $now ??= now();
        $minutes = max(5, Settings::int('slot_mins', 15)) + Settings::int('noshow_after_mins', 30);
        $cutoff = $now->modify("-{$minutes} minutes")->format('Y-m-d H:i:s');

        return Db::transaction(function () use ($cutoff): int {
            $stmt = $this->db->prepare(
                "SELECT id, customer_id, token_no, token_date FROM orders
                 WHERE order_type = 'takeaway' AND status IN ('received', 'preparing', 'ready') AND pickup_at <= ?
                 FOR UPDATE"
            );
            $stmt->execute([$cutoff]);
            $orders = $stmt->fetchAll();
            foreach ($orders as $order) {
                $this->db->prepare("UPDATE orders SET status = 'no_show' WHERE id = ?")->execute([$order['id']]);
                $blocked = $order['customer_id'] !== null && $this->customers->recordNoShow((int) $order['customer_id']);
                $this->audit->log(null, 'no_show', 'order', (int) $order['id'], [
                    'token' => $order['token_no'],
                    'date' => $order['token_date'],
                    'blocked' => $blocked,
                ]);
            }
            return count($orders);
        });
    }

    /**
     * Online orders still needing the shop today (Online tab), oldest first, with their lines.
     * @return list<array>
     */
    public function activeOnline(DateTimeImmutable $day): array
    {
        $stmt = $this->db->prepare(
            "SELECT o.id, o.token_no, o.order_type, o.status, o.pickup_at, o.total, o.note, o.created_at,
                    cu.name AS customer_name, cu.mobile AS customer_mobile, t.code AS table_code
             FROM orders o
             LEFT JOIN customers cu ON cu.id = o.customer_id
             LEFT JOIN table_sessions ts ON ts.id = o.table_session_id
             LEFT JOIN dining_tables t ON t.id = ts.table_id
             WHERE o.token_date = ? AND o.order_type IN ('takeaway', 'table') AND o.status IN ('received', 'preparing', 'ready')
             ORDER BY o.id"
        );
        $stmt->execute([$day->format('Y-m-d')]);
        $orders = $stmt->fetchAll();
        if ($orders === []) {
            return [];
        }

        $ids = array_column($orders, 'id');
        $stmt = $this->db->prepare(
            'SELECT order_id, item_name, qty FROM order_items WHERE order_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id'
        );
        $stmt->execute($ids);
        $lines = [];
        foreach ($stmt->fetchAll() as $line) {
            $lines[(int) $line['order_id']][] = $line;
        }
        return array_map(static function (array $order) use ($lines): array {
            $order['items'] = $lines[(int) $order['id']] ?? [];
            return $order;
        }, $orders);
    }

    /**
     * For the counter's 5-second poll: online/table orders waiting to be accepted and open table
     * alerts, with the newest id of each (the screen beeps until they are acknowledged).
     *
     * @return array{new_count: int, latest_new_id: int, alert_count: int, latest_alert_id: int}
     */
    public function newOnlineSummary(DateTimeImmutable $day): array
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) AS n, COALESCE(MAX(id), 0) AS latest FROM orders
             WHERE token_date = ? AND order_type IN ('takeaway', 'table') AND status = 'received'"
        );
        $stmt->execute([$day->format('Y-m-d')]);
        $orders = $stmt->fetch();
        $alerts = $this->db->query("SELECT COUNT(*) AS n, COALESCE(MAX(id), 0) AS latest FROM table_alerts WHERE status = 'open'")->fetch();
        return [
            'new_count' => (int) $orders['n'],
            'latest_new_id' => (int) $orders['latest'],
            'alert_count' => (int) $alerts['n'],
            'latest_alert_id' => (int) $alerts['latest'],
        ];
    }

    /**
     * Ids of table orders accepted in the last 10 minutes. The counter prints their kitchen ticket
     * unless it already has (decision 2026-11-26: accepting on the owner's phone still prints a
     * KOT at the counter). The counter remembers what it printed, so nothing prints twice.
     *
     * @return list<int>
     */
    public function recentlyAcceptedTableOrders(DateTimeImmutable $now): array
    {
        $stmt = $this->db->prepare(
            "SELECT id FROM orders
             WHERE order_type = 'table' AND accepted_at >= ? AND status IN ('preparing', 'served')
             ORDER BY id"
        );
        $stmt->execute([$now->modify('-10 minutes')->format('Y-m-d H:i:s')]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** The order only if $key is its secret order_key (customer status page). */
    public function findForCustomer(int $id, string $key): ?array
    {
        $order = $this->find($id);
        return $order !== null && $key !== '' && hash_equals($order['order_key'], $key) ? $order : null;
    }

    /** Moves an order between statuses; refuses if someone else already moved it. */
    private function transition(int $orderId, array $from, string $to, array $types, array $set): void
    {
        Db::transaction(function () use ($orderId, $from, $to, $types, $set): void {
            $order = $this->lockOrder($orderId);
            if (!in_array($order['order_type'], $types, true) || !in_array($order['status'], $from, true)) {
                throw ValidationException::one('_', 'order.error.wrong_status');
            }
            $columns = array_keys($set);
            $assignments = implode('', array_map(static fn (string $c): string => ", {$c} = ?", $columns));
            $this->db->prepare("UPDATE orders SET status = ?{$assignments} WHERE id = ?")
                ->execute(array_merge([$to], array_values($set), [$orderId]));
        });
    }

    private function lockOrder(int $orderId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM orders WHERE id = ? FOR UPDATE');
        $stmt->execute([$orderId]);
        return $stmt->fetch() ?: throw ValidationException::one('_', 'cancel.error.not_found');
    }

    private function insertLines(int $orderId, array $lines, array $lineTotals): void
    {
        $insert = $this->db->prepare(
            'INSERT INTO order_items (order_id, variant_id, item_name, unit_price, qty, line_total) VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ($lines as $i => $line) {
            $insert->execute([
                $orderId, $line['variant_id'], $line['item_name'], decimal_from_paise($line['unit_paise']),
                $line['qty'], decimal_from_paise($lineTotals[$i]),
            ]);
        }
    }

    // ---------------------------------------------------------------
    // Counter bills (SPEC §9)
    // ---------------------------------------------------------------

    /**
     * Saves a counter bill in one transaction: checked cart → prices → token + bill number →
     * order, lines, payments.
     *
     * $input:
     *  - order_key: 32 hex chars made by the counter screen for this bill. If the same key arrives
     *    again (staff tapped Save again after a network drop) the already-saved bill is returned.
     *  - lines: [['variant_id' => 21, 'qty' => 2], …]
     *  - service_type: 'dine_in' | 'parcel'
     *  - payment_method: 'cash' | 'upi' | 'mixed', cash_paise: cash part of a mixed payment
     *  - expected_total: total the screen showed (paise); refused if prices changed meanwhile
     *  - discount: ['amount' => paise, 'reason' => …, 'owner_id' => …] already approved by an owner PIN
     *
     * @return array the saved order (see find()) plus 'duplicate' => bool
     */
    public function createCounterOrder(array $input, int $staffId, ?DateTimeImmutable $now = null): array
    {
        $now ??= now();
        $key = (string) ($input['order_key'] ?? '');
        if (!preg_match('/^[a-f0-9]{32}$/', $key)) {
            throw ValidationException::one('_', 'error.generic');
        }
        $existing = $this->findByKey($key);
        if ($existing !== null) {
            return $existing + ['duplicate' => true];
        }

        $serviceType = (string) ($input['service_type'] ?? '');
        if (!in_array($serviceType, ['dine_in', 'parcel'], true)) {
            throw ValidationException::one('_', 'error.generic');
        }
        $lines = $this->cart->validate(is_array($input['lines'] ?? null) ? $input['lines'] : [], 'counter');
        $discount = is_array($input['discount'] ?? null) ? $input['discount'] : null;

        try {
            $totals = PricingService::totals(
                'counter',
                array_map(static fn (array $l): array => ['unit_paise' => $l['unit_paise'], 'qty' => $l['qty']], $lines),
                $serviceType === 'parcel',
                Settings::paise('parcel_charge'),
                $discount !== null ? (int) $discount['amount'] : 0,
            );
        } catch (DomainException) {
            throw ValidationException::one('_', 'discount.error.too_big');
        }
        if (isset($input['expected_total']) && (int) $input['expected_total'] !== $totals['total']) {
            throw ValidationException::one('_', 'billing.error.price_changed');
        }
        $payments = PaymentService::split((string) ($input['payment_method'] ?? ''), $totals['total'], (int) ($input['cash_paise'] ?? 0));

        try {
            $orderId = Db::transaction(function () use ($key, $serviceType, $lines, $totals, $payments, $discount, $staffId, $now): int {
                $token = $this->sequences->nextToken($now);
                $billNo = $this->sequences->nextBillNo();
                $at = $now->format('Y-m-d H:i:s');

                $this->db->prepare(
                    "INSERT INTO orders (order_key, bill_no, token_no, token_date, order_type, service_type, staff_id,
                                         status, subtotal, parcel_charge, discount, total, amount_paid, payment_pref,
                                         completed_at, created_at)
                     VALUES (?, ?, ?, ?, 'counter', ?, ?, 'completed', ?, ?, ?, ?, ?, 'counter', ?, ?)"
                )->execute([
                    $key, $billNo, $token, $now->format('Y-m-d'), $serviceType, $staffId,
                    decimal_from_paise($totals['subtotal']), decimal_from_paise($totals['parcel_charge']),
                    decimal_from_paise($totals['discount']), decimal_from_paise($totals['total']),
                    decimal_from_paise($totals['total']), $at, $at,
                ]);
                $orderId = (int) $this->db->lastInsertId();

                $insertLine = $this->db->prepare(
                    'INSERT INTO order_items (order_id, variant_id, item_name, unit_price, qty, line_total) VALUES (?, ?, ?, ?, ?, ?)'
                );
                foreach ($lines as $i => $line) {
                    $insertLine->execute([
                        $orderId, $line['variant_id'], $line['item_name'], decimal_from_paise($line['unit_paise']),
                        $line['qty'], decimal_from_paise($totals['lines'][$i]),
                    ]);
                }

                $this->payments->record($orderId, $payments, $staffId, $now);

                if ($discount !== null && $totals['discount'] > 0) {
                    $this->audit->log((int) $discount['owner_id'], 'discount', 'order', $orderId, [
                        'bill_no' => $billNo,
                        'amount' => decimal_from_paise($totals['discount']),
                        'reason' => (string) $discount['reason'],
                        'billed_by' => $staffId,
                    ]);
                }
                return $orderId;
            });
        } catch (PDOException $e) {
            // Two saves with the same key at the same moment: the second hits the unique key.
            $existing = ($e->errorInfo[1] ?? null) === 1062 ? $this->findByKey($key) : null;
            if ($existing === null) {
                throw $e;
            }
            return $existing + ['duplicate' => true];
        }

        return $this->find($orderId) + ['duplicate' => false];
    }

    /**
     * Cancels an order (SPEC §3–4); a reason is always required, and only today's orders.
     * - still 'received' (not accepted): staff may cancel without the owner ($ownerId null);
     *   the customer's own cancel passes both ids null
     * - accepted or later (preparing / ready / completed): needs an owner's approval — the
     *   caller checks the owner PIN and passes $ownerId
     * A billed order keeps its bill number and stays listed as Cancelled; its payments are
     * marked refunded so day totals leave it out.
     */
    public function cancel(int $orderId, string $reason, ?int $ownerId, ?int $staffId, ?DateTimeImmutable $now = null): void
    {
        $now ??= now();
        $reason = trim($reason);
        if (!is_valid_name($reason, 200)) {
            throw ValidationException::one('reason', 'cancel.error.reason');
        }

        Db::transaction(function () use ($orderId, $reason, $ownerId, $staffId, $now): void {
            $order = $this->lockOrder($orderId);
            if ($order['status'] === 'cancelled') {
                throw ValidationException::one('_', 'cancel.error.already');
            }
            // No-shows are final; a settled table order is cancelled with its whole bill (TableService::cancelBill).
            if ($order['status'] === 'no_show' || ($order['order_type'] === 'table' && $order['status'] === 'completed')) {
                throw ValidationException::one('_', 'order.error.wrong_status');
            }
            if ($order['token_date'] !== $now->format('Y-m-d')) {
                throw ValidationException::one('_', 'cancel.error.not_today');
            }
            if ($order['status'] !== 'received' && $ownerId === null) {
                throw ValidationException::one('_', 'cancel.error.owner_needed');
            }

            $this->db->prepare("UPDATE orders SET status = 'cancelled', cancel_reason = ?, cancelled_by = ? WHERE id = ?")
                ->execute([$reason, $ownerId ?? $staffId, $orderId]);
            $this->db->prepare("UPDATE payments SET status = 'refunded' WHERE order_id = ? AND status = 'success'")
                ->execute([$orderId]);
            $this->audit->log($ownerId ?? $staffId, 'cancel_order', 'order', $orderId, [
                'bill_no' => $order['bill_no'],
                'token' => $order['token_no'],
                'total' => $order['total'],
                'reason' => $reason,
                'status_before' => $order['status'],
                'requested_by' => $staffId,
            ]);
            // A table whose only orders are now cancelled becomes free again.
            if ($order['table_session_id'] !== null) {
                (new TableService($this->db))->closeIfEmpty((int) $order['table_session_id'], $ownerId ?? $staffId, $now);
            }
        });
    }

    /** Reprints are logged so the owner can see if bills are printed twice. */
    public function logReprint(int $orderId, int $staffId): void
    {
        $order = $this->find($orderId) ?? throw ValidationException::one('_', 'cancel.error.not_found');
        $this->audit->log($staffId, 'bill_reprint', 'order', $orderId, ['bill_no' => $order['bill_no']]);
    }

    /** One order with its lines, payments and the names of who billed / cancelled it. */
    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT o.*, s.name AS staff_name, c.name AS cancelled_by_name,
                    cu.name AS customer_name, cu.mobile AS customer_mobile, dt.code AS table_code
             FROM orders o
             LEFT JOIN staff s ON s.id = o.staff_id
             LEFT JOIN staff c ON c.id = o.cancelled_by
             LEFT JOIN customers cu ON cu.id = o.customer_id
             LEFT JOIN table_sessions ts ON ts.id = o.table_session_id
             LEFT JOIN dining_tables dt ON dt.id = ts.table_id
             WHERE o.id = ?'
        );
        $stmt->execute([$id]);
        $order = $stmt->fetch();
        if (!$order) {
            return null;
        }

        $stmt = $this->db->prepare('SELECT * FROM order_items WHERE order_id = ? ORDER BY id');
        $stmt->execute([$id]);
        $order['items'] = $stmt->fetchAll();

        $stmt = $this->db->prepare('SELECT mode, amount, status, created_at FROM payments WHERE order_id = ? ORDER BY id');
        $stmt->execute([$id]);
        $order['payments'] = $stmt->fetchAll();
        return $order;
    }

    public function findByKey(string $key): ?array
    {
        $stmt = $this->db->prepare('SELECT id FROM orders WHERE order_key = ?');
        $stmt->execute([$key]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : $this->find((int) $id);
    }

    /**
     * Orders of one day (by token date), newest first, each with its payments and item count.
     * @return list<array>
     */
    public function forDay(DateTimeImmutable $day): array
    {
        $stmt = $this->db->prepare(
            'SELECT o.*, s.name AS staff_name, cu.name AS customer_name,
                    (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id) AS line_count
             FROM orders o
             LEFT JOIN staff s ON s.id = o.staff_id
             LEFT JOIN customers cu ON cu.id = o.customer_id
             WHERE o.token_date = ?
             ORDER BY o.id DESC'
        );
        $stmt->execute([$day->format('Y-m-d')]);
        $orders = $stmt->fetchAll();
        if ($orders === []) {
            return [];
        }

        $ids = array_column($orders, 'id');
        $stmt = $this->db->prepare(
            'SELECT order_id, mode, amount FROM payments WHERE order_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id'
        );
        $stmt->execute($ids);
        $payments = [];
        foreach ($stmt->fetchAll() as $payment) {
            $payments[(int) $payment['order_id']][] = $payment;
        }
        return array_map(static function (array $order) use ($payments): array {
            $order['payments'] = $payments[(int) $order['id']] ?? [];
            return $order;
        }, $orders);
    }
}
