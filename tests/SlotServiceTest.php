<?php

declare(strict_types=1);

namespace Tests;

use App\Services\SlotService;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Pickup slot times (SPEC §6.4) with the seeded settings: 10:00–22:30, last order 30 min, 15 min slots, 15 min lead. */
final class SlotServiceTest extends TestCase
{
    /** @return list<string> 'H:i' starts at the given time */
    private function starts(string $now): array
    {
        $slots = SlotService::slotStarts(new DateTimeImmutable("2026-11-26 {$now}"), '10:00', '22:30', 30, 15, 15);
        return array_map(static fn (DateTimeImmutable $s): string => $s->format('H:i'), $slots);
    }

    public static function earliestSlots(): array
    {
        return [
            'on a slot boundary: now + 15' => ['19:00', '19:15'],
            'just after: rounded up' => ['19:02', '19:30'],
            'seconds count too' => ['19:00:30', '19:30'],
            'right at opening' => ['10:00', '10:15'],
            'late afternoon' => ['16:44', '17:00'],
        ];
    }

    #[DataProvider('earliestSlots')]
    public function testEarliestSlotIsNowPlusFifteenRoundedUp(string $now, string $first): void
    {
        self::assertSame($first, $this->starts($now)[0]);
    }

    public function testLastSlotIsNineFortyFiveToTen(): void
    {
        $starts = $this->starts('21:00');
        self::assertSame(['21:15', '21:30', '21:45'], $starts);
    }

    public function testClosedBeforeOpeningAndAfterLastOrders(): void
    {
        self::assertSame([], $this->starts('08:30'), 'before opening');
        self::assertSame([], $this->starts('22:05'), '10:05 PM – the M3 check');
        self::assertSame([], $this->starts('21:50'), 'no slot left after the 15 min lead');
        self::assertSame(['21:45'], $this->starts('21:30'));
    }

    public function testFullDayHasFortySevenSlots(): void
    {
        // 10:15 … 21:45 = 47 slots when ordering right at opening
        self::assertCount(47, $this->starts('10:00'));
    }

    public function testOtherSettingsAreHonoured(): void
    {
        $slots = SlotService::slotStarts(new DateTimeImmutable('2026-11-26 18:10'), '11:00', '21:00', 60, 30, 30);
        self::assertSame(['19:00', '19:30'], array_map(static fn ($s) => $s->format('H:i'), $slots));
    }
}
