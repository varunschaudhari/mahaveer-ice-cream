<?php

declare(strict_types=1);

namespace Tests;

use App\Services\PricingService;
use DomainException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PricingServiceTest extends TestCase
{
    private const PARCEL = 500;       // settings.parcel_charge = ₹5
    private const BADAM_FULL = 8000;  // Badam Shake Full ₹80
    private const VANILLA_HALF = 1500;

    // ---------- line totals ----------

    public function testLineTotalWholeQuantity(): void
    {
        self::assertSame(16000, PricingService::lineTotal(self::BADAM_FULL, '2'));
        self::assertSame(1500, PricingService::lineTotal(self::VANILLA_HALF, '1'));
    }

    public function testLineTotalDecimalQuantityForBulk(): void
    {
        self::assertSame(12500, PricingService::lineTotal(5000, '2.5'));   // 2.5 kg × ₹50
        self::assertSame(3750, PricingService::lineTotal(5000, '0.75'));
    }

    public static function roundingCases(): array
    {
        return [
            'exactly half a paisa rounds up' => [1, '0.50', 1],
            'just under half rounds down' => [1, '0.49', 0],
            '₹33.33 × 1.5 = ₹49.995 → ₹50.00' => [3333, '1.5', 5000],
            '₹33.33 × 1.49 = ₹49.6617 → ₹49.66' => [3333, '1.49', 4966],
        ];
    }

    #[DataProvider('roundingCases')]
    public function testLineTotalRoundsHalfUpToThePaisa(int $unit, string $qty, int $expected): void
    {
        self::assertSame($expected, PricingService::lineTotal($unit, $qty));
    }

    public static function badLines(): array
    {
        return [
            'zero qty' => [1500, '0'],
            'negative qty' => [1500, '-1'],
            'three decimals' => [1500, '1.234'],
            'not a number' => [1500, 'two'],
            'negative price' => [-1500, '1'],
        ];
    }

    #[DataProvider('badLines')]
    public function testLineTotalRejectsBadInput(int $unit, string $qty): void
    {
        $this->expectException(InvalidArgumentException::class);
        PricingService::lineTotal($unit, $qty);
    }

    // ---------- parcel charge rules (SPEC §5) ----------

    public static function parcelRules(): array
    {
        return [
            'takeaway always' => ['takeaway', false, true],
            'takeaway with parcel flag' => ['takeaway', true, true],
            'counter dine-in' => ['counter', false, false],
            'counter parcel' => ['counter', true, true],
            'table never' => ['table', true, false],
            'bulk never' => ['bulk', true, false],
        ];
    }

    #[DataProvider('parcelRules')]
    public function testParcelApplies(string $type, bool $parcelChosen, bool $expected): void
    {
        self::assertSame($expected, PricingService::parcelApplies($type, $parcelChosen));
    }

    public function testUnknownOrderTypeIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PricingService::parcelApplies('delivery', false);
    }

    // ---------- totals ----------

    private static function sampleLines(): array
    {
        return [
            ['unit_paise' => self::BADAM_FULL, 'qty' => '2'],
            ['unit_paise' => self::VANILLA_HALF, 'qty' => '1'],
        ];
    }

    public function testCounterParcelBill(): void
    {
        // 2× Badam Shake Full + 1× Vanilla Half, Parcel
        $t = PricingService::totals('counter', self::sampleLines(), true, self::PARCEL);

        self::assertSame([16000, 1500], $t['lines']);
        self::assertSame(17500, $t['subtotal']);
        self::assertSame(500, $t['parcel_charge']);
        self::assertSame(0, $t['discount']);
        self::assertSame(0, $t['round_off']);
        self::assertSame(18000, $t['total']);
    }

    public function testCounterParcelBillChangeFromTwoHundred(): void
    {
        // BUILD_PLAN M2 check: cash ₹200 for this bill → change ₹20
        $t = PricingService::totals('counter', self::sampleLines(), true, self::PARCEL);
        self::assertSame(2000, 20000 - $t['total']);
    }

    public function testCounterDineInBillHasNoParcelCharge(): void
    {
        $t = PricingService::totals('counter', self::sampleLines(), false, self::PARCEL);
        self::assertSame(0, $t['parcel_charge']);
        self::assertSame(17500, $t['total']);
    }

    public function testTakeawayAddsParcelChargeOncePerOrder(): void
    {
        $t = PricingService::totals('takeaway', self::sampleLines(), false, self::PARCEL);
        self::assertSame(500, $t['parcel_charge']);
        self::assertSame(18000, $t['total']);
    }

    public function testTableAndBulkNeverPayParcelCharge(): void
    {
        foreach (['table', 'bulk'] as $type) {
            $t = PricingService::totals($type, self::sampleLines(), true, self::PARCEL);
            self::assertSame(0, $t['parcel_charge'], $type);
            self::assertSame(17500, $t['total'], $type);
        }
    }

    public function testDiscountIsSubtracted(): void
    {
        $t = PricingService::totals('counter', self::sampleLines(), true, self::PARCEL, 1000);
        self::assertSame(1000, $t['discount']);
        self::assertSame(17000, $t['total']);
    }

    public function testDiscountMayCoverTheWholeBill(): void
    {
        $t = PricingService::totals('counter', self::sampleLines(), true, self::PARCEL, 18000);
        self::assertSame(0, $t['total']);
    }

    public function testDiscountLargerThanBillIsRejected(): void
    {
        $this->expectException(DomainException::class);
        PricingService::totals('counter', self::sampleLines(), true, self::PARCEL, 18001);
    }

    public function testNegativeDiscountIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PricingService::totals('counter', self::sampleLines(), false, self::PARCEL, -100);
    }

    public function testEmptyOrderIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PricingService::totals('takeaway', [], false, self::PARCEL);
    }

    public function testBulkTotalWithDecimalQuantities(): void
    {
        $lines = [
            ['unit_paise' => 5000, 'qty' => '3'],      // 3 kg
            ['unit_paise' => 20000, 'qty' => '2'],     // 2× Shrikhand 500 g
        ];
        $t = PricingService::totals('bulk', $lines, false, self::PARCEL);
        self::assertSame(55000, $t['total']);
    }

    // ---------- rounding the total to the rupee (SPEC §5) ----------

    public static function rupeeRounding(): array
    {
        return [
            [0, 0], [18000, 18000], [8349, 8300], [8350, 8400], [8301, 8300], [8399, 8400], [49, 0], [50, 100],
        ];
    }

    #[DataProvider('rupeeRounding')]
    public function testRoundToRupee(int $paise, int $expected): void
    {
        self::assertSame($expected, PricingService::roundToRupee($paise));
    }

    public function testTotalRoundsDownWithNegativeRoundOff(): void
    {
        // 2.5 kg × ₹33.33 = ₹83.325 → line ₹83.33 → total ₹83, round off −₹0.33
        $t = PricingService::totals('bulk', [['unit_paise' => 3333, 'qty' => '2.5']], false, self::PARCEL);
        self::assertSame([8333], $t['lines']);
        self::assertSame(8333, $t['subtotal']);
        self::assertSame(-33, $t['round_off']);
        self::assertSame(8300, $t['total']);
    }

    public function testTotalRoundsUpFromFiftyPaise(): void
    {
        // 2.5 kg × ₹33.40 = ₹83.50 → total ₹84, round off +₹0.50
        $t = PricingService::totals('bulk', [['unit_paise' => 3340, 'qty' => '2.5']], false, self::PARCEL);
        self::assertSame(50, $t['round_off']);
        self::assertSame(8400, $t['total']);
    }

    public function testRoundingHappensAfterDiscount(): void
    {
        // ₹83.33 − ₹3 discount = ₹80.33 → ₹80
        $t = PricingService::totals('bulk', [['unit_paise' => 3333, 'qty' => '2.5']], false, self::PARCEL, 300);
        self::assertSame(-33, $t['round_off']);
        self::assertSame(8000, $t['total']);
    }
}
