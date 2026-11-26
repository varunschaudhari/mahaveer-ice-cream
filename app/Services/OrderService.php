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
 * Orders. M2: counter bills (saved straight as completed), cancel, reprint, the day's list.
 * Takeaway / table / bulk status changes join in M3–M5.
 */
final class OrderService
{
    private readonly PDO $db;
    private readonly SequenceService $sequences;
    private readonly CartService $cart;
    private readonly PaymentService $payments;
    private readonly AuditService $audit;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Db::pdo();
        $this->sequences = new SequenceService($this->db);
        $this->cart = new CartService($this->db);
        $this->payments = new PaymentService($this->db);
        $this->audit = new AuditService($this->db);
    }

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

                $this->payments->record($orderId, $payments, $staffId);

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
     * Cancels a bill (SPEC §3–4): needs an owner's approval (PIN checked by the caller) and a
     * reason; only today's bills. The bill keeps its number and stays listed as Cancelled; its
     * payments are marked refunded so day totals leave it out.
     */
    public function cancel(int $orderId, string $reason, int $ownerId, int $staffId, ?DateTimeImmutable $now = null): void
    {
        $now ??= now();
        $reason = trim($reason);
        if (!is_valid_name($reason, 200)) {
            throw ValidationException::one('reason', 'cancel.error.reason');
        }

        Db::transaction(function () use ($orderId, $reason, $ownerId, $staffId, $now): void {
            $stmt = $this->db->prepare('SELECT * FROM orders WHERE id = ? FOR UPDATE');
            $stmt->execute([$orderId]);
            $order = $stmt->fetch();
            if (!$order) {
                throw ValidationException::one('_', 'cancel.error.not_found');
            }
            if ($order['status'] === 'cancelled') {
                throw ValidationException::one('_', 'cancel.error.already');
            }
            if ($order['token_date'] !== $now->format('Y-m-d')) {
                throw ValidationException::one('_', 'cancel.error.not_today');
            }

            $this->db->prepare("UPDATE orders SET status = 'cancelled', cancel_reason = ?, cancelled_by = ? WHERE id = ?")
                ->execute([$reason, $ownerId, $orderId]);
            $this->db->prepare("UPDATE payments SET status = 'refunded' WHERE order_id = ? AND status = 'success'")
                ->execute([$orderId]);
            $this->audit->log($ownerId, 'cancel_order', 'order', $orderId, [
                'bill_no' => $order['bill_no'],
                'token' => $order['token_no'],
                'total' => $order['total'],
                'reason' => $reason,
                'requested_by' => $staffId,
            ]);
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
            'SELECT o.*, s.name AS staff_name, c.name AS cancelled_by_name
             FROM orders o
             LEFT JOIN staff s ON s.id = o.staff_id
             LEFT JOIN staff c ON c.id = o.cancelled_by
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

        $stmt = $this->db->prepare('SELECT mode, amount, status FROM payments WHERE order_id = ? ORDER BY id');
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
            'SELECT o.*, s.name AS staff_name,
                    (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id) AS line_count
             FROM orders o LEFT JOIN staff s ON s.id = o.staff_id
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
