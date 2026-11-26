<?php

declare(strict_types=1);

namespace Tests;

use App\Lang;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LangTest extends TestCase
{
    protected function tearDown(): void
    {
        Lang::set(Lang::DEFAULT);
    }

    public function testMarathiIsTheDefault(): void
    {
        self::assertSame('mr', Lang::DEFAULT);
        self::assertSame('मेनू', t('menu.title'));
    }

    public function testEnglish(): void
    {
        Lang::set('en');
        self::assertSame('en', lang());
        self::assertSame('Menu', t('menu.title'));
    }

    public function testUnknownKeyReturnsTheKey(): void
    {
        self::assertSame('no.such.key', t('no.such.key'));
    }

    public function testParametersAreReplaced(): void
    {
        Lang::set('en');
        self::assertSame('Shop closed, opens at 10:00 AM', t('shop.closed', ['time' => '10:00 AM']));
    }

    public function testUnsupportedLanguageIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Lang::set('hi');
    }

    public function testBothLanguageFilesHaveTheSameKeys(): void
    {
        $mr = array_keys(Lang::strings('mr'));
        $en = array_keys(Lang::strings('en'));
        sort($mr);
        sort($en);
        self::assertSame($mr, $en, 'lang/mr.php and lang/en.php must define the same keys');
    }

    public function testLangFieldPicksCurrentLanguageAndFallsBack(): void
    {
        $row = ['name_mr' => 'बदाम शेक', 'name_en' => 'Badam Shake'];
        self::assertSame('बदाम शेक', lang_field($row, 'name'));
        Lang::set('en');
        self::assertSame('Badam Shake', lang_field($row, 'name'));
        self::assertSame('बदाम शेक', lang_field(['name_mr' => 'बदाम शेक', 'name_en' => ''], 'name'));
    }

    public static function times(): array
    {
        return [
            ['mr', '10:00', 'सकाळी 10:00'],
            ['mr', '14:00', 'दुपारी 2:00'],
            ['mr', '17:30', 'संध्याकाळी 5:30'],
            ['mr', '22:30', 'रात्री 10:30'],
            ['en', '10:00', '10:00 AM'],
            ['en', '12:15', '12:15 PM'],
            ['en', '22:30', '10:30 PM'],
            ['en', '00:05', '12:05 AM'],
        ];
    }

    #[DataProvider('times')]
    public function testFormatTime(string $lang, string $hhmm, string $expected): void
    {
        Lang::set($lang);
        self::assertSame($expected, format_time($hhmm));
    }

    public function testClosedBannerMatchesSpecWording(): void
    {
        self::assertSame('दुकान बंद आहे, सकाळी 10:00 वाजता उघडेल', t('shop.closed', ['time' => format_time('10:00')]));
        Lang::set('en');
        self::assertSame('Shop closed, opens at 10:00 AM', t('shop.closed', ['time' => format_time('10:00')]));
    }
}
