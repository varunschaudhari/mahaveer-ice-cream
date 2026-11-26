<?php

declare(strict_types=1);

namespace App\Services;

use DomainException;
use InvalidArgumentException;

/**
 * Order totals in integer paise. Pure functions: no database, no globals.
 * total = round_to_rupee(subtotal + parcel_charge − discount)   (SPEC §5)
 */
final class PricingService
{
    public const ORDER_TYPES = ['counter', 'takeaway', 'table', 'bulk'];

    /**
     * Price of one order line. Quantity is a decimal string with up to 2 places because bulk
     * orders sell by weight ('2.5' kg). Fractions of a paisa round half-up.
     */
    public static function lineTotal(int $unitPaise, string $qty): int
    {
        $qtyHundredths = decimal_to_hundredths($qty);
        if ($unitPaise < 0) {
            throw new InvalidArgumentException('Unit price cannot be negative');
        }
        if ($qtyHundredths <= 0) {
            throw new InvalidArgumentException("Quantity must be more than zero: '{$qty}'");
        }
        return intdiv($unitPaise * $qtyHundredths + 50, 100);
    }

    /**
     * Takeaway always pays the parcel charge; counter bills only when staff choose Parcel;
     * table and bulk orders never.
     */
    public static function parcelApplies(string $orderType, bool $parcelChosen): bool
    {
        return match ($orderType) {
            'takeaway' => true,
            'counter' => $parcelChosen,
            'table', 'bulk' => false,
            default => throw new InvalidArgumentException("Unknown order type: {$orderType}"),
        };
    }

    /** Rounds a non-negative paise amount to the nearest whole rupee; 50 paise and above round up. */
    public static function roundToRupee(int $paise): int
    {
        if ($paise < 0) {
            throw new InvalidArgumentException('Cannot round a negative amount');
        }
        return intdiv($paise + 50, 100) * 100;
    }

    /**
     * Lines keep exact paise; only the final total is rounded to the rupee. round_off is the
     * difference (−49…+50 paise), printed on the bill when non-zero and never stored.
     *
     * @param list<array{unit_paise: int, qty: string}> $lines
     * @param int $parcelChargePaise the configured charge (settings.parcel_charge); applied per the rules above
     * @param int $discountPaise flat discount (owner only — the caller checks the role)
     * @return array{lines: list<int>, subtotal: int, parcel_charge: int, discount: int, round_off: int, total: int}
     */
    public static function totals(
        string $orderType,
        array $lines,
        bool $parcelChosen,
        int $parcelChargePaise,
        int $discountPaise = 0,
    ): array {
        if ($lines === []) {
            throw new InvalidArgumentException('An order needs at least one line');
        }
        if ($parcelChargePaise < 0 || $discountPaise < 0) {
            throw new InvalidArgumentException('Parcel charge and discount cannot be negative');
        }

        $lineTotals = [];
        foreach ($lines as $line) {
            $lineTotals[] = self::lineTotal($line['unit_paise'], $line['qty']);
        }
        $subtotal = array_sum($lineTotals);
        $parcel = self::parcelApplies($orderType, $parcelChosen) ? $parcelChargePaise : 0;

        if ($discountPaise > $subtotal + $parcel) {
            throw new DomainException('Discount cannot be more than the bill amount');
        }

        $exact = $subtotal + $parcel - $discountPaise;
        $total = self::roundToRupee($exact);

        return [
            'lines' => $lineTotals,
            'subtotal' => $subtotal,
            'parcel_charge' => $parcel,
            'discount' => $discountPaise,
            'round_off' => $total - $exact,
            'total' => $total,
        ];
    }
}
