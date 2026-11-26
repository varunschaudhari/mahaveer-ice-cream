<?php

declare(strict_types=1);

namespace Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HelpersTest extends TestCase
{
    public static function validDecimals(): array
    {
        return [
            'two places' => ['40.00', 4000],
            'whole' => ['40', 4000],
            'one place' => ['40.5', 4050],
            'paise only' => ['0.05', 5],
            'zero' => ['0', 0],
            'zero decimal' => ['0.00', 0],
            'negative (refund)' => ['-5.00', -500],
            'surrounding spaces' => [' 12.34 ', 1234],
            'large' => ['99999999.99', 9999999999],
        ];
    }

    #[DataProvider('validDecimals')]
    public function testPaiseFromDecimal(string $input, int $expected): void
    {
        self::assertSame($expected, paise_from_decimal($input));
    }

    public static function invalidDecimals(): array
    {
        return [[''], ['abc'], ['1.234'], ['1,000'], ['1e3'], ['.5'], ['5.'], ['₹40'], ['4 0'], ['--5']];
    }

    #[DataProvider('invalidDecimals')]
    public function testPaiseFromDecimalRejectsBadInput(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        paise_from_decimal($input);
    }

    public function testDecimalFromPaise(): void
    {
        self::assertSame('40.00', decimal_from_paise(4000));
        self::assertSame('40.50', decimal_from_paise(4050));
        self::assertSame('0.05', decimal_from_paise(5));
        self::assertSame('0.00', decimal_from_paise(0));
        self::assertSame('-5.00', decimal_from_paise(-500));
        self::assertSame('-0.05', decimal_from_paise(-5));
    }

    public function testRoundTrip(): void
    {
        foreach ([0, 1, 5, 99, 100, 101, 1500, 4050, 999999, -1, -4050] as $paise) {
            self::assertSame($paise, paise_from_decimal(decimal_from_paise($paise)));
        }
    }

    public static function moneyCases(): array
    {
        return [
            [4000, '₹40'],
            [4050, '₹40.50'],
            [4005, '₹40.05'],
            [5, '₹0.05'],
            [0, '₹0'],
            [99999, '₹999.99'],
            [100000, '₹1,000'],
            [12500000, '₹1,25,000'],
            [1234567800, '₹1,23,45,678'],
            [-2500, '-₹25'],
        ];
    }

    #[DataProvider('moneyCases')]
    public function testMoney(int $paise, string $expected): void
    {
        self::assertSame($expected, money($paise));
    }

    public function testEscape(): void
    {
        self::assertSame('&lt;script&gt;alert(1)&lt;/script&gt;', e('<script>alert(1)</script>'));
        self::assertSame('&quot;x&quot; &amp; O&#039;Brien', e('"x" & O\'Brien'));
        self::assertSame('', e(null));
        self::assertSame('42', e(42));
        self::assertSame('बदाम शेक', e('बदाम शेक'));
    }
}
