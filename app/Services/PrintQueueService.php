<?php

declare(strict_types=1);

namespace App\Services;

use App\Db;
use App\ValidationException;
use DateTimeImmutable;
use PDO;

/**
 * Bills taken on a phone, printed by the counter PC (decision 2026-11-28). A phone cannot reach
 * the USB receipt printer, so it queues the print page; a counter screen that prints claims the
 * jobs from its poll and prints them silently. Jobs are kept as data (type + id), never as a URL
 * from the browser, so only what print.php prints can come out of the printer.
 */
final class PrintQueueService
{
    /** Jobs older than this are not printed any more (e.g. the counter PC was off). */
    public const FRESH_MINUTES = 30;
    public const KEEP_DAYS = 2;

    private readonly PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Db::pdo();
    }

    /**
     * Queues a print page the counter would have printed itself, e.g. the print_url an API returned:
     * /counter/print.php?type=bill&id=12[&copy=1], ?type=table&id=5[&copy=1] or ?type=day.
     */
    public function add(string $url, int $staffId): int
    {
        [$type, $refId, $copy] = self::parse($url);
        $this->db->prepare('INSERT INTO print_jobs (job_type, ref_id, is_copy, created_by) VALUES (?, ?, ?, ?)')
            ->execute([$type, $refId, $copy ? 1 : 0, $staffId]);
        return (int) $this->db->lastInsertId();
    }

    /** @return array{0: string, 1: ?int, 2: bool} job type, referenced id, copy */
    public static function parse(string $url): array
    {
        $parts = parse_url($url);
        if (($parts['path'] ?? '') !== '/counter/print.php' || isset($parts['host']) || isset($parts['scheme'])) {
            throw ValidationException::one('url', 'print.error.invalid');
        }
        parse_str($parts['query'] ?? '', $query);
        $type = is_string($query['type'] ?? null) ? $query['type'] : '';
        $id = is_string($query['id'] ?? null) && ctype_digit($query['id']) ? (int) $query['id'] : 0;
        $copy = !empty($query['copy']);
        if ($type === 'day') {
            return ['day', null, false];
        }
        if (!in_array($type, ['bill', 'table'], true) || $id <= 0) {
            throw ValidationException::one('url', 'print.error.invalid');
        }
        return [$type, $id, $copy];
    }

    /** Fresh jobs nobody has taken yet (the poll tells printing counters to claim). */
    public function pendingCount(DateTimeImmutable $now): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM print_jobs WHERE claimed_at IS NULL AND created_at >= ?');
        $stmt->execute([self::freshSince($now)]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Takes up to $limit fresh jobs for one counter and returns their print page URLs, oldest first.
     * The UPDATE marks them in one statement, so two counters never print the same job.
     * @return list<string>
     */
    public function claim(DateTimeImmutable $now, int $limit = 5): array
    {
        $token = bin2hex(random_bytes(16));
        $stmt = $this->db->prepare(
            'UPDATE print_jobs SET claimed_at = ?, claim_token = ?
             WHERE claimed_at IS NULL AND created_at >= ? ORDER BY id LIMIT ' . max(1, min(20, $limit))
        );
        $stmt->execute([$now->format('Y-m-d H:i:s'), $token, self::freshSince($now)]);

        $stmt = $this->db->prepare('SELECT job_type, ref_id, is_copy FROM print_jobs WHERE claim_token = ? ORDER BY id');
        $stmt->execute([$token]);
        return array_map(static fn (array $job): string => self::url($job['job_type'], $job['ref_id'] === null ? null : (int) $job['ref_id'], (bool) $job['is_copy']), $stmt->fetchAll());
    }

    public static function url(string $type, ?int $refId, bool $copy): string
    {
        if ($type === 'day') {
            return '/counter/print.php?type=day';
        }
        return '/counter/print.php?type=' . $type . ($copy ? '&copy=1' : '') . '&id=' . (int) $refId;
    }

    /** Weekly cleanup: printed or expired jobs are no longer needed. */
    public function deleteOld(DateTimeImmutable $now): int
    {
        $stmt = $this->db->prepare('DELETE FROM print_jobs WHERE created_at < ?');
        $stmt->execute([$now->modify('-' . self::KEEP_DAYS . ' days')->format('Y-m-d H:i:s')]);
        return $stmt->rowCount();
    }

    private static function freshSince(DateTimeImmutable $now): string
    {
        return $now->modify('-' . self::FRESH_MINUTES . ' minutes')->format('Y-m-d H:i:s');
    }
}
