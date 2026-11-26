<?php

declare(strict_types=1);

namespace App\Services;

use App\Db;
use App\Settings;
use App\ValidationException;
use DateTimeImmutable;
use PDO;
use PDOException;

/**
 * Bulk / party orders (SPEC §8): the customer requests → pending_approval; the owner approves with
 * an advance (→ preparing) or rejects; staff record advance payments at the shop, mark ready, and
 * at pickup collect the balance — the bill number is assigned then (SPEC §4). Quantities may be
 * decimal (kg, via "1 kg" variants the owner adds). No parcel charge.
 */
final class BulkService
{
    public const MAX_NOTE = 250;
    public const MAX_DAYS_AHEAD = 90;
    public const OPEN_STATUSES = ['pending_approval', 'preparing', 'ready'];

    private readonly PDO $db;
    private readonly SequenceService $sequences;
    private readonly CartService $cart;
    private readonly PaymentService $payments;
    private readonly AuditService $audit;
    private readonly CustomerService $customers;
    private readonly OrderService $orders;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Db::pdo();
        $this->sequences = new SequenceService($this->db);
        $this->cart = new CartService($this->db);
        $this->payments = new PaymentService($this->db);
        $this->audit = new AuditService($this->db);
        $this->customers = new CustomerService($this->db);
        $this->orders = new OrderService($this->db);
    }

    /**
     * Customer's request. $input: order_key, name, mobile, note, event_date (Y-m-d),
     * pickup_time (HH:MM), lines [['variant_id' => …, 'qty' => '2.5'], …].
     *
     * @return array the saved order (OrderService::find) plus 'duplicate' => bool
     */
    public function request(array $input, ?DateTimeImmutable $now = null): array
    {
        $now ??= now();
        $key = (string) ($input['order_key'] ?? '');
        if (!preg_match('/^[a-f0-9]{32}$/', $key)) {
            throw ValidationException::one('_', 'error.generic');
        }
        $existing = $this->orders->findByKey($key);
        if ($existing !== null) {
            return $existing + ['duplicate' => true];
        }

        $errors = [];
        try {
            $contact = CustomerService::validateContact($input, self::MAX_NOTE);
        } catch (ValidationException $e) {
            $errors = $e->errors;
        }
        $when = $this->parseWhen((string) ($input['event_date'] ?? ''), (string) ($input['pickup_time'] ?? ''), $now);
        $errors += $when['errors'];
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $lines = $this->cart->validate(is_array($input['lines'] ?? null) ? $input['lines'] : [], 'bulk');
        $totals = self::price($lines);

        try {
            $orderId = Db::transaction(function () use ($key, $contact, $when, $lines, $totals, $now): int {
                $token = $this->sequences->nextToken($now);
                $customerId = $this->customers->recordOrder($contact['mobile'], $contact['name'], $now);
                $this->db->prepare(
                    "INSERT INTO orders (order_key, token_no, token_date, order_type, service_type, customer_id, status,
                                         pickup_at, event_date, subtotal, parcel_charge, discount, total, amount_paid,
                                         payment_pref, note, created_at)
                     VALUES (?, ?, ?, 'bulk', 'parcel', ?, 'pending_approval', ?, ?, ?, 0, 0, ?, 0, 'counter', ?, ?)"
                )->execute([
                    $key, $token, $now->format('Y-m-d'), $customerId, $when['pickup_at'], $when['event_date'],
                    decimal_from_paise($totals['subtotal']), decimal_from_paise($totals['total']),
                    $contact['note'] === '' ? null : $contact['note'], $now->format('Y-m-d H:i:s'),
                ]);
                $orderId = (int) $this->db->lastInsertId();
                $this->writeLines($orderId, $lines, $totals['lines']);
                return $orderId;
            });
        } catch (PDOException $e) {
            $existing = ($e->errorInfo[1] ?? null) === 1062 ? $this->orders->findByKey($key) : null;
            if ($existing === null) {
                throw $e;
            }
            return $existing + ['duplicate' => true];
        }

        return $this->orders->find($orderId) + ['duplicate' => false];
    }

    /** Owner approves with an advance (₹0 allowed, never more than the total): → preparing. */
    public function approve(int $orderId, int $advancePaise, int $ownerId, ?DateTimeImmutable $now = null): void
    {
        $now ??= now();
        Db::transaction(function () use ($orderId, $advancePaise, $ownerId, $now): void {
            $order = $this->lock($orderId, ['pending_approval']);
            $total = paise_from_decimal((string) $order['total']);
            if ($advancePaise < 0 || $advancePaise > $total) {
                throw ValidationException::one('advance', 'bulk.error.advance');
            }
            $this->db->prepare(
                "UPDATE orders SET status = 'preparing', advance_amount = ?, accepted_at = ?, staff_id = ? WHERE id = ?"
            )->execute([decimal_from_paise($advancePaise), $now->format('Y-m-d H:i:s'), $ownerId, $orderId]);
            $this->audit->log($ownerId, 'bulk_approve', 'order', $orderId, [
                'token' => $order['token_no'],
                'total' => $order['total'],
                'amount' => decimal_from_paise($advancePaise),
            ]);
        });
    }

    /**
     * Owner rejects a request or cancels an approved bulk order; the customer may cancel their
     * own request while it is still pending ($ownerId null). Payments already made are marked
     * refunded (the shop returns the advance).
     */
    public function cancel(int $orderId, string $reason, ?int $ownerId, ?DateTimeImmutable $now = null): void
    {
        $reason = trim($reason);
        if (!is_valid_name($reason, 200)) {
            throw ValidationException::one('reason', 'cancel.error.reason');
        }
        Db::transaction(function () use ($orderId, $reason, $ownerId): void {
            $order = $this->lock($orderId, self::OPEN_STATUSES);
            if ($ownerId === null && $order['status'] !== 'pending_approval') {
                throw ValidationException::one('_', 'order.error.cannot_cancel');
            }
            $this->db->prepare("UPDATE orders SET status = 'cancelled', cancel_reason = ?, cancelled_by = ? WHERE id = ?")
                ->execute([$reason, $ownerId, $orderId]);
            $this->db->prepare("UPDATE payments SET status = 'refunded' WHERE order_id = ? AND status = 'success'")
                ->execute([$orderId]);
            $this->audit->log($ownerId, $order['status'] === 'pending_approval' ? 'bulk_reject' : 'cancel_order', 'order', $orderId, [
                'token' => $order['token_no'],
                'total' => $order['total'],
                'reason' => $reason,
                'amount' => $order['amount_paid'],
            ]);
        });
    }

    /**
     * Owner edits the lines (SPEC §8 "can edit items/prices"): each line is a variant, a quantity
     * (decimal allowed) and a price in rupees that may differ from the menu. Totals are recomputed;
     * the new total may not drop below what the customer already paid.
     *
     * @param list<array{variant_id: mixed, qty: mixed, price: mixed}> $lines
     */
    public function updateLines(int $orderId, array $lines, int $ownerId): void
    {
        if ($lines === []) {
            throw ValidationException::one('_', 'cart.error.empty');
        }
        $priced = [];
        $errors = [];
        foreach (array_values($lines) as $i => $line) {
            $variant = $this->variant((int) ($line['variant_id'] ?? 0));
            $qty = CartService::parseQty($line['qty'] ?? null, true);
            $priceText = trim((string) ($line['price'] ?? ''));
            try {
                // A blank price on a new line means "today's menu price".
                $price = $priceText === '' && $variant !== null
                    ? paise_from_decimal((string) $variant['price'])
                    : parse_rupees($priceText);
            } catch (\InvalidArgumentException) {
                $price = 0;
            }
            if ($variant === null) {
                $errors["lines.{$i}.variant"] = 'cart.error.unknown';
            }
            if ($qty === null || $qty < 1 || $qty > CartService::MAX_BULK_QTY_HUNDREDTHS) {
                $errors["lines.{$i}.qty"] = 'cart.error.bulk_qty';
            }
            if ($price <= 0 || $price > MenuService::MAX_PRICE_PAISE) {
                $errors["lines.{$i}.price"] = 'menu.error.price';
            }
            if ($variant !== null && $qty !== null && $price > 0) {
                $priced[] = [
                    'variant_id' => (int) $variant['id'],
                    'item_name' => CartService::snapshotName($variant),
                    'unit_paise' => $price,
                    'qty' => $qty % 100 === 0 ? (string) intdiv($qty, 100) : hundredths_to_decimal($qty),
                ];
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $totals = self::price($priced);

        Db::transaction(function () use ($orderId, $priced, $totals, $ownerId): void {
            $order = $this->lock($orderId, self::OPEN_STATUSES);
            if ($totals['total'] < paise_from_decimal((string) $order['amount_paid'])) {
                throw ValidationException::one('_', 'bulk.error.below_paid');
            }
            if ($order['advance_amount'] !== null && $totals['total'] < paise_from_decimal((string) $order['advance_amount'])) {
                throw ValidationException::one('_', 'bulk.error.advance');
            }
            $this->db->prepare('DELETE FROM order_items WHERE order_id = ?')->execute([$orderId]);
            $this->writeLines($orderId, $priced, $totals['lines']);
            $this->db->prepare('UPDATE orders SET subtotal = ?, total = ? WHERE id = ?')
                ->execute([decimal_from_paise($totals['subtotal']), decimal_from_paise($totals['total']), $orderId]);
            $this->audit->log($ownerId, 'bulk_update', 'order', $orderId, [
                'token' => $order['token_no'],
                'before' => ['total' => $order['total']],
                'after' => ['total' => decimal_from_paise($totals['total'])],
            ]);
        });
    }

    /** Staff record money received before pickup (usually the advance): cash or UPI. */
    public function recordPayment(int $orderId, string $method, int $amountPaise, int $staffId, ?DateTimeImmutable $now = null): array
    {
        $now ??= now();
        Db::transaction(function () use ($orderId, $method, $amountPaise, $staffId, $now): void {
            $order = $this->lock($orderId, ['preparing', 'ready']);
            if (!in_array($method, ['cash', 'upi'], true)) {
                throw ValidationException::one('_', 'payment.error.method');
            }
            if ($amountPaise <= 0 || $amountPaise > self::balance($order)) {
                throw ValidationException::one('amount', 'bulk.error.payment');
            }
            $this->payments->record($orderId, [['mode' => $method === 'cash' ? 'cash' : 'upi_counter', 'amount' => $amountPaise]], $staffId, $now);
            $this->db->prepare('UPDATE orders SET amount_paid = amount_paid + ? WHERE id = ?')
                ->execute([decimal_from_paise($amountPaise), $orderId]);
        });
        return $this->orders->find($orderId);
    }

    /** preparing → ready. */
    public function markReady(int $orderId, int $staffId, ?DateTimeImmutable $now = null): void
    {
        $this->orders->markReady($orderId, $staffId, $now);
    }

    /**
     * Pickup: the customer pays the balance (cash / UPI / mixed; nothing if fully prepaid), the
     * bill number is assigned now, the order is completed.
     */
    public function complete(int $orderId, string $method, int $cashPaise, int $staffId, ?DateTimeImmutable $now = null): array
    {
        $now ??= now();
        Db::transaction(function () use ($orderId, $method, $cashPaise, $staffId, $now): void {
            $order = $this->lock($orderId, ['preparing', 'ready']);
            $balance = self::balance($order);
            $rows = PaymentService::split($method, $balance, $cashPaise);
            $billNo = $this->sequences->nextBillNo();
            $this->db->prepare(
                "UPDATE orders SET status = 'completed', bill_no = ?, amount_paid = total, completed_at = ?, staff_id = ? WHERE id = ?"
            )->execute([$billNo, $now->format('Y-m-d H:i:s'), $staffId, $orderId]);
            $this->payments->record($orderId, $rows, $staffId, $now);
        });
        return $this->orders->find($orderId);
    }

    /**
     * Bulk orders still open (pending, approved, ready), soonest pickup first, with their lines.
     * @return list<array>
     */
    public function open(): array
    {
        return $this->withLines($this->db->query(
            "SELECT o.*, cu.name AS customer_name, cu.mobile AS customer_mobile
             FROM orders o LEFT JOIN customers cu ON cu.id = o.customer_id
             WHERE o.order_type = 'bulk' AND o.status IN ('pending_approval', 'preparing', 'ready')
             ORDER BY o.status = 'pending_approval' DESC, o.pickup_at, o.id"
        )->fetchAll());
    }

    /** Recently finished bulk orders (completed / cancelled) for the owner's history. */
    public function recent(int $limit = 20): array
    {
        $limit = max(1, $limit);
        return $this->withLines($this->db->query(
            "SELECT o.*, cu.name AS customer_name, cu.mobile AS customer_mobile
             FROM orders o LEFT JOIN customers cu ON cu.id = o.customer_id
             WHERE o.order_type = 'bulk' AND o.status IN ('completed', 'cancelled')
             ORDER BY o.id DESC LIMIT {$limit}"
        )->fetchAll());
    }

    /** What is still owed, in paise. */
    public static function balance(array $order): int
    {
        return max(0, paise_from_decimal((string) $order['total']) - paise_from_decimal((string) $order['amount_paid']));
    }

    /** Advance the owner asked for that is still unpaid, in paise. */
    public static function advanceDue(array $order): int
    {
        if ($order['advance_amount'] === null) {
            return 0;
        }
        return max(0, paise_from_decimal((string) $order['advance_amount']) - paise_from_decimal((string) $order['amount_paid']));
    }

    /** @return array{lines: list<int>, subtotal: int, parcel_charge: int, discount: int, round_off: int, total: int} */
    private static function price(array $lines): array
    {
        return PricingService::totals(
            'bulk',
            array_map(static fn (array $l): array => ['unit_paise' => $l['unit_paise'], 'qty' => $l['qty']], $lines),
            false,
            0,
        );
    }

    /**
     * Event date at least bulk_notice_days ahead (and at most 90), pickup time within shop hours.
     * @return array{errors: array, event_date?: string, pickup_at?: string}
     */
    private function parseWhen(string $date, string $time, DateTimeImmutable $now): array
    {
        $errors = [];
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $now->getTimezone());
        $notice = max(0, Settings::int('bulk_notice_days', 1));
        $earliest = $now->setTime(0, 0)->modify("+{$notice} days");
        if ($day === false || $day->format('Y-m-d') !== $date) {
            $errors['event_date'] = 'bulk.error.date';
        } elseif ($day < $earliest) {
            $errors['event_date'] = ['bulk.error.date_too_soon', ['days' => $notice]];
        } elseif ($day > $now->setTime(0, 0)->modify('+' . self::MAX_DAYS_AHEAD . ' days')) {
            $errors['event_date'] = ['bulk.error.date_too_far', ['days' => self::MAX_DAYS_AHEAD]];
        }

        $open = Settings::get('open_time', '10:00');
        $close = Settings::get('close_time', '22:30');
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) || $time < $open || $time >= $close) {
            $errors['pickup_time'] = ['bulk.error.time', ['open' => format_time($open), 'close' => format_time($close)]];
        }

        if ($errors !== []) {
            return ['errors' => $errors];
        }
        return ['errors' => [], 'event_date' => $date, 'pickup_at' => $date . ' ' . $time . ':00'];
    }

    private function lock(int $orderId, array $statuses): array
    {
        $stmt = $this->db->prepare("SELECT * FROM orders WHERE id = ? AND order_type = 'bulk' FOR UPDATE");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch() ?: throw ValidationException::one('_', 'cancel.error.not_found');
        if (!in_array($order['status'], $statuses, true)) {
            throw ValidationException::one('_', 'order.error.wrong_status');
        }
        return $order;
    }

    private function variant(int $variantId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT v.id, v.label_mr, v.label_en, v.price, i.name_mr, i.name_en FROM item_variants v JOIN items i ON i.id = v.item_id WHERE v.id = ?'
        );
        $stmt->execute([$variantId]);
        return $stmt->fetch() ?: null;
    }

    private function writeLines(int $orderId, array $lines, array $lineTotals): void
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

    private function withLines(array $orders): array
    {
        $stmt = $this->db->prepare('SELECT * FROM order_items WHERE order_id = ? ORDER BY id');
        foreach ($orders as $i => $order) {
            $stmt->execute([$order['id']]);
            $orders[$i]['items'] = $stmt->fetchAll();
        }
        return $orders;
    }
}
