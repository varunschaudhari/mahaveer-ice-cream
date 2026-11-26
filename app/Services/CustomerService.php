<?php

declare(strict_types=1);

namespace App\Services;

use App\Db;
use App\Settings;
use DateTimeImmutable;
use PDO;

/**
 * Online customers, keyed by mobile number (no login, SPEC §2). Counts orders and no-shows;
 * a number with noshow_block_count no-shows is blocked from ordering online.
 */
final class CustomerService
{
    private readonly PDO $db;
    private readonly AuditService $audit;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Db::pdo();
        $this->audit = new AuditService($this->db);
    }

    public function isBlocked(string $mobile): bool
    {
        $stmt = $this->db->prepare('SELECT is_blocked FROM customers WHERE mobile = ?');
        $stmt->execute([$mobile]);
        return (bool) $stmt->fetchColumn();
    }

    /** Creates or updates the customer for a new order (inside the order transaction). Returns the id. */
    public function recordOrder(string $mobile, string $name, DateTimeImmutable $now): int
    {
        $this->db->prepare(
            'INSERT INTO customers (mobile, name, order_count, last_order_at, created_at) VALUES (?, ?, 1, ?, ?)
             ON DUPLICATE KEY UPDATE name = ?, order_count = order_count + 1, last_order_at = ?'
        )->execute([$mobile, $name, $now->format('Y-m-d H:i:s'), $now->format('Y-m-d H:i:s'), $name, $now->format('Y-m-d H:i:s')]);

        $stmt = $this->db->prepare('SELECT id FROM customers WHERE mobile = ?');
        $stmt->execute([$mobile]);
        return (int) $stmt->fetchColumn();
    }

    /** Counts a no-show; blocks the number at the threshold. Returns true if this blocked it. */
    public function recordNoShow(int $customerId): bool
    {
        $this->db->prepare('UPDATE customers SET no_show_count = no_show_count + 1 WHERE id = ?')->execute([$customerId]);
        $stmt = $this->db->prepare(
            'UPDATE customers SET is_blocked = 1 WHERE id = ? AND is_blocked = 0 AND no_show_count >= ?'
        );
        $stmt->execute([$customerId, max(1, Settings::int('noshow_block_count', 2))]);
        return $stmt->rowCount() > 0;
    }

    /** @return list<array> blocked numbers, most recent order first */
    public function blocked(): array
    {
        return $this->db->query(
            'SELECT id, mobile, name, order_count, no_show_count, last_order_at FROM customers
             WHERE is_blocked = 1 ORDER BY last_order_at DESC'
        )->fetchAll();
    }

    /** Owner unblocks a number; its no-show count starts again from zero. */
    public function unblock(int $customerId, int $staffId): void
    {
        $stmt = $this->db->prepare('SELECT mobile, name FROM customers WHERE id = ? AND is_blocked = 1');
        $stmt->execute([$customerId]);
        $customer = $stmt->fetch();
        if (!$customer) {
            return;
        }
        Db::transaction(function () use ($customerId, $customer, $staffId): void {
            $this->db->prepare('UPDATE customers SET is_blocked = 0, no_show_count = 0 WHERE id = ?')->execute([$customerId]);
            $this->audit->log($staffId, 'customer_unblock', 'customer', $customerId, [
                'name' => $customer['name'],
                'mobile' => $customer['mobile'],
            ]);
        });
    }
}
