<?php

declare(strict_types=1);

namespace App\Services;

use App\Db;
use App\ValidationException;
use DateTimeImmutable;
use PDO;

/** How a bill is paid at the counter: Cash, UPI (shop's QR) or Mixed (part cash, part UPI). */
final class PaymentService
{
    public const METHODS = ['cash', 'upi', 'mixed'];

    private readonly PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Db::pdo();
    }

    /**
     * Payment rows for a bill. Mixed: $cashPaise is the cash part, UPI pays the rest; both parts
     * must be more than ₹0. A ₹0 bill (fully discounted) has no payment rows.
     *
     * @return list<array{mode: string, amount: int}>
     */
    public static function split(string $method, int $totalPaise, int $cashPaise = 0): array
    {
        if (!in_array($method, self::METHODS, true)) {
            throw ValidationException::one('_', 'payment.error.method');
        }
        if ($totalPaise === 0) {
            return [];
        }
        return match ($method) {
            'cash' => [['mode' => 'cash', 'amount' => $totalPaise]],
            'upi' => [['mode' => 'upi_counter', 'amount' => $totalPaise]],
            'mixed' => $cashPaise > 0 && $cashPaise < $totalPaise
                ? [['mode' => 'cash', 'amount' => $cashPaise], ['mode' => 'upi_counter', 'amount' => $totalPaise - $cashPaise]]
                : throw ValidationException::one('_', 'payment.error.mixed'),
        };
    }

    /** Change to give back, or null when the customer handed over too little. */
    public static function change(int $totalPaise, int $tenderedPaise): ?int
    {
        return $tenderedPaise >= $totalPaise ? $tenderedPaise - $totalPaise : null;
    }

    /**
     * Saves payment rows (inside the caller's transaction) for an order, or for a table visit
     * ($sessionId, with $orderId null). $at is when the money was received: the day summary
     * counts payments by this date (a bulk advance belongs to the day it was paid).
     */
    public function record(?int $orderId, array $rows, int $staffId, DateTimeImmutable $at, ?int $sessionId = null): void
    {
        $insert = $this->db->prepare(
            "INSERT INTO payments (order_id, table_session_id, mode, amount, status, staff_id, created_at)
             VALUES (?, ?, ?, ?, 'success', ?, ?)"
        );
        foreach ($rows as $row) {
            $insert->execute([$orderId, $sessionId, $row['mode'], decimal_from_paise($row['amount']), $staffId, $at->format('Y-m-d H:i:s')]);
        }
    }
}
