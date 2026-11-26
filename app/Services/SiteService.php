<?php

declare(strict_types=1);

namespace App\Services;

use App\Db;
use App\ValidationException;
use DateTimeImmutable;
use PDO;

/**
 * The public website (SPEC §13, BUILD_PLAN M8): owner-edited text (About, announcement banner),
 * the photo gallery, automatic best sellers and the business data for search engines.
 */
final class SiteService
{
    /** Public pages: nav key => path (header nav, sitemap.xml; titles in site.title.<key>). */
    public const PAGES = [
        'home' => '/',
        'menu' => '/menu.php',
        'about' => '/about.php',
        'bulk' => '/bulk.php',
        'gallery' => '/gallery.php',
        'location' => '/location.php',
        'contact' => '/contact.php',
    ];
    public const TEXT_KEYS = ['about' => 5000, 'banner' => 200]; // key => max characters per language
    public const BEST_SELLERS = 3;
    public const BEST_SELLER_DAYS = 30;

    // SPEC §13 names these until there are sales to rank (seed ids: Badam Shake, Mahavir Special,
    // American Dryfruit; menu rows are never deleted, so the ids stay valid).
    private const DEFAULT_BEST_SELLERS = [21, 15, 14];

    // The shop's town for the address in search results (the one-line address is in settings).
    private const LOCALITY = 'Amalner';
    private const REGION = 'Maharashtra';

    private readonly PDO $db;
    private readonly AuditService $audit;
    private readonly ImageService $images;

    public function __construct(?PDO $db = null, ?ImageService $images = null)
    {
        $this->db = $db ?? Db::pdo();
        $this->audit = new AuditService($this->db);
        $this->images = $images ?? new ImageService();
    }

    // ---------------------------------------------------------------
    // Owner text: About, announcement banner
    // ---------------------------------------------------------------

    /** @return array{value_mr: string, value_en: string, is_active: bool} */
    public function text(string $key): array
    {
        $stmt = $this->db->prepare('SELECT value_mr, value_en, is_active FROM site_content WHERE content_key = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        return [
            'value_mr' => (string) ($row['value_mr'] ?? ''),
            'value_en' => (string) ($row['value_en'] ?? ''),
            'is_active' => (bool) ($row['is_active'] ?? false),
        ];
    }

    /** The banner when it is switched on and has text, else null. */
    public function banner(): ?array
    {
        $banner = $this->text('banner');
        return $banner['is_active'] && ($banner['value_mr'] !== '' || $banner['value_en'] !== '') ? $banner : null;
    }

