<?php

declare(strict_types=1);

namespace Tests;

use App\Services\MenuService;

final class MenuServiceTest extends DatabaseTestCase
{
    private function menu(): array
    {
        return (new MenuService($this->db()))->publicMenu();
    }

    private function findItem(array $menu, string $nameEn): ?array
    {
        foreach ($menu as $category) {
            foreach ($category['items'] as $item) {
                if ($item['name_en'] === $nameEn) {
                    return $item;
                }
            }
        }
        return null;
    }

    public function testCategoriesInOrderWithKulfiHidden(): void
    {
        // Kulfi items are inactive with ₹0 placeholder prices, so the category has nothing to show.
        self::assertSame(
            ['Ice Cream', 'Lassi', 'Shakes', 'Shrikhand'],
            array_column($this->menu(), 'name_en')
        );
    }

    public function testItemsAndVariantsInSortOrderWithPricesInPaise(): void
    {
        $iceCream = $this->menu()[0];
        self::assertCount(16, $iceCream['items']);

        $vanilla = $iceCream['items'][0];
        self::assertSame('वॅनिला', $vanilla['name_mr']);
        self::assertTrue($vanilla['is_available']);
        self::assertSame(['Half', 'Full'], array_column($vanilla['variants'], 'label_en'));
        self::assertSame([1500, 3000], array_column($vanilla['variants'], 'price_paise'));

        self::assertSame(
            [10000, 20000],
            array_column($this->findItem($this->menu(), 'Kesari Dryfruit Shrikhand')['variants'], 'price_paise')
        );
        self::assertCount(1, $this->findItem($this->menu(), 'Dudh Cold Drinks')['variants']);
    }

    public function testSoldOutItemStaysVisibleButFlagged(): void
    {
        $this->db()->exec("UPDATE items SET is_available = 0 WHERE name_en = 'Vanilla'");

        $vanilla = $this->findItem($this->menu(), 'Vanilla');
        self::assertNotNull($vanilla);
        self::assertFalse($vanilla['is_available']);
    }

    public function testItemNotEnabledOnlineIsHidden(): void
    {
        $this->db()->exec("UPDATE items SET online_enabled = 0 WHERE name_en = 'Falooda'");
        self::assertNull($this->findItem($this->menu(), 'Falooda'));
    }

    public function testZeroPriceVariantIsNeverShownEvenIfItemIsActive(): void
    {
        $this->db()->exec("UPDATE items SET is_active = 1 WHERE name_en = 'Kulfi'");
        self::assertNull($this->findItem($this->menu(), 'Kulfi'));
    }

    public function testInactiveVariantIsHidden(): void
    {
        $this->db()->exec(
            "UPDATE item_variants v JOIN items i ON i.id = v.item_id
             SET v.is_active = 0 WHERE i.name_en = 'Mango' AND v.label_en = 'Half'"
        );
        self::assertSame(['Full'], array_column($this->findItem($this->menu(), 'Mango')['variants'], 'label_en'));
    }
}
