<?php

declare(strict_types=1);

namespace App\Services;

use App\Db;
use App\Settings;
use DateTimeImmutable;
use PDO;

/**
 * Takeaway pickup slots (SPEC §6.4, settings in §14): today only, slot_mins long, the earliest
 * starts at least min_lead_mins from now, the last one ends last_order_mins before closing.
 * A slot holding slot_capacity orders (not cancelled / no-show) is full.
 */
final class SlotService
{
    private readonly PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Db::pdo();
    }

    /**
     * Pure: start times a customer can still choose at $now. Empty before opening and after the
     * last-order time (the page then shows the "closed" banner).
     *
     * @return list<DateTimeImmutable>
     */
    public static function slotStarts(
        DateTimeImmutable $now,
        string $openTime,
        string $closeTime,
        int $lastOrderMins,
        int $slotMins,
        int $minLeadMins,
    ): array {
        $slotMins = max(5, $slotMins);
        $openAt = self::at($now, $openTime);
        $lastEnd = self::at($now, $closeTime)->modify("-{$lastOrderMins} minutes");
        if ($now < $openAt || $now >= $lastEnd) {
            return [];
        }

        $earliest = $now->modify("+{$minLeadMins} minutes");
        $starts = [];
        for ($start = $openAt; $start->modify("+{$slotMins} minutes") <= $lastEnd; $start = $start->modify("+{$slotMins} minutes")) {
            if ($start >= $earliest) {
                $starts[] = $start;
            }
        }
        return $starts;
    }

    /**
     * Today's choosable slots with how full each one is.
     *
     * @return array{open: bool, opens_at: string, slots: list<array{start: string, end: string, at: string, count: int, full: bool}>}
     */
    public function today(DateTimeImmutable $now): array
    {
        $slotMins = max(5, Settings::int('slot_mins', 15));
        $capacity = Settings::int('slot_capacity', 10);
        $starts = self::slotStarts(
            $now,
            Settings::get('open_time', '10:00'),
            Settings::get('close_time', '22:30'),
            Settings::int('last_order_mins', 30),
            $slotMins,
            Settings::int('min_lead_mins', 15),
        );

        $counts = $this->countsFor($now);
        $slots = [];
        foreach ($starts as $start) {
            $at = $start->format('Y-m-d H:i:s');
            $count = $counts[$at] ?? 0;
            $slots[] = [
                'start' => $start->format('H:i'),
                'end' => $start->modify("+{$slotMins} minutes")->format('H:i'),
                'at' => $at,
                'count' => $count,
                'full' => $count >= $capacity,
            ];
        }

        return ['open' => $slots !== [], 'opens_at' => Settings::get('open_time', '10:00'), 'slots' => $slots];
    }

    /** Orders already booked for one slot (call inside the order transaction, after taking the token lock). */
    public function countAt(string $pickupAt): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM orders
             WHERE order_type = 'takeaway' AND pickup_at = ? AND status NOT IN ('cancelled', 'no_show')"
        );
        $stmt->execute([$pickupAt]);
        return (int) $stmt->fetchColumn();
    }

    /** @return array<string, int> pickup_at → booked orders, for $now's day */
    private function countsFor(DateTimeImmutable $now): array
    {
        $stmt = $this->db->prepare(
            "SELECT pickup_at, COUNT(*) AS n FROM orders
             WHERE order_type = 'takeaway' AND pickup_at >= ? AND pickup_at < ?
               AND status NOT IN ('cancelled', 'no_show')
             GROUP BY pickup_at"
        );
        $day = $now->setTime(0, 0);
        $stmt->execute([$day->format('Y-m-d H:i:s'), $day->modify('+1 day')->format('Y-m-d H:i:s')]);
        return array_map('intval', array_column($stmt->fetchAll(), 'n', 'pickup_at'));
    }

    private static function at(DateTimeImmutable $day, string $hhmm): DateTimeImmutable
    {
        [$hour, $minute] = array_map('intval', explode(':', $hhmm) + [1 => '0']);
        return $day->setTime($hour, $minute);
    }
}