    /** Saves About or the banner (both languages). Line breaks are allowed in About only. */
    public function saveText(string $key, string $mr, string $en, bool $active, int $staffId): void
    {
        if (!isset(self::TEXT_KEYS[$key])) {
            throw ValidationException::one('_', 'error.generic');
        }
        $max = self::TEXT_KEYS[$key];
        $clean = static fn (string $text): string => trim(str_replace("\r\n", "\n", $text));
        [$mr, $en] = [$clean($mr), $clean($en)];
        $control = $key === 'about' ? '/[\x00-\x09\x0B-\x1F\x7F]/u' : '/[\x00-\x1F\x7F]/u';

        $errors = [];
        foreach (['mr' => $mr, 'en' => $en] as $lang => $text) {
            if (mb_strlen($text) > $max) {
                $errors["{$key}_{$lang}"] = ['site.error.too_long', ['max' => $max]];
            } elseif (preg_match($control, $text)) {
                $errors["{$key}_{$lang}"] = 'site.error.invalid';
            }
        }
        if ($active && $mr === '' && $en === '') {
            $errors["{$key}_mr"] = 'site.error.empty';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $before = $this->text($key);
        if ($before === ['value_mr' => $mr, 'value_en' => $en, 'is_active' => $active]) {
            return;
        }
        Db::transaction(function () use ($key, $mr, $en, $active, $before, $staffId): void {
            $this->db->prepare(
                'INSERT INTO site_content (content_key, value_mr, value_en, is_active) VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE value_mr = ?, value_en = ?, is_active = ?'
            )->execute([$key, $mr, $en, (int) $active, $mr, $en, (int) $active]);
            $this->audit->log($staffId, 'site_content_update', 'site_content', $key, [
                'before' => ['is_active' => $before['is_active'], 'characters' => mb_strlen($before['value_mr']) + mb_strlen($before['value_en'])],
                'after' => ['is_active' => $active, 'characters' => mb_strlen($mr) + mb_strlen($en)],
            ]);
        });
    }

    // ---------------------------------------------------------------
    // Gallery
    // ---------------------------------------------------------------

    /** @return list<array> photos in display order, with width / height when the file exists */
    public function photos(bool $activeOnly = true): array
    {
        $rows = $this->db->query(
            'SELECT * FROM site_photos' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY sort_order, id'
        )->fetchAll();
        return array_map(function (array $row): array {
            [$row['width'], $row['height']] = $this->images->dimensions($row['file_name']) ?? [null, null];
            return $row;
        }, $rows);
    }

    /** The home page photo: the first gallery photo, or null until the owner uploads one. */
    public function heroPhoto(): ?array
    {
        foreach ($this->photos() as $photo) {
            if ($photo['width'] !== null) {
                return $photo;
            }
        }
        return null;
    }

    /** Adds a photo already stored by ImageService (storeUpload) at the end of the gallery. */
    public function addPhoto(string $fileName, string $captionMr, string $captionEn, int $staffId): int
    {
        if (!ImageService::isValidName($fileName)) {
            throw ValidationException::one('photo', 'image.error.upload');
        }
        [$captionMr, $captionEn] = self::captions($captionMr, $captionEn);
        $next = (int) $this->db->query('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM site_photos')->fetchColumn();
        return Db::transaction(function () use ($fileName, $captionMr, $captionEn, $next, $staffId): int {
            $this->db->prepare('INSERT INTO site_photos (file_name, caption_mr, caption_en, sort_order) VALUES (?, ?, ?, ?)')
                ->execute([$fileName, $captionMr, $captionEn, $next]);
            $id = (int) $this->db->lastInsertId();
            $this->audit->log($staffId, 'site_photo_add', 'site_photo', $id, ['name' => $captionEn ?? $captionMr]);
            return $id;
        });
    }

    public function updatePhoto(int $id, string $captionMr, string $captionEn, int $sortOrder, bool $active, int $staffId): void
    {
        [$captionMr, $captionEn] = self::captions($captionMr, $captionEn);
        $photo = $this->photo($id);
        Db::transaction(function () use ($id, $captionMr, $captionEn, $sortOrder, $active, $photo, $staffId): void {
            $this->db->prepare('UPDATE site_photos SET caption_mr = ?, caption_en = ?, sort_order = ?, is_active = ? WHERE id = ?')
                ->execute([$captionMr, $captionEn, max(0, min(9999, $sortOrder)), (int) $active, $id]);
            $this->audit->log($staffId, 'site_photo_update', 'site_photo', $id, [
                'before' => ['caption_mr' => $photo['caption_mr'], 'caption_en' => $photo['caption_en'], 'sort_order' => (int) $photo['sort_order'], 'is_active' => (int) $photo['is_active']],
                'after' => ['caption_mr' => $captionMr, 'caption_en' => $captionEn, 'sort_order' => max(0, min(9999, $sortOrder)), 'is_active' => (int) $active],
            ]);
        });
    }

    /** Deletes the photo and its file (gallery photos, unlike menu rows, are not referenced by orders). */
    public function deletePhoto(int $id, int $staffId): void
    {
        $photo = $this->photo($id);
        Db::transaction(function () use ($id, $photo, $staffId): void {
            $this->db->prepare('DELETE FROM site_photos WHERE id = ?')->execute([$id]);
            $this->audit->log($staffId, 'site_photo_delete', 'site_photo', $id, ['name' => $photo['caption_en'] ?? $photo['caption_mr']]);
        });
        $this->images->delete($photo['file_name']);
    }

    private function photo(int $id): array
    {
        $stmt = $this->db->prepare('SELECT * FROM site_photos WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: throw ValidationException::one('_', 'site.error.photo_not_found');
    }

    /** @return array{0: ?string, 1: ?string} trimmed captions, null when empty */
    private static function captions(string $mr, string $en): array
    {
        $out = [];
        foreach (['caption_mr' => $mr, 'caption_en' => $en] as $field => $text) {
            $text = trim($text);
            if (mb_strlen($text) > 150 || preg_match('/[\x00-\x1F\x7F]/u', $text)) {
                throw ValidationException::one($field, ['site.error.too_long', ['max' => 150]]);
            }
            $out[] = $text === '' ? null : $text;
        }
        return $out;
    }

    // ---------------------------------------------------------------
    // Best sellers (developer decision: automatic)
    // ---------------------------------------------------------------

    /**
     * The top items by sales of the last 30 days (amount, as the reports rank them), only items
     * on the online menu right now; the SPEC's three fill any empty places.
     *
     * @return list<array> items as MenuService::publicMenu() returns them
     */
    public function bestSellers(DateTimeImmutable $now, int $limit = self::BEST_SELLERS): array
    {
        $onMenu = [];
        foreach ((new MenuService($this->db))->publicMenu() as $category) {
            foreach ($category['items'] as $item) {
                $onMenu[(int) $item['id']] = $item;
            }
        }

        $today = $now->setTime(0, 0);
        $ranked = array_column((new ReportService($this->db))->topItems($today->modify('-' . (self::BEST_SELLER_DAYS - 1) . ' days'), $today), 'item_id');
        $picked = [];
        foreach (array_merge($ranked, self::DEFAULT_BEST_SELLERS) as $id) {
            if (isset($onMenu[$id]) && !isset($picked[$id])) {
                $picked[$id] = $onMenu[$id];
            }
            if (count($picked) === $limit) {
                break;
            }
        }
        return array_values($picked);
    }

    // ---------------------------------------------------------------
    // Search engines
    // ---------------------------------------------------------------

    /**
     * schema.org IceCreamShop (a LocalBusiness) for the JSON-LD block: name, address, phone,
     * opening hours, map, and geo when the map embed code contains the coordinates.
     */
    public static function structuredData(array $settings, string $siteUrl, ?string $imageUrl): array
    {
        $address = (string) ($settings['shop_address_en'] ?? '');
        $phone = preg_replace('/[^\d+]/', '', (string) ($settings['shop_phone'] ?? ''));
        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'IceCreamShop',
            'name' => (string) ($settings['shop_name_en'] ?? ''),
            'alternateName' => (string) ($settings['shop_name_mr'] ?? ''),
            'url' => rtrim($siteUrl, '/') . '/',
            'telephone' => $phone,
            'address' => array_filter([
                '@type' => 'PostalAddress',
                'streetAddress' => $address,
                'addressLocality' => self::LOCALITY,
                'addressRegion' => self::REGION,
                'postalCode' => preg_match('/\b(\d{6})\b/', $address, $m) ? $m[1] : null,
                'addressCountry' => 'IN',
            ]),
            'openingHoursSpecification' => [[
                '@type' => 'OpeningHoursSpecification',
                'dayOfWeek' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'],
                'opens' => (string) ($settings['open_time'] ?? '10:00'),
                'closes' => (string) ($settings['close_time'] ?? '22:30'),
            ]],
            'servesCuisine' => 'Ice cream',
            'priceRange' => '₹',
        ];
        if ($imageUrl !== null) {
            $data['image'] = $imageUrl;
        }
        if (($settings['google_maps_url'] ?? '') !== '') {
            $data['hasMap'] = $settings['google_maps_url'];
        }
        // Google's embed code carries the map centre as !2d<longitude>!3d<latitude>.
        if (preg_match('/!2d(-?\d{1,3}\.\d+)!3d(-?\d{1,2}\.\d+)/', (string) ($settings['google_maps_embed'] ?? ''), $m)) {
            $data['geo'] = ['@type' => 'GeoCoordinates', 'latitude' => (float) $m[2], 'longitude' => (float) $m[1]];
        }
        return $data;
    }

    /** "Directions": the owner's Google Maps link, else a Maps search for the address. */
    public static function directionsUrl(array $settings): string
    {
        $link = (string) ($settings['google_maps_url'] ?? '');
        if ($link !== '') {
            return $link;
        }
        $query = trim(($settings['shop_name_en'] ?? '') . ', ' . ($settings['shop_address_en'] ?? ''), ', ');
        return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($query);
    }

    /** WhatsApp chat link, or null while no number is set (the button stays hidden). */
    public static function whatsappUrl(array $settings): ?string
    {
        $number = preg_replace('/\D/', '', (string) ($settings['whatsapp_number'] ?? ''));
        return preg_match('/^[6-9]\d{9}$/', $number) ? 'https://wa.me/91' . $number : null;
    }
}
