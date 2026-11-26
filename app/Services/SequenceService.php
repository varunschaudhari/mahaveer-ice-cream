<?php

declare(strict_types=1);

namespace App\Services;

use App\Db;
use DateTimeInterface;
use LogicException;
use PDO;

/**
 * Token numbers (reset to 1 every day) and bill numbers (continuous, never reused), SPEC §4.
 *
 * Both lock their counter row with SELECT … FOR UPDATE, so they must run inside the transaction
 * that saves the order: two counters billing at the same moment wait for each other instead of
 * getting the same number, and a failed save rolls the number back (no gaps).
 */
final class SequenceService
{
    private readonly PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Db::pdo();
    }

    public function nextToken(DateTimeInterface $day): int
    {
        $this->assertInTransaction();
        $date = $day->format('Y-m-d');

        $this->db->prepare('INSERT IGNORE INTO daily_tokens (token_date, last_token) VALUES (?, 0)')->execute([$date]);
        $stmt = $this->db->prepare('SELECT last_token FROM daily_tokens WHERE token_date = ? FOR UPDATE');
        $stmt->execute([$date]);
        $next = (int) $stmt->fetchColumn() + 1;
        $this->db->prepare('UPDATE daily_tokens SET last_token = ? WHERE token_date = ?')->execute([$next, $date]);
        return $next;
    }

    public function nextBillNo(): int
    {
        $this->assertInTransaction();

        $this->db->exec("INSERT IGNORE INTO sequences (name, last_value) VALUES ('bill_no', 0)");
        $next = (int) $this->db->query("SELECT last_value FROM sequences WHERE name = 'bill_no' FOR UPDATE")->fetchColumn() + 1;
        $this->db->prepare("UPDATE sequences SET last_value = ? WHERE name = 'bill_no'")->execute([$next]);
        return $next;
    }

    private function assertInTransaction(): void
    {
        if (!$this->db->inTransaction()) {
            throw new LogicException('Sequence numbers must be taken inside the transaction that saves the order');
        }
    }
}
