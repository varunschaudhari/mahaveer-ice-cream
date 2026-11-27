<?php

declare(strict_types=1);

namespace Tests;

use App\Services\ImageService;
use App\Services\OrderService;
use App\Services\SettingsService;
use App\Services\SiteService;
use App\Settings;
use App\ValidationException;
use DateTimeImmutable;

/** Public website content, gallery, best sellers and search-engine data (SPEC §13, BUILD_PLAN M8). */
final class SiteServiceTest extends DatabaseTestCase
{
    private SiteService $site;
    private ImageService $images;
    private string $dir;
    private int $ownerId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/mahavir-site-test-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->images = new ImageService($this->dir);
        $this->site = new SiteService($this->db(), $this->images);
        $this->ownerId = $this->makeOwner();
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
        parent::tearDown();
    }

    private function errorOf(callable $fn): string|array
    {
        try {
            $fn();
        } catch (ValidationException $e) {
            return $e->first();
        }
        self::fail('Expected a ValidationException');
    }

    private function auditCount(string $action): int
    {
        $stmt = $this->db()->prepare('SELECT COUNT(*) FROM audit_log WHERE action = ?');
        $stmt->execute([$action]);
        return (int) $stmt->fetchColumn();
    }

    /** A stored test photo (width × height), as ImageService saves uploads. */
    private function photoFile(int $width = 800, int $height = 600): string
    {
        $png = $this->dir . '/source.png';
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 194, 24, 91));
        imagepng($image, $png);
        $name = $this->images->storeFile($png);
        unlink($png);
        return $name;
    }

    private function itemId(string $nameEn): int
    {
        $stmt = $this->db()->prepare('SELECT id FROM items WHERE name_en = ?');
        $stmt->execute([$nameEn]);
        return (int) $stmt->fetchColumn();
    }

    // ---------------------------------------------------------------
    // About + banner
    // ---------------------------------------------------------------

    public function testSeededPlaceholdersAndSavingText(): void
    {
        self::assertStringStartsWith('TODO:', $this->site->text('about')['value_en'], 'clearly marked placeholder until the owner writes it');
        self::assertNull($this->site->banner(), 'banner off in the seed');

        $this->site->saveText('about', "पहिला परिच्छेद.\r\n\r\nदुसरा.", "First paragraph.\n\nSecond.", true, $this->ownerId);
        self::assertSame("पहिला परिच्छेद.\n\nदुसरा.", $this->site->text('about')['value_mr'], 'Windows line ends become \\n');
        self::assertSame(1, $this->auditCount('site_content_update'));

        $this->site->saveText('about', "पहिला परिच्छेद.\n\nदुसरा.", "First paragraph.\n\nSecond.", true, $this->ownerId);
        self::assertSame(1, $this->auditCount('site_content_update'), 'saving the same text again is not logged');
    }

    public function testBannerShowsOnlyWhenOnWithText(): void
    {
        self::assertSame('site.error.empty', $this->errorOf(fn () => $this->site->saveText('banner', '', '', true, $this->ownerId)));

        $this->site->saveText('banner', 'दिवाळीनिमित्त सोमवारी बंद', 'Closed on Monday for Diwali', false, $this->ownerId);
        self::assertNull($this->site->banner());

        $this->site->saveText('banner', 'दिवाळीनिमित्त सोमवारी बंद', 'Closed on Monday for Diwali', true, $this->ownerId);
        self::assertSame('Closed on Monday for Diwali', $this->site->banner()['value_en']);
    }

    public function testTextLimits(): void
    {
        self::assertSame(['site.error.too_long', ['max' => 200]], $this->errorOf(fn () => $this->site->saveText('banner', str_repeat('अ', 201), '', true, $this->ownerId)));
        self::assertSame('site.error.invalid', $this->errorOf(fn () => $this->site->saveText('banner', "दोन\nओळी", '', true, $this->ownerId)), 'one line only');
        self::assertSame('site.error.invalid', $this->errorOf(fn () => $this->site->saveText('about', "x\x07", '', true, $this->ownerId)));
        self::assertSame('error.generic', $this->errorOf(fn () => $this->site->saveText('hacked', 'x', 'x', true, $this->ownerId)));
    }

    // ---------------------------------------------------------------
    // Gallery
    // ---------------------------------------------------------------

    public function testGalleryPhotosOrderHeroAndDelete(): void
    {
        self::assertNull($this->site->heroPhoto());

        $first = $this->site->addPhoto($this->photoFile(800, 600), 'दुकान', 'The shop', $this->ownerId);
        $second = $this->site->addPhoto($this->photoFile(600, 900), '', '', $this->ownerId);

        $photos = $this->site->photos();
        self::assertSame([$first, $second], array_map('intval', array_column($photos, 'id')));
        self::assertSame([800, 600], [$photos[0]['width'], $photos[0]['height']], 'size for <img width height>');
        self::assertNull($photos[1]['caption_en'], 'empty caption saved as NULL');
        self::assertSame($first, (int) $this->site->heroPhoto()['id']);

        // Hide the first one: the second becomes the home page photo; the admin still lists both.
        $this->site->updatePhoto($first, 'दुकान', 'The shop', 10, false, $this->ownerId);
        self::assertSame($second, (int) $this->site->heroPhoto()['id']);
        self::assertCount(2, $this->site->photos(false));
        self::assertCount(1, $this->site->photos());

        $file = $this->site->photos()[0]['file_name'];
        $this->site->deletePhoto($second, $this->ownerId);
        self::assertFileDoesNotExist($this->dir . '/' . $file);
        self::assertNull($this->site->heroPhoto());
        self::assertSame([2, 1, 1], [$this->auditCount('site_photo_add'), $this->auditCount('site_photo_update'), $this->auditCount('site_photo_delete')]);
    }

    public function testPhotoChecks(): void
    {
        self::assertSame('image.error.upload', $this->errorOf(fn () => $this->site->addPhoto('../../app/config.php', '', '', $this->ownerId)));
        self::assertSame(['site.error.too_long', ['max' => 150]], $this->errorOf(fn () => $this->site->addPhoto($this->photoFile(), str_repeat('x', 151), '', $this->ownerId)));
        self::assertSame('site.error.photo_not_found', $this->errorOf(fn () => $this->site->deletePhoto(999999, $this->ownerId)));
    }

    // ---------------------------------------------------------------
    // Best sellers (developer decision: automatic, last 30 days)
    // ---------------------------------------------------------------

    public function testCategorySummaryCountsItemsAndLowestPrice(): void
    {
        $summary = SiteService::categorySummary([
            ['id' => 1, 'name_mr' => 'आईस्क्रीम', 'name_en' => 'Ice Cream', 'items' => [
                ['variants' => [['price_paise' => 2000], ['price_paise' => 4000]]],
                ['variants' => [['price_paise' => 1500], ['price_paise' => 3000]]],
            ]],
            ['id' => 2, 'name_mr' => 'रिकामे', 'name_en' => 'Empty', 'items' => []],
        ]);
        self::assertSame([['id' => 1, 'name_mr' => 'आईस्क्रीम', 'name_en' => 'Ice Cream', 'count' => 2, 'from_paise' => 1500]], $summary);

        $seeded = SiteService::categorySummary((new \App\Services\MenuService())->publicMenu());
        self::assertSame(['Ice Cream', 'Lassi', 'Shakes', 'Shrikhand'], array_column($seeded, 'name_en'));
        self::assertSame(1500, $seeded[0]['from_paise'], 'Vanilla half');
    }

    public function testBestSellersStartWithTheSpecsThree(): void
    {
        $names = array_column($this->site->bestSellers(new DateTimeImmutable('2026-11-26 12:00')), 'name_en');
        self::assertSame(['Badam Shake', 'Mahavir Special', 'American Dryfruit'], $names);
    }

    public function testBestSellersFollowLast30DaysOfSalesAndTheOnlineMenu(): void
    {
        $orders = new OrderService($this->db());
        $variant = function (string $item): int {
            $stmt = $this->db()->prepare('SELECT v.id FROM item_variants v JOIN items i ON i.id = v.item_id WHERE i.name_en = ? AND v.price > 0 ORDER BY v.price DESC LIMIT 1');
            $stmt->execute([$item]);
            return (int) $stmt->fetchColumn();
        };
        $sell = function (string $item, int $qty, string $at) use ($orders, $variant): void {
            $orders->createCounterOrder([
                'order_key' => bin2hex(random_bytes(16)), 'lines' => [['variant_id' => $variant($item), 'qty' => $qty]],
                'service_type' => 'dine_in', 'payment_method' => 'cash',
            ], $this->ownerId, new DateTimeImmutable($at));
        };
        $sell('Vanilla', 20, '2026-11-20 12:00');
        $sell('Mango', 5, '2026-11-01 12:00');
        $sell('Kesari Dryfruit Shrikhand', 50, '2026-10-01 12:00'); // older than 30 days: not counted

        $now = new DateTimeImmutable('2026-11-26 12:00');
        self::assertSame(['Vanilla', 'Mango', 'Badam Shake'], array_column($this->site->bestSellers($now), 'name_en'));

        $this->db()->exec('UPDATE items SET online_enabled = 0 WHERE id = ' . $this->itemId('Vanilla'));
        self::assertSame(['Mango', 'Badam Shake', 'Mahavir Special'], array_column($this->site->bestSellers($now), 'name_en'), 'only items on the online menu');
    }

    // ---------------------------------------------------------------
    // Search engines, links, map code
    // ---------------------------------------------------------------

    public function testStructuredDataForGoogle(): void
    {
        $settings = Settings::all() + ['google_maps_embed' => 'https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3720!2d75.0654321!3d21.0412345!2m3'];
        $settings['google_maps_url'] = 'https://maps.app.goo.gl/abc';
        $data = SiteService::structuredData($settings, 'https://mahaveericecream.in', 'https://mahaveericecream.in/media.php?f=x.webp');

        self::assertSame('IceCreamShop', $data['@type']);
        self::assertSame('Mahavir Ice Cream', $data['name']);
        self::assertSame('महावीर आईस्क्रीम', $data['alternateName']);
        self::assertSame('https://mahaveericecream.in/', $data['url']);
        self::assertSame('+919922220000', $data['telephone']);
        self::assertSame('425401', $data['address']['postalCode']);
        self::assertSame('Amalner', $data['address']['addressLocality']);
        self::assertSame(['10:00', '22:30'], [$data['openingHoursSpecification'][0]['opens'], $data['openingHoursSpecification'][0]['closes']]);
        self::assertCount(7, $data['openingHoursSpecification'][0]['dayOfWeek']);
        self::assertSame(['@type' => 'GeoCoordinates', 'latitude' => 21.0412345, 'longitude' => 75.0654321], $data['geo']);
        self::assertSame('https://maps.app.goo.gl/abc', $data['hasMap']);
        self::assertJson(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        $plain = SiteService::structuredData(Settings::all(), 'https://mahaveericecream.in', null);
        self::assertArrayNotHasKey('geo', $plain);
        self::assertArrayNotHasKey('image', $plain);
    }

    public function testDirectionsAndWhatsappLinks(): void
    {
        $settings = Settings::all();
        self::assertStringStartsWith('https://www.google.com/maps/search/?api=1&query=Mahavir%20Ice%20Cream%2C%20Dhule%20Road', SiteService::directionsUrl($settings));
        self::assertSame('https://maps.app.goo.gl/abc', SiteService::directionsUrl(['google_maps_url' => 'https://maps.app.goo.gl/abc'] + $settings));

        self::assertNull(SiteService::whatsappUrl($settings), 'hidden while no number is set');
        self::assertSame('https://wa.me/919822000000', SiteService::whatsappUrl(['whatsapp_number' => '9822000000']));
    }

    public function testMapEmbedCodeFromGoogle(): void
    {
        $src = 'https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3720.5!2d75.0654!3d21.0412!5e0!3m2!1sen!2sin!4v1700000000000';
        $iframe = '<iframe src="' . $src . '" width="600" height="450" style="border:0;" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>';

        self::assertSame($src, SettingsService::mapEmbedUrl($iframe), 'the whole code pasted');
        self::assertSame($src, SettingsService::mapEmbedUrl('  ' . $src . ' '), 'or just the address');
        foreach (['https://evil.example/maps/embed?pb=1', 'https://www.google.com/maps/embed?pb="><script>', 'javascript:alert(1)', 'https://maps.app.goo.gl/abc', ''] as $bad) {
            self::assertNull(SettingsService::mapEmbedUrl($bad), $bad);
        }

        $settings = new SettingsService($this->db());
        $input = array_intersect_key(Settings::all(), SettingsService::FIELDS);
        $settings->update(['google_maps_embed' => $iframe] + $input, $this->ownerId);
        self::assertSame($src, Settings::get('google_maps_embed'));
        try {
            $settings->update(['google_maps_embed' => '<iframe src="https://evil.example/">'] + $input, $this->ownerId);
            self::fail('Expected a ValidationException');
        } catch (ValidationException $e) {
            self::assertSame('settings.error.map_embed', $e->errors['google_maps_embed']);
        }
    }
}
