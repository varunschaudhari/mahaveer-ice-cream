<?php

declare(strict_types=1);

namespace Tests;

use App\Services\OrderCard;
use DateTimeImmutable;

final class OrderCardTest extends DatabaseTestCase
{
    /** A row as OrderService::activeOnline() returns it. */
    private static function order(?string $pickupAt): array
    {
        return [
            'id' => 1, 'order_type' => $pickupAt === null ? 'table' : 'takeaway', 'table_code' => $pickupAt === null ? 'T2' : null,
            'token_no' => 7, 'status' => 'received', 'pickup_at' => $pickupAt,
            'customer_name' => 'Asha', 'customer_mobile' => '9876500000', 'items' => [],
            'total' => '60.00', 'note' => null, 'created_at' => '2026-11-27 18:00:00',
        ];
    }

    public function testLateOnlyAfterThePickupWindowHasEnded(): void
    {
        $now = new DateTimeImmutable('2026-11-27 18:40:00');
        // slot_mins = 15 (seed): the 18:15–18:30 window is over, 18:30–18:45 is still running.
        self::assertTrue(OrderCard::make(self::order('2026-11-27 18:15:00'), $now)['late']);
        self::assertFalse(OrderCard::make(self::order('2026-11-27 18:30:00'), $now)['late']);
        self::assertFalse(OrderCard::make(self::order(null), $now)['late'], 'table orders have no pickup time');
    }
}
