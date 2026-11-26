<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * The two web roots cannot share files, so website/assets keeps copies of the shared base CSS
 * and fonts from order/assets. Edit the order/ copy, then copy it over.
 */
final class AssetsSyncTest extends TestCase
{
    public function testWebsiteCopiesMatchOrderAssets(): void
    {
        $shared = ['css/base.css', 'fonts/OFL.txt', 'img/icon-192.png', 'img/icon-512.png'];
        foreach (glob(APP_ROOT . '/order/assets/fonts/*.woff2') as $font) {
            $shared[] = 'fonts/' . basename($font);
        }
        self::assertGreaterThanOrEqual(6, count($shared));

        foreach ($shared as $path) {
            $website = APP_ROOT . '/website/assets/' . $path;
            self::assertFileExists($website);
            self::assertFileEquals(APP_ROOT . '/order/assets/' . $path, $website, "website/assets/{$path} is out of date");
        }
    }
}
