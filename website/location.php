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
<section class="page-hero">
  <div>
    <h1><?= e(t('site.nav.location')) ?></h1>
    <p class="lead"><?= e(lang_field($shop, 'shop_address')) ?></p>
  </div>
  <a class="btn btn-primary btn-lg" href="<?= e($directions) ?>" rel="noopener"><?= icon('directions') ?><?= e(t('site.location.directions')) ?></a>
</section>

<div class="location-grid">
  <section class="content-card">
    <h2><?= e(t('site.location.address')) ?></h2>
    <ul class="icon-list">
      <li><?= icon('pin', 18) ?><address><?= e(lang_field($shop, 'shop_address')) ?></address></li>
      <li><?= icon('clock', 18) ?><span><?= e(t('shop.hours', ['open' => format_time($shop['open_time'] ?? '10:00'), 'close' => format_time($shop['close_time'] ?? '22:30')])) ?></span></li>
      <?php if ($phone !== ''): ?>
      <li><?= icon('phone', 18) ?><a href="<?= e('tel:' . preg_replace('/[^\d+]/', '', $phone)) ?>"><?= e($phone) ?></a></li>
      <?php endif; ?>
    </ul>
  </section>

  <?php if ($embed !== ''): ?>
  <section class="map" data-map data-map-src="<?= e($embed) ?>" data-map-title="<?= e(t('site.location.map_title')) ?>">
    <span class="map__icon"><?= icon('pin', 32) ?></span>
    <a class="map__load btn btn-primary" href="<?= e($directions) ?>" data-map-load rel="noopener"><?= e(t('site.location.show_map')) ?></a>
    <p class="map__note text-sm muted"><?= e(t('site.location.map_note')) ?></p>
  </section>
  <?php else: ?>
  <?php /* TODO: owner content – Google Maps "Embed a map" code (admin → Settings). */ ?>
  <a class="map map--link" href="<?= e($directions) ?>" rel="noopener">
    <span class="map__icon"><?= icon('pin', 32) ?></span>
    <span class="btn btn-primary"><?= e(t('site.location.directions')) ?></span>
  </a>
  <?php endif; ?>
</div>
<?php
view('site_footer', ['scripts' => $embed !== '' ? ['js/site.js'] : []]);
