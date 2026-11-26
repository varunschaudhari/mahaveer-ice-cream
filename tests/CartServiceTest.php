<?php

declare(strict_types=1);

namespace Tests;

use App\Services\CartService;
use App\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;

final class CartServiceTest extends DatabaseTestCase
{
    private CartService $cart;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cart = new CartService($this->db());
    }

    private function variant(string $itemEn, string $labelEn): int
    {
        $stmt = $this->db()->prepare(
            'SELECT v.id FROM item_variants v JOIN items i ON i.id = v.item_id WHERE i.name_en = ? AND v.label_en = ?'
        );
        $stmt->execute([$itemEn, $labelEn]);
        return (int) $stmt->fetchColumn();
    }

    private function errorOf(array $lines, string $channel = 'counter'): string|array
    {
        try {
            $this->cart->validate($lines, $channel);
        } catch (ValidationException $e) {
            return $e->errors['_'];
        }
        self::fail('Expected a ValidationException');
    }

    public function testPricesComeFromTheDatabaseAndNamesAreSnapshotted(): void
    {
        $lines = $this->cart->validate([
            ['variant_id' => $this->variant('Badam Shake', 'Full'), 'qty' => 2, 'price' => 1], // browser price ignored
            ['variant_id' => $this->variant('Vanilla', 'Half'), 'qty' => '1'],
        ]);

        self::assertSame(8000, $lines[0]['unit_paise']);
        self::assertSame('2', $lines[0]['qty']);
        self::assertSame('बदाम शेक (फुल) / Badam Shake (Full)', $lines[0]['item_name']);
        self::assertSame(1500, $lines[1]['unit_paise']);
    }

    public function testTheSameVariantTwiceIsMerged(): void
    {
        $vanilla = $this->variant('Vanilla', 'Half');
        $lines = $this->cart->validate([['variant_id' => $vanilla, 'qty' => 2], ['variant_id' => $vanilla, 'qty' => 3]]);
        self::assertCount(1, $lines);
        self::assertSame('5', $lines[0]['qty']);
    }

    public static function badQuantities(): array
    {
        return [['0'], [0], [-1], [51], ['1.5'], [1.5], ['two'], [null]];
    }

    #[DataProvider('badQuantities')]
    public function testQuantityMustBeAWholeNumberFromOneToFifty(mixed $qty): void
    {
        self::assertSame(['cart.error.qty', ['max' => 50]], $this->errorOf([['variant_id' => 1, 'qty' => $qty]]));
    }

    public function testMergedQuantityAboveFiftyIsRefused(): void
    {
        self::assertSame(['cart.error.qty', ['max' => 50]], $this->errorOf([['variant_id' => 1, 'qty' => 30], ['variant_id' => 1, 'qty' => 21]]));
    }

    public function testEmptyCartAndUnknownVariant(): void
    {
        self::assertSame('cart.error.empty', $this->errorOf([]));
        self::assertSame('cart.error.unknown', $this->errorOf([['variant_id' => 99999, 'qty' => 1]]));
    }

    public function testInactiveItemIsRefused(): void
    {
        self::assertSame('cart.error.unavailable', $this->errorOf([['variant_id' => $this->variant('Kulfi', 'Piece'), 'qty' => 1]])[0]);
    }

    public function testZeroPriceIsRefusedEvenIfTheItemIsActive(): void
    {
        $this->db()->exec("UPDATE items SET is_active = 1 WHERE name_en = 'Kulfi'");
        self::assertSame('cart.error.unavailable', $this->errorOf([['variant_id' => $this->variant('Kulfi', 'Piece'), 'qty' => 1]])[0]);
    }

    public function testInactiveVariantOrCategoryIsRefused(): void
    {
        $half = $this->variant('Mango', 'Half');
        $this->db()->exec("UPDATE item_variants SET is_active = 0 WHERE id = {$half}");
        self::assertSame('cart.error.unavailable', $this->errorOf([['variant_id' => $half, 'qty' => 1]])[0]);

        $this->db()->exec('UPDATE categories SET is_active = 0 WHERE id = 3');
        self::assertSame('cart.error.unavailable', $this->errorOf([['variant_id' => $this->variant('Badam Shake', 'Full'), 'qty' => 1]])[0]);
    }

    public function testSoldOutItemIsRefusedWithItsName(): void
    {
        $this->db()->exec("UPDATE items SET is_available = 0 WHERE name_en = 'Vanilla'");
        self::assertSame(['cart.error.sold_out', ['item' => 'वॅनिला']], $this->errorOf([['variant_id' => $this->variant('Vanilla', 'Half'), 'qty' => 1]]));
    }

    public function testCounterOnlyItemsAreSoldAtTheCounterButNotOnline(): void
    {
        $this->db()->exec("UPDATE items SET online_enabled = 0 WHERE name_en = 'Falooda'");
        $line = [['variant_id' => $this->variant('Falooda', 'Full'), 'qty' => 1]];

        self::assertCount(1, $this->cart->validate($line, 'counter'));
        self::assertSame('cart.error.unavailable', $this->errorOf($line, 'online')[0]);
    }
}
