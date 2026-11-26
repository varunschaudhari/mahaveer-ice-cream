<?php

declare(strict_types=1);

namespace App\Services;

use App\Db;
use App\ValidationException;
use PDO;

/**
 * Checks cart lines sent by a browser against the database and prices them from the database.
 * Prices sent by the browser are never used. Counter carts (M2) and online carts (M3) share it.
 */
final class CartService
{
    public const MAX_QTY = 50;
    public const MAX_LINES = 50;

    private readonly PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Db::pdo();
    }

    /** Bulk orders: quantities in hundredths (kg, packs), 0.01 … 999.99 (ARCHITECTURE §11). */
    public const MAX_BULK_QTY_HUNDREDTHS = 99999;

    /**
     * @param array $lines list of ['variant_id' => int, 'qty' => int|string]; repeated variants are merged
     * @param string $channel 'counter', 'online' (also needs items.online_enabled) or 'bulk'
     *        (online items, decimal quantities, sold-out ignored: the event is days away)
     * @return list<array{variant_id: int, item_name: string, unit_paise: int, qty: string}>
     */
    public function validate(array $lines, string $channel = 'counter'): array
    {
        if ($lines === []) {
            throw ValidationException::one('_', 'cart.error.empty');
        }
        if (count($lines) > self::MAX_LINES) {
            throw ValidationException::one('_', 'cart.error.too_many');
        }

        $isBulk = $channel === 'bulk';
        $max = $isBulk ? self::MAX_BULK_QTY_HUNDREDTHS : self::MAX_QTY * 100;
        $qtyError = $isBulk ? 'cart.error.bulk_qty' : ['cart.error.qty', ['max' => self::MAX_QTY]];

        // Quantities are counted in hundredths so 2.5 kg and 3 packs use the same code.
        $qtyByVariant = [];
        foreach ($lines as $line) {
            $variantId = is_array($line) ? filter_var($line['variant_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
            $hundredths = is_array($line) ? self::parseQty($line['qty'] ?? null, $isBulk) : null;
            if ($variantId === false || $hundredths === null || $hundredths < 1) {
                throw ValidationException::one('_', $qtyError);
            }
            $qtyByVariant[$variantId] = ($qtyByVariant[$variantId] ?? 0) + $hundredths;
            if ($qtyByVariant[$variantId] > $max) {
                throw ValidationException::one('_', $qtyError);
            }
        }

        $ids = array_keys($qtyByVariant);
        $stmt = $this->db->prepare(
            'SELECT v.id, v.label_mr, v.label_en, v.price, v.is_active AS variant_active,
                    i.name_mr, i.name_en, i.is_active AS item_active, i.is_available, i.online_enabled,
                    c.is_active AS category_active
             FROM item_variants v
             JOIN items i ON i.id = v.item_id
             JOIN categories c ON c.id = i.category_id
             WHERE v.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'
        );
        $stmt->execute($ids);
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[(int) $row['id']] = $row;
        }

        $priced = [];
        foreach ($qtyByVariant as $variantId => $qty) {
            $row = $rows[$variantId] ?? null;
            if ($row === null) {
                throw ValidationException::one('_', 'cart.error.unknown');
            }
            $price = paise_from_decimal((string) $row['price']);
            $sellable = $row['variant_active'] && $row['item_active'] && $row['category_active'] && $price > 0
                && ($channel === 'counter' || $row['online_enabled']);
            if (!$sellable) {
                throw ValidationException::one('_', ['cart.error.unavailable', ['item' => $row['name_mr']]]);
            }
            if (!$isBulk && !$row['is_available']) {
                throw ValidationException::one('_', ['cart.error.sold_out', ['item' => $row['name_mr']]]);
            }

            $priced[] = [
                'variant_id' => $variantId,
                'item_name' => self::snapshotName($row),
                'unit_paise' => $price,
                'qty' => $qty % 100 === 0 ? (string) intdiv($qty, 100) : hundredths_to_decimal($qty),
            ];
        }
        return $priced;
    }

    /**
     * Quantity → hundredths, or null. Normal orders: whole numbers only. Bulk: up to 2 decimals
     * ('2.5' kg → 250). Accepts ints and numeric strings from JSON / forms.
     */
    public static function parseQty(mixed $qty, bool $allowDecimals): ?int
    {
        if (is_int($qty)) {
            return $qty * 100;
        }
        if (is_float($qty) && $allowDecimals) {
            $qty = (string) round($qty, 2);
        }
        if (!is_string($qty)) {
            return null;
        }
        $qty = trim($qty);
        if (!preg_match($allowDecimals ? '/^\d{1,3}(\.\d{1,2})?$/' : '/^\d{1,3}$/', $qty)) {
            return null;
        }
        return decimal_to_hundredths($qty);
    }

    /**
     * Name saved on the order line, both languages: "बदाम शेक (फुल) / Badam Shake (Full)".
     * Bills print the Marathi part (see marathi_name()); price changes never alter it.
     */
    public static function snapshotName(array $row): string
    {
        $name = "{$row['name_mr']} ({$row['label_mr']}) / {$row['name_en']} ({$row['label_en']})";
        return mb_substr($name, 0, 150);
    }
}
