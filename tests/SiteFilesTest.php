<?php

declare(strict_types=1);

namespace Tests;

use App\Lang;
use App\Services\SiteService;
use PHPUnit\Framework\TestCase;

/** website/ pages, sitemap.xml, robots.txt and page titles stay in step (SPEC §13 SEO, BUILD_PLAN M8). */
final class SiteFilesTest extends TestCase
{
    private const DOMAIN = 'https://mahaveericecream.in';

    public function testEveryPublicPageIsInTheNavAndTheSitemap(): void
    {
        $files = array_map('basename', glob(APP_ROOT . '/website/*.php'));
        $pages = array_values(array_diff($files, ['boot.php', 'media.php', 'approot.php']));
        $fromNav = array_map(static fn (string $path): string => $path === '/' ? 'index.php' : ltrim($path, '/'), SiteService::PAGES);
        sort($pages);
        $navFiles = array_values($fromNav);
        sort($navFiles);
        self::assertSame($pages, $navFiles, 'every website page is in SiteService::PAGES (nav + sitemap)');

        $xml = simplexml_load_file(APP_ROOT . '/website/sitemap.xml');
        self::assertNotFalse($xml);
        $locs = [];
        foreach ($xml->url as $url) {
            $locs[] = (string) $url->loc;
        }
        self::assertSame(array_map(static fn (string $path): string => self::DOMAIN . $path, array_values(SiteService::PAGES)), $locs);
    }

    public function testRobotsPointsAtTheSitemap(): void
    {
        $robots = (string) file_get_contents(APP_ROOT . '/website/robots.txt');
        self::assertStringContainsString("User-agent: *\nAllow: /", str_replace("\r\n", "\n", $robots));
        self::assertStringContainsString('Sitemap: ' . self::DOMAIN . '/sitemap.xml', $robots);
    }

    public function testEachPageHasItsOwnTitleAndDescriptionMentioningIceCreamAmalner(): void
    {
        foreach (Lang::SUPPORTED as $lang) {
            $strings = Lang::strings($lang);
            $titles = [];
            $descriptions = [];
            foreach (array_keys(SiteService::PAGES) as $page) {
                $title = $strings['site.title.' . $page];
                $description = $strings['site.desc.' . $page];
                self::assertMatchesRegularExpression('/ice cream/i', $title, "{$lang} {$page}");
                self::assertMatchesRegularExpression('/amalner/i', $title, "{$lang} {$page}");
                self::assertLessThanOrEqual(75, mb_strlen($title), "{$lang} {$page} title");
                self::assertGreaterThanOrEqual(50, mb_strlen($description), "{$lang} {$page} description");
                self::assertLessThanOrEqual(160, mb_strlen($description), "{$lang} {$page} description");
                $titles[] = $title;
                $descriptions[] = $description;
            }
            self::assertSame($titles, array_unique($titles), "{$lang}: titles are unique");
            self::assertSame($descriptions, array_unique($descriptions), "{$lang}: descriptions are unique");
        }
    }
}
