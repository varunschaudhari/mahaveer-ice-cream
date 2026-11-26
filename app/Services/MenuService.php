<?php

declare(strict_types=1);

namespace App\Services;

use App\Db;
use PDO;

final class MenuService
{
    private readonly PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Db::pdo();
    }

    /**
     * Menu for customers (order pages and website): active categories → active, online-enabled
     * items → active variants priced above ₹0. Items left with no sellable variant and empty
     * categories are dropped. Sold-out items stay in the list with is_available = false.
     *
     * @return list<array{id:int, name_mr:string, name_en:string, items: list<array{
     *     id:int, name_mr:string, name_en:string, photo:?string, is_available:bool,
     *     variants: list<array{id:int, label_mr:string, label_en:string, price_paise:int}>}>}>
     */
    public function publicMenu(): array
    {
        $rows = $this->db->query(
            'SELECT c.id AS c_id, c.name_mr AS c_name_mr, c.name_en AS c_name_en,
                    i.id AS i_id, i.name_mr AS i_name_mr, i.name_en AS i_name_en,
                    i.photo, i.is_available,
                    v.id AS v_id, v.label_mr, v.label_en, v.price
             FROM categories c
             JOIN items i         ON i.category_id = c.id
             JOIN item_variants v ON v.item_id = i.id
             WHERE c.is_active = 1
               AND i.is_active = 1 AND i.online_enabled = 1
               AND v.is_active = 1 AND v.price > 0
             ORDER BY c.sort_order, c.id, i.sort_order, i.id, v.sort_order, v.id'
        )->fetchAll();

        $menu = [];
        foreach ($rows as $r) {
            $cid = (int) $r['c_id'];
            $iid = (int) $r['i_id'];

            $menu[$cid] ??= [
                'id' => $cid,
                'name_mr' => $r['c_name_mr'],
                'name_en' => $r['c_name_en'],
                'items' => [],
            ];
            $menu[$cid]['items'][$iid] ??= [
                'id' => $iid,
                'name_mr' => $r['i_name_mr'],
                'name_en' => $r['i_name_en'],
                'photo' => $r['photo'],
                'is_available' => (bool) $r['is_available'],
                'variants' => [],
            ];
            $menu[$cid]['items'][$iid]['variants'][] = [
                'id' => (int) $r['v_id'],
                'label_mr' => $r['label_mr'],
                'label_en' => $r['label_en'],
                'price_paise' => paise_from_decimal((string) $r['price']),
            ];
        }

        // Re-index to plain lists so the shape is the same in PHP and in JSON.
        return array_values(array_map(static function (array $category): array {
            $category['items'] = array_values($category['items']);
            return $category;
        }, $menu));
    }
}
