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

    /**
     * @param array $lines list of ['variant_id' => int, 'qty' => int]; repeated variants are merged
     * @param string $channel 'counter' or 'online' (online also needs items.online_enabled)
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

        $qtyByVariant = [];
        foreach ($lines as $line) {
            $variantId = is_array($line) ? filter_var($line['variant_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
            $qty = is_array($line) ? filter_var($line['qty'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
            if ($variantId === false || $qty === false) {
                throw ValidationException::one('_', ['cart.error.qty', ['max' => self::MAX_QTY]]);
            }
            $qtyByVariant[$variantId] = ($qtyByVariant[$variantId] ?? 0) + $qty;
            if ($qtyByVariant[$variantId] > self::MAX_QTY) {
                throw ValidationException::one('_', ['cart.error.qty', ['max' => self::MAX_QTY]]);
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
                && ($channel !== 'online' || $row['online_enabled']);
            if (!$sellable) {
                throw ValidationException::one('_', ['cart.error.unavailable', ['item' => $row['name_mr']]]);
            }
            if (!$row['is_available']) {
                throw ValidationException::one('_', ['cart.error.sold_out', ['item' => $row['name_mr']]]);
            }

            $priced[] = [
                'variant_id' => $variantId,
                'item_name' => self::snapshotName($row),
                'unit_paise' => $price,
                'qty' => (string) $qty,
            ];
        }
        return $priced;
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
