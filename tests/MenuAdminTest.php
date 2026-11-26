<?php

declare(strict_types=1);

namespace Tests;

use App\Services\MenuService;
use App\ValidationException;

/** Owner edits to the menu: prices, activation rules (₹0 guard), variants, audit log. */
final class MenuAdminTest extends DatabaseTestCase
{
    private int $ownerId;

    private MenuService $menu;

    protected function setUp(): void
    {
        parent::setUp();
        $this->menu = new MenuService($this->db());
        $this->ownerId = $this->makeOwner();
    }

    private function itemId(string $nameEn): int
    {
        $stmt = $this->db()->prepare('SELECT id FROM items WHERE name_en = ?');
        $stmt->execute([$nameEn]);
        return (int) $stmt->fetchColumn();
    }

    /** The item as the admin form would post it back, with optional changes. */
    private function formFor(int $itemId, array $itemChanges = [], array $variantChanges = []): array
    {
        $item = $this->menu->findItem($itemId);
        $input = array_merge([
            'category_id' => $item['category_id'],
            'name_mr' => $item['name_mr'],
            'name_en' => $item['name_en'],
            'sort_order' => (string) $item['sort_order'],
            'is_active' => $item['is_active'] ? '1' : '',
            'online_enabled' => $item['online_enabled'] ? '1' : '',
        ], $itemChanges);

        $variants = [];
        foreach ($item['variants'] as $variant) {
            $variants[(string) $variant['id']] = array_merge([
                'label_mr' => $variant['label_mr'],
                'label_en' => $variant['label_en'],
                'price' => rupees_input($variant['price_paise']),
                'sort_order' => (string) $variant['sort_order'],
                'is_active' => $variant['is_active'] ? '1' : '',
            ], $variantChanges[$variant['label_en']] ?? []);
        }
        return [$input, $variants];
    }

    private function save(?int $id, array $input, array $variants): array
    {
        return $this->menu->saveItem($id, $input, $variants, $this->ownerId);
    }

    private function errorsOf(callable $fn): array
    {
        try {
            $fn();
        } catch (ValidationException $e) {
            return $e->errors;
        }
        self::fail('Expected a ValidationException');
    }

    private function publicPrice(string $nameEn, string $labelEn): ?int
    {
        foreach ($this->menu->publicMenu() as $category) {
            foreach ($category['items'] as $item) {
                if ($item['name_en'] === $nameEn) {
                    foreach ($item['variants'] as $variant) {
                        if ($variant['label_en'] === $labelEn) {
                            return $variant['price_paise'];
                        }
                    }
                }
            }
        }
        return null;
    }

    public function testButterscotchPriceChangeShowsOnMenuAndInAuditLog(): void
    {
        $id = $this->itemId('Butterscotch');
        self::assertSame(5000, $this->publicPrice('Butterscotch', 'Full'));

        [$input, $variants] = $this->formFor($id, [], ['Full' => ['price' => '55']]);
        $this->save($id, $input, $variants);

        self::assertSame(5500, $this->publicPrice('Butterscotch', 'Full'));
        self::assertSame(2500, $this->publicPrice('Butterscotch', 'Half'), 'other variant unchanged');

        $audit = $this->db()->query("SELECT * FROM audit_log WHERE action = 'price_change'")->fetchAll();
        self::assertCount(1, $audit);
        $details = json_decode($audit[0]['details'], true);
        self::assertSame('बटर स्कॉच', $details['item']);
        self::assertSame(['price' => '50.00'], $details['before']);
        self::assertSame(['price' => '55.00'], $details['after']);
        self::assertSame(0, (int) $this->db()->query("SELECT COUNT(*) FROM audit_log WHERE action IN ('item_update','variant_update')")->fetchColumn(), 'nothing else logged');
    }

    public function testActivatingKulfiAtZeroRupeesIsRefused(): void
    {
        $id = $this->itemId('Kulfi');
        [$input, $variants] = $this->formFor($id, ['is_active' => '1']);

        $errors = $this->errorsOf(fn () => $this->save($id, $input, $variants));
        self::assertSame('menu.error.zero_price_active', $errors['_']);
        self::assertSame('menu.error.zero_price_active', $errors['variants.' . array_key_first($variants) . '.price']);
        self::assertSame(0, (int) $this->db()->query("SELECT is_active FROM items WHERE id = {$id}")->fetchColumn());
    }

    public function testActivatingKulfiIsRefusedEvenIfTheVariantRowsAreNotSent(): void
    {
        $id = $this->itemId('Kulfi');
        [$input] = $this->formFor($id, ['is_active' => '1']);
        self::assertSame('menu.error.zero_price_active', $this->errorsOf(fn () => $this->save($id, $input, []))['_']);
    }

    public function testKulfiCanBeActivatedOnceItHasAPrice(): void
    {
        $id = $this->itemId('Kulfi');
        [$input, $variants] = $this->formFor($id, ['is_active' => '1'], ['Piece' => ['price' => '25']]);
        $this->save($id, $input, $variants);

        self::assertSame(2500, $this->publicPrice('Kulfi', 'Piece'));
    }

