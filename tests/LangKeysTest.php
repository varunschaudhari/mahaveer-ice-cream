<?php

declare(strict_types=1);

namespace Tests;

use App\Lang;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Every lang key written in the code (t('menu.title'), service error keys like
 * 'staff.error.pin') must exist in both lang files. Catches typos and missing translations
 * before a page shows a raw key to the owner.
 */
final class LangKeysTest extends TestCase
{
    private const PREFIXES = 'auth|admin|counter|menu|common|staff|settings|audit|role|image|error|shop|lang|time|order|site'
        . '|billing|cart|payment|discount|cancel|today|stock|print'
        . '|online|checkout|status|qr|blocked';

    public function testEveryKeyUsedInCodeExistsInBothLanguages(): void
    {
        $used = [];
        foreach (['app', 'order', 'website', 'database'] as $dir) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(APP_ROOT . '/' . $dir));
            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                preg_match_all("/'((?:" . self::PREFIXES . ")\\.[a-z0-9_]+(?:\\.[a-z0-9_]+)*)'/", file_get_contents($file->getPathname()), $m);
                foreach ($m[1] as $key) {
                    $used[$key][] = substr($file->getPathname(), strlen(APP_ROOT) + 1);
                }
            }
        }
        self::assertGreaterThan(100, count($used), 'scanner found too few keys – is the pattern broken?');

        foreach (Lang::SUPPORTED as $lang) {
            $missing = array_diff_key($used, Lang::strings($lang));
            self::assertSame(
                [],
                array_map(static fn (array $files) => implode(', ', array_unique($files)), $missing),
                "Keys missing from lang/{$lang}.php"
            );
        }
    }
}
