<?php

declare(strict_types=1);

namespace App\Services;

use App\Db;
use App\Settings;
use App\ValidationException;
use DateTimeImmutable;
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
        return $this->sellableMenu(true);
    }

    /** Counter billing menu: same shape as publicMenu(), plus counter-only items (online_enabled = 0). */
    public function counterMenu(): array
    {
        return $this->sellableMenu(false);
    }

    private function sellableMenu(bool $onlineOnly): array
    {
        $onlineFilter = $onlineOnly ? 'AND i.online_enabled = 1' : '';
        $rows = $this->db->query(
            'SELECT c.id AS c_id, c.name_mr AS c_name_mr, c.name_en AS c_name_en,
                    i.id AS i_id, i.name_mr AS i_name_mr, i.name_en AS i_name_en,
                    i.photo, i.is_available,
                    v.id AS v_id, v.label_mr, v.label_en, v.price
             FROM categories c
             JOIN items i         ON i.category_id = c.id
             JOIN item_variants v ON v.item_id = i.id
             WHERE c.is_active = 1
               AND i.is_active = 1 ' . $onlineFilter . '
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

    // ---------------------------------------------------------------
    // Daily stock (counter Stock tab)
    // ---------------------------------------------------------------

    /** Sold-out switch for today (SPEC §9 Stock tab), audit logged. Only active items. */
    public function setAvailability(int $itemId, bool $available, int $staffId): void
    {
        $stmt = $this->db->prepare('SELECT id, name_mr, is_available FROM items WHERE id = ? AND is_active = 1');
        $stmt->execute([$itemId]);
        $item = $stmt->fetch() ?: throw ValidationException::one('_', 'menu.error.not_found');
        if ((bool) $item['is_available'] === $available) {
            return;
        }
        Db::transaction(function () use ($item, $available, $staffId): void {
            $this->db->prepare('UPDATE items SET is_available = ? WHERE id = ?')->execute([$available ? 1 : 0, $item['id']]);
            $this->audit()->log($staffId, 'stock_toggle', 'item', (int) $item['id'], [
                'item' => $item['name_mr'],
                'available' => $available,
            ]);
        });
    }

    /**
     * Sold-out marks last one day: the first call on a new day makes every item available again.
     * The last reset date is kept in settings ('stock_reset_date'). Called by the menu pages and
     * the counter before they read availability, so no cron job is needed.
     *
     * @return bool true when this call did the reset
     */
    public function resetStockIfNewDay(?DateTimeImmutable $now = null): bool
    {
        $today = ($now ?? now())->format('Y-m-d');
        $stmt = $this->db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'stock_reset_date'");
        $stmt->execute();
        if ($stmt->fetchColumn() === $today) {
            return false;
        }

        return Db::transaction(function () use ($today): bool {
            // Lock the marker row so two requests right after midnight reset only once.
            $this->db->exec("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('stock_reset_date', '')");
            $last = $this->db->query("SELECT setting_value FROM settings WHERE setting_key = 'stock_reset_date' FOR UPDATE")->fetchColumn();
            if ($last === $today) {
                return false;
            }
            $count = $this->db->exec('UPDATE items SET is_available = 1 WHERE is_available = 0');
            $this->db->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'stock_reset_date'")->execute([$today]);
            if ($count > 0) {
                $this->audit()->log(null, 'stock_reset', 'item', null, ['items' => $count]);
            }
            Settings::clearCache();
            return true;
        });
    }

    // ---------------------------------------------------------------
    // Admin (owner): every row, including inactive ones
    // ---------------------------------------------------------------

    public function allCategories(): array
    {
        return $this->db->query(
            'SELECT c.*, (SELECT COUNT(*) FROM items i WHERE i.category_id = c.id) AS item_count
             FROM categories c ORDER BY c.sort_order, c.id'
        )->fetchAll();
    }

    public function findCategory(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM categories WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /** @return array<int, list<array>> category id → items (each with 'variants', prices in paise) */
    public function allItemsByCategory(): array
    {
        $variants = [];
        foreach ($this->db->query('SELECT * FROM item_variants ORDER BY item_id, sort_order, id') as $variant) {
            $variant['price_paise'] = paise_from_decimal((string) $variant['price']);
            $variants[(int) $variant['item_id']][] = $variant;
        }

        $byCategory = [];
        foreach ($this->db->query('SELECT * FROM items ORDER BY category_id, sort_order, id') as $item) {
            $item['variants'] = $variants[(int) $item['id']] ?? [];
            $byCategory[(int) $item['category_id']][] = $item;
        }
        return $byCategory;
    }

    public function findItem(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM items WHERE id = ?');
        $stmt->execute([$id]);
        $item = $stmt->fetch();
        if (!$item) {
            return null;
        }

        $stmt = $this->db->prepare('SELECT * FROM item_variants WHERE item_id = ? ORDER BY sort_order, id');
        $stmt->execute([$id]);
        $item['variants'] = array_map(static function (array $variant): array {
            $variant['price_paise'] = paise_from_decimal((string) $variant['price']);
            return $variant;
        }, $stmt->fetchAll());
        return $item;
    }

    public function saveCategory(?int $id, array $input, int $staffId): int
    {
        $data = [
            'name_mr' => trim((string) ($input['name_mr'] ?? '')),
            'name_en' => trim((string) ($input['name_en'] ?? '')),
            'sort_order' => trim((string) ($input['sort_order'] ?? '0')),
            'is_active' => empty($input['is_active']) ? 0 : 1,
        ];
        $errors = $this->nameErrors($data);
        if (!preg_match('/^\d{1,4}$/', $data['sort_order'])) {
            $errors['sort_order'] = 'menu.error.sort_order';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $data['sort_order'] = (int) $data['sort_order'];

        return Db::transaction(function () use ($id, $data, $staffId): int {
            if ($id === null) {
                $this->db->prepare('INSERT INTO categories (name_mr, name_en, sort_order, is_active) VALUES (?, ?, ?, ?)')
                    ->execute([$data['name_mr'], $data['name_en'], $data['sort_order'], $data['is_active']]);
                $newId = (int) $this->db->lastInsertId();
                $this->audit()->log($staffId, 'category_create', 'category', $newId, ['after' => $data]);
                return $newId;
            }

            $before = $this->findCategory($id) ?? throw ValidationException::one('_', 'menu.error.not_found');
            $this->db->prepare('UPDATE categories SET name_mr = ?, name_en = ?, sort_order = ?, is_active = ? WHERE id = ?')
                ->execute([$data['name_mr'], $data['name_en'], $data['sort_order'], $data['is_active'], $id]);
            $diff = self::diff($before, $data);
            if ($diff !== []) {
                $this->audit()->log($staffId, 'category_update', 'category', $id, $diff + ['name' => $data['name_mr']]);
            }
            return $id;
        });
    }

    /**
     * Saves an item and its variants in one transaction.
     *
     * Rule (SPEC §5, §12): an active item must be sellable — at least one active variant and no
     * active variant priced ₹0. This is what refuses activating the Kulfi placeholders.
     *
     * @param array $input name_mr, name_en, category_id, sort_order, is_active, online_enabled
     * @param array<string, array> $variants variant id, or 'new…' for new rows → label_mr, label_en,
     *        price (rupees as typed), sort_order, is_active. Blank new rows are ignored.
     * @param ?string $newPhoto file already stored by ImageService (null = keep current)
     * @return array{id: int, old_photo: ?string} old_photo is a file the caller should now delete
     */
    public function saveItem(
        ?int $id,
        array $input,
        array $variants,
        int $staffId,
        ?string $newPhoto = null,
        bool $removePhoto = false,
    ): array {
        $existing = $id === null ? null : ($this->findItem($id) ?? throw ValidationException::one('_', 'menu.error.not_found'));

        $item = [
            'category_id' => (int) ($input['category_id'] ?? 0),
            'name_mr' => trim((string) ($input['name_mr'] ?? '')),
            'name_en' => trim((string) ($input['name_en'] ?? '')),
            'sort_order' => trim((string) ($input['sort_order'] ?? '0')),
            'is_active' => empty($input['is_active']) ? 0 : 1,
            'online_enabled' => empty($input['online_enabled']) ? 0 : 1,
        ];
        $errors = $this->nameErrors($item);
        if ($this->findCategory($item['category_id']) === null) {
            $errors['category_id'] = 'menu.error.category';
        }
        if (!preg_match('/^\d{1,4}$/', $item['sort_order'])) {
            $errors['sort_order'] = 'menu.error.sort_order';
        }

        [$rows, $variantErrors] = $this->parseVariants($variants, $existing['variants'] ?? []);
        $errors += $variantErrors;

        if ($item['is_active'] && $variantErrors === []) {
            $active = array_filter($rows, static fn (array $row): bool => (bool) $row['is_active']);
            if ($active === []) {
                $errors['_'] = 'menu.error.active_needs_variant';
            }
            foreach ($active as $row) {
                if ($row['price_paise'] === 0) {
                    $errors['_'] = 'menu.error.zero_price_active';
                    $errors["variants.{$row['key']}.price"] = 'menu.error.zero_price_active';
                }
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $item['sort_order'] = (int) $item['sort_order'];

        return Db::transaction(function () use ($existing, $item, $rows, $staffId, $newPhoto, $removePhoto): array {
            $photoBefore = $existing['photo'] ?? null;
            $item['photo'] = $newPhoto ?? ($removePhoto ? null : $photoBefore);

            if ($existing === null) {
                $this->db->prepare(
                    'INSERT INTO items (category_id, name_mr, name_en, photo, online_enabled, sort_order, is_active)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                )->execute([$item['category_id'], $item['name_mr'], $item['name_en'], $item['photo'],
                    $item['online_enabled'], $item['sort_order'], $item['is_active']]);
                $itemId = (int) $this->db->lastInsertId();
                $this->audit()->log($staffId, 'item_create', 'item', $itemId, ['after' => $item]);
            } else {
                $itemId = (int) $existing['id'];
                $this->db->prepare(
                    'UPDATE items SET category_id = ?, name_mr = ?, name_en = ?, photo = ?, online_enabled = ?,
                            sort_order = ?, is_active = ?
                     WHERE id = ?'
                )->execute([$item['category_id'], $item['name_mr'], $item['name_en'], $item['photo'],
                    $item['online_enabled'], $item['sort_order'], $item['is_active'], $itemId]);
                $diff = self::diff($existing, $item);
                if ($diff !== []) {
                    $this->audit()->log($staffId, 'item_update', 'item', $itemId, $diff + ['name' => $item['name_mr']]);
                }
            }

            foreach ($rows as $row) {
                if ($row['unchanged']) {
                    continue;
                }
                $this->saveVariant($itemId, $item['name_mr'], $row, $staffId);
            }

            return ['id' => $itemId, 'old_photo' => $photoBefore !== $item['photo'] ? $photoBefore : null];
        });
    }

    public const MAX_PRICE_PAISE = 9999999; // ₹99,999.99

    /**
     * Validates submitted variant rows and merges in existing variants that were not submitted,
     * so the ₹0 rule is checked against the item's final state.
     *
     * @return array{0: list<array>, 1: array<string, string>}
     */
    private function parseVariants(array $submitted, array $existingVariants): array
    {
        $known = [];
        foreach ($existingVariants as $variant) {
            $known[(int) $variant['id']] = $variant;
        }

        $rows = [];
        $errors = [];
        $seen = [];
        foreach ($submitted as $key => $variant) {
            $key = (string) $key;
            $isNew = str_starts_with($key, 'new');
            if (!is_array($variant) || (!$isNew && !isset($known[(int) $key]))) {
                $errors['_'] = 'menu.error.not_found';
                continue;
            }

            $row = [
                'key' => $key,
                'id' => $isNew ? null : (int) $key,
                'label_mr' => trim((string) ($variant['label_mr'] ?? '')),
                'label_en' => trim((string) ($variant['label_en'] ?? '')),
                'price' => trim((string) ($variant['price'] ?? '')),
                'sort_order' => trim((string) ($variant['sort_order'] ?? '0')),
                'is_active' => empty($variant['is_active']) ? 0 : 1,
                'price_paise' => null,
                'unchanged' => false,
            ];
            if ($isNew && $row['label_mr'] === '' && $row['label_en'] === '' && $row['price'] === '') {
                continue;
            }

            $prefix = "variants.{$key}.";
            if (!is_valid_name($row['label_mr'], 50)) {
                $errors[$prefix . 'label_mr'] = 'menu.error.label';
            }
            if (!is_valid_name($row['label_en'], 50)) {
                $errors[$prefix . 'label_en'] = 'menu.error.label';
            }
            try {
                $row['price_paise'] = parse_rupees($row['price']);
                if ($row['price_paise'] > self::MAX_PRICE_PAISE) {
                    $errors[$prefix . 'price'] = 'menu.error.price';
                }
            } catch (\InvalidArgumentException) {
                $errors[$prefix . 'price'] = 'menu.error.price';
            }
            if (!preg_match('/^\d{1,4}$/', $row['sort_order'])) {
                $errors[$prefix . 'sort_order'] = 'menu.error.sort_order';
            }
            if ($row['id'] !== null) {
                $seen[$row['id']] = true;
            }
            $rows[] = $row;
        }

        foreach ($known as $variantId => $variant) {
            if (!isset($seen[$variantId])) {
                $rows[] = [
                    'key' => (string) $variantId,
                    'id' => $variantId,
                    'is_active' => (int) $variant['is_active'],
                    'price_paise' => $variant['price_paise'],
                    'unchanged' => true,
                ];
            }
        }
        return [$rows, $errors];
    }

    private function saveVariant(int $itemId, string $itemName, array $row, int $staffId): void
    {
        $data = [
            'label_mr' => $row['label_mr'],
            'label_en' => $row['label_en'],
            'price' => decimal_from_paise($row['price_paise']),
            'sort_order' => (int) $row['sort_order'],
            'is_active' => $row['is_active'],
        ];

        if ($row['id'] === null) {
            $this->db->prepare(
                'INSERT INTO item_variants (item_id, label_mr, label_en, price, sort_order, is_active) VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([$itemId, $data['label_mr'], $data['label_en'], $data['price'], $data['sort_order'], $data['is_active']]);
            $this->audit()->log($staffId, 'variant_create', 'item_variant', (int) $this->db->lastInsertId(), [
                'item' => $itemName,
                'after' => $data,
            ]);
            return;
        }

        $stmt = $this->db->prepare('SELECT * FROM item_variants WHERE id = ? AND item_id = ?');
        $stmt->execute([$row['id'], $itemId]);
        $before = $stmt->fetch();
        $this->db->prepare(
            'UPDATE item_variants SET label_mr = ?, label_en = ?, price = ?, sort_order = ?, is_active = ? WHERE id = ?'
        )->execute([$data['label_mr'], $data['label_en'], $data['price'], $data['sort_order'], $data['is_active'], $row['id']]);

        if ((string) $before['price'] !== $data['price']) {
            $this->audit()->log($staffId, 'price_change', 'item_variant', $row['id'], [
                'item' => $itemName,
                'variant' => $data['label_mr'],
                'before' => ['price' => (string) $before['price']],
                'after' => ['price' => $data['price']],
            ]);
        }
        $other = self::diff($before, array_diff_key($data, ['price' => true]));
        if ($other !== []) {
            $this->audit()->log($staffId, 'variant_update', 'item_variant', $row['id'], $other + [
                'item' => $itemName,
                'variant' => $data['label_mr'],
            ]);
        }
    }

    private function nameErrors(array $data): array
    {
        $errors = [];
        foreach (['name_mr', 'name_en'] as $field) {
            if (!is_valid_name($data[$field], 100)) {
                $errors[$field] = 'menu.error.name';
            }
        }
        return $errors;
    }

    /** Changed fields only: ['before' => [...], 'after' => [...]], or [] when nothing changed. */
    private static function diff(array $before, array $after): array
    {
        $changed = [];
        foreach ($after as $key => $value) {
            if ((string) ($before[$key] ?? '') !== (string) ($value ?? '')) {
                $changed['before'][$key] = $before[$key] ?? null;
                $changed['after'][$key] = $value;
            }
        }
        return $changed;
    }

    private function audit(): AuditService
    {
        return new AuditService($this->db);
    }
}