    public function testSettingZeroPriceOnAnActiveItemIsRefused(): void
    {
        $id = $this->itemId('Vanilla');
        [$input, $variants] = $this->formFor($id, [], ['Half' => ['price' => '0']]);
        self::assertSame('menu.error.zero_price_active', $this->errorsOf(fn () => $this->save($id, $input, $variants))['_']);
    }

    public function testActiveItemNeedsAnActiveVariant(): void
    {
        $id = $this->itemId('Vanilla');
        [$input, $variants] = $this->formFor($id, [], ['Half' => ['is_active' => ''], 'Full' => ['is_active' => '']]);
        self::assertSame('menu.error.active_needs_variant', $this->errorsOf(fn () => $this->save($id, $input, $variants))['_']);
    }

    public function testInactiveItemMayKeepZeroPricePlaceholders(): void
    {
        $id = $this->itemId('Cone');
        [$input, $variants] = $this->formFor($id, ['name_en' => 'Cone (Havmor)']);
        $this->save($id, $input, $variants);
        self::assertSame('Cone (Havmor)', $this->menu->findItem($id)['name_en']);
    }

    public function testNewItemWithTwoVariants(): void
    {
        $result = $this->save(null, [
            'category_id' => '1', 'name_mr' => 'सीताफळ', 'name_en' => 'Custard Apple', 'sort_order' => '17',
            'is_active' => '1', 'online_enabled' => '1',
        ], [
            'new1' => ['label_mr' => 'हाफ', 'label_en' => 'Half', 'price' => '30', 'sort_order' => '1', 'is_active' => '1'],
            'new2' => ['label_mr' => 'फुल', 'label_en' => 'Full', 'price' => '60.50', 'sort_order' => '2', 'is_active' => '1'],
        ]);

        self::assertSame(6050, $this->publicPrice('Custard Apple', 'Full'));
        self::assertSame(
            ['item_create' => 1, 'variant_create' => 2],
            $this->db()->query('SELECT action, COUNT(*) FROM audit_log GROUP BY action')->fetchAll(\PDO::FETCH_KEY_PAIR)
        );
        self::assertNull($result['old_photo']);
    }

    public function testBlankNewVariantRowIsIgnored(): void
    {
        $id = $this->itemId('Vanilla');
        [$input, $variants] = $this->formFor($id);
        $variants['new1'] = ['label_mr' => '', 'label_en' => '', 'price' => '', 'sort_order' => '3', 'is_active' => '1'];
        $this->save($id, $input, $variants);
        self::assertCount(2, $this->menu->findItem($id)['variants']);
    }

    public function testBadInputIsReportedPerField(): void
    {
        $id = $this->itemId('Vanilla');
        [$input, $variants] = $this->formFor($id, ['name_en' => '', 'category_id' => '999'], ['Half' => ['price' => 'abc']]);
        $errors = $this->errorsOf(fn () => $this->save($id, $input, $variants));

        self::assertSame('menu.error.name', $errors['name_en']);
        self::assertSame('menu.error.category', $errors['category_id']);
        self::assertSame('menu.error.price', $errors['variants.' . array_key_first($variants) . '.price']);
    }

    public function testVariantOfAnotherItemIsRejected(): void
    {
        $vanilla = $this->itemId('Vanilla');
        [, $mangoVariants] = $this->formFor($this->itemId('Mango'));
        [$input] = $this->formFor($vanilla);
        self::assertSame('menu.error.not_found', $this->errorsOf(fn () => $this->save($vanilla, $input, $mangoVariants))['_']);
    }

    public function testReplacingThePhotoReturnsTheOldFileToDelete(): void
    {
        $id = $this->itemId('Vanilla');
        [$input, $variants] = $this->formFor($id);
        $old = str_repeat('a', 32) . '.webp';
        $new = str_repeat('b', 32) . '.webp';

        $this->menu->saveItem($id, $input, $variants, $this->ownerId, $old);
        $result = $this->menu->saveItem($id, $input, $variants, $this->ownerId, $new);
        self::assertSame($old, $result['old_photo']);

        $result = $this->menu->saveItem($id, $input, $variants, $this->ownerId, null, true);
        self::assertSame($new, $result['old_photo']);
        self::assertNull($this->menu->findItem($id)['photo']);
    }

    public function testCategoryCreateAndRename(): void
    {
        $id = $this->menu->saveCategory(null, ['name_mr' => 'कोन', 'name_en' => 'Cones', 'sort_order' => '6', 'is_active' => '1'], $this->ownerId);
        $this->menu->saveCategory($id, ['name_mr' => 'कोन', 'name_en' => 'Cones & Cups', 'sort_order' => '6', 'is_active' => '1'], $this->ownerId);

        self::assertSame('Cones & Cups', $this->menu->findCategory($id)['name_en']);
        $actions = $this->db()->query('SELECT action FROM audit_log ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['category_create', 'category_update'], $actions);
    }

    public function testCategoryNeedsNames(): void
    {
        $errors = $this->errorsOf(fn () => $this->menu->saveCategory(null, ['name_mr' => '', 'name_en' => 'X', 'sort_order' => 'x'], $this->ownerId));
        self::assertSame(['name_mr' => 'menu.error.name', 'sort_order' => 'menu.error.sort_order'], $errors);
    }
}
