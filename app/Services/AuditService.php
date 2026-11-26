<?php

declare(strict_types=1);

namespace App\Services;

use App\Db;
use PDO;

/** Who changed what, and when: prices, menu, settings, staff, (later) cancellations and discounts. */
final class AuditService
{
    private readonly PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Db::pdo();
    }

    /**
     * Runs inside the caller's transaction (same connection), so the log entry is saved only if
     * the change itself is saved. $staffId is null for CLI setup scripts.
     */
    public function log(?int $staffId, string $action, string $entity, int|string|null $entityId, array $details = []): void
    {
        $this->db->prepare(
            'INSERT INTO audit_log (staff_id, action, entity, entity_id, details) VALUES (?, ?, ?, ?, ?)'
        )->execute([
            $staffId,
            $action,
            $entity,
            $entityId === null ? null : (string) $entityId,
            $details === [] ? null : json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);
    }

    /**
     * Newest first. Filters: action, from (Y-m-d), to (Y-m-d).
     *
     * @return array{rows: list<array>, total: int}
     */
    public function search(array $filters = [], int $page = 1, int $perPage = 50): array
    {
        $where = [];
        $params = [];
        if (!empty($filters['action'])) {
            $where[] = 'a.action = ?';
            $params[] = $filters['action'];
        }
        if (!empty($filters['from'])) {
            $where[] = 'a.created_at >= ?';
            $params[] = $filters['from'] . ' 00:00:00';
        }
        if (!empty($filters['to'])) {
            $where[] = 'a.created_at <= ?';
            $params[] = $filters['to'] . ' 23:59:59';
        }
        $whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);

        $count = $this->db->prepare("SELECT COUNT(*) FROM audit_log a {$whereSql}");
        $count->execute($params);

        $limit = max(1, $perPage);
        $offset = max(0, ($page - 1) * $limit);
        $stmt = $this->db->prepare(
            "SELECT a.*, s.name AS staff_name
             FROM audit_log a LEFT JOIN staff s ON s.id = a.staff_id
             {$whereSql}
             ORDER BY a.id DESC
             LIMIT {$limit} OFFSET {$offset}"
        );
        $stmt->execute($params);

        $rows = array_map(static function (array $row): array {
            $row['details'] = $row['details'] === null ? [] : (json_decode($row['details'], true) ?: []);
            return $row;
        }, $stmt->fetchAll());

        return ['rows' => $rows, 'total' => (int) $count->fetchColumn()];
    }

    /** @return list<string> actions present in the log, for the filter dropdown */
    public function actions(): array
    {
        return $this->db->query('SELECT DISTINCT action FROM audit_log ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);
    }
}
