<?php

declare(strict_types=1);

// Location & hours (SPEC §13). The Google Map loads only when tapped (developer decision for M8):
// the page stays fast and Google is not contacted until then. Without JavaScript the button is
// a plain link to Google Maps.

use App\Services\SiteService;
use App\Settings;

require __DIR__ . '/boot.php';

$shop = Settings::all();
$siteUrl = rtrim((string) config('APP_URL_WEBSITE', ''), '/');
$embed = (string) ($shop['google_maps_embed'] ?? '');
$directions = SiteService::directionsUrl($shop);
$phone = (string) ($shop['shop_phone'] ?? '');
if ($embed !== '') {
    send_security_headers(['https://www.google.com']);
}

view('site_header', [
    'page' => 'location',
    'path' => '/location.php',
    'jsonLd' => SiteService::structuredData($shop, $siteUrl, null),
]);
?>
<h1><?= e(t('site.nav.location')) ?></h1>

<section class="card stack-sm">
  <h2><?= e(t('site.location.address')) ?></h2>
  <address><?= e(lang_field($shop, 'shop_address')) ?></address>
  <p><?= e(t('shop.hours', ['open' => format_time($shop['open_time'] ?? '10:00'), 'close' => format_time($shop['close_time'] ?? '22:30')])) ?></p>
  <?php if ($phone !== ''): ?>
  <p><a href="tel:<?= e(preg_replace('/[^\d+]/', '', $phone)) ?>"><?= e($phone) ?></a></p>
  <?php endif; ?>
  <p><a class="btn btn-primary" href="<?= e($directions) ?>" rel="noopener"><?= e(t('site.location.directions')) ?></a></p>
</section>

<?php if ($embed !== ''): ?>
<section class="map" data-map data-map-src="<?= e($embed) ?>" data-map-title="<?= e(t('site.location.map_title')) ?>">
  <a class="map__load btn btn-secondary" href="<?= e($directions) ?>" data-map-load rel="noopener"><?= e(t('site.location.show_map')) ?></a>
  <p class="map__note text-sm muted"><?= e(t('site.location.map_note')) ?></p>
</section>
<?php else: ?>
<?php /* TODO: owner content – Google Maps "Embed a map" code (admin → Settings). */ ?>
<?php endif; ?>
<?php
view('site_footer', ['scripts' => $embed !== '' ? ['js/site.js'] : []]);
