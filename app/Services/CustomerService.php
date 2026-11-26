<?php

declare(strict_types=1);

namespace App\Services;

use App\Db;
use App\Settings;
use App\ValidationException;
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

    /**
     * Name, mobile and note as every customer form checks them (takeaway, table, bulk).
     * Mobile: 10 digits starting 6–9, "+91 98765 43210" accepted (SPEC §6.3).
     *
     * @return array{name: string, mobile: string, note: string}
     */
    public static function validateContact(array $input, int $maxNote = 200): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        $mobile = normalize_mobile((string) ($input['mobile'] ?? ''));
        $note = trim((string) ($input['note'] ?? ''));
        $errors = [];
        if (!is_valid_name($name, 60)) {
            $errors['name'] = 'checkout.error.name';
        }
        if ($mobile === null) {
            $errors['mobile'] = 'checkout.error.mobile';
        }
        if (mb_strlen($note) > $maxNote || preg_match('/[\x00-\x09\x0B-\x1F\x7F]/u', $note)) {
            $errors['note'] = ['checkout.error.note_max', ['max' => $maxNote]];
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return ['name' => $name, 'mobile' => $mobile, 'note' => $note];
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
        return $this->search('', 'blocked', 1, 1000)['rows'];
    }

    // ---------------------------------------------------------------
    // Admin → Customers (M7)
    // ---------------------------------------------------------------

    public const FILTERS = ['all', 'blocked', 'no_shows'];

    /**
     * Customers matching a search, most recent order first. $query: digits search the mobile
     * number ("98765", "+91 98765 43210"), anything else the name. $filter: all | blocked | no_shows.
     *
     * @return array{rows: list<array>, total: int}
     */
    public function search(string $query, string $filter, int $page = 1, int $perPage = 50): array
    {
        [$where, $params] = self::where($query, $filter);
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM customers c WHERE {$where}");
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();

        $perPage = max(1, $perPage);
        $offset = (max(1, $page) - 1) * $perPage;
        $stmt = $this->db->prepare(
            "SELECT c.id, c.mobile, c.name, c.order_count, c.last_order_at, c.no_show_count, c.is_blocked, c.created_at
             FROM customers c WHERE {$where}
             ORDER BY c.last_order_at IS NULL, c.last_order_at DESC, c.id DESC
             LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute($params);
        return ['rows' => $stmt->fetchAll(), 'total' => $total];
    }

    /** @return array{0: string, 1: list<string>} */
    private static function where(string $query, string $filter): array
    {
        $conditions = ['1 = 1'];
        $params = [];
        if ($filter === 'blocked') {
            $conditions[] = 'c.is_blocked = 1';
        } elseif ($filter === 'no_shows') {
            $conditions[] = 'c.no_show_count > 0';
        }

        $query = trim(mb_substr($query, 0, 60));
        if ($query !== '') {
            $digits = preg_replace('/\D/', '', $query);
            if (preg_match('/^[\d\s+()-]+$/', $query) && $digits !== '') {
                if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
                    $digits = substr($digits, 2); // "+91 98765 43210"
                }
                $conditions[] = 'c.mobile LIKE ?';
                $params[] = '%' . $digits . '%';
            } else {
                $conditions[] = "c.name LIKE ? ESCAPE '!'";
                $params[] = '%' . strtr($query, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            }
        }
        return [implode(' AND ', $conditions), $params];
    }

    public function find(int $customerId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM customers WHERE id = ?');
        $stmt->execute([$customerId]);
        return $stmt->fetch() ?: null;
    }

    /** The customer's latest orders (any type), newest first. @return list<array> */
    public function orders(int $customerId, int $limit = 20): array
    {
        $limit = max(1, $limit);
        $stmt = $this->db->prepare(
            "SELECT o.id, o.token_no, o.token_date, o.order_type, o.status, o.total, o.bill_no, o.pickup_at,
                    o.cancel_reason, o.created_at, t.code AS table_code
             FROM orders o
             LEFT JOIN table_sessions s ON s.id = o.table_session_id
             LEFT JOIN dining_tables t ON t.id = s.table_id
             WHERE o.customer_id = ? ORDER BY o.id DESC LIMIT {$limit}"
        );
        $stmt->execute([$customerId]);
        return $stmt->fetchAll();
    }

    /**
     * Customers CSV (developer decision for M7: the owner may export names + mobile numbers).
     * Every export is written to the audit log.
     *
     * @return list<array>
     */
    public function export(string $query, string $filter, int $staffId): array
    {
        $rows = $this->search($query, $filter, 1, 1000000)['rows'];
        $this->audit->log($staffId, 'customers_export', 'customer', null, [
            'count' => count($rows),
            'filter' => $filter,
            'search' => mb_substr(trim($query), 0, 60),
        ]);
        return $rows;
    }

    /** Owner blocks a number from online ordering by hand (no-shows block automatically). */
    public function block(int $customerId, int $staffId): void
    {
        $customer = $this->find($customerId);
        if ($customer === null || (int) $customer['is_blocked'] === 1) {
            return;
        }
        Db::transaction(function () use ($customerId, $customer, $staffId): void {
            $this->db->prepare('UPDATE customers SET is_blocked = 1 WHERE id = ?')->execute([$customerId]);
            $this->audit->log($staffId, 'customer_block', 'customer', $customerId, [
                'name' => $customer['name'],
                'mobile' => $customer['mobile'],
            ]);
        });
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
