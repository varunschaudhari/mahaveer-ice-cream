<?php

declare(strict_types=1);

// Contact (SPEC §13): call button; WhatsApp button only once a number is set in admin → Settings.

use App\Services\SiteService;
use App\Settings;

require __DIR__ . '/boot.php';

$shop = Settings::all();
$siteUrl = rtrim((string) config('APP_URL_WEBSITE', ''), '/');
$orderUrl = rtrim((string) config('APP_URL_ORDER', ''), '/');
$phone = (string) ($shop['shop_phone'] ?? '');
$whatsapp = SiteService::whatsappUrl($shop);

view('site_header', [
    'page' => 'contact',
    'path' => '/contact.php',
    'jsonLd' => SiteService::structuredData($shop, $siteUrl, null),
]);
?>
<h1><?= e(t('site.nav.contact')) ?></h1>
<p class="lead"><?= e(t('site.contact.intro')) ?></p>

<div class="contact-buttons">
  <?php if ($phone !== ''): ?>
  <a class="btn btn-primary btn-lg" href="tel:<?= e(preg_replace('/[^\d+]/', '', $phone)) ?>"><?= e(t('site.contact.call')) ?> · <?= e($phone) ?></a>
  <?php endif; ?>
  <?php if ($whatsapp !== null): ?>
  <a class="btn btn-success btn-lg" href="<?= e($whatsapp) ?>" rel="noopener"><?= e(t('site.contact.whatsapp')) ?></a>
  <?php endif; ?>
  <a class="btn btn-secondary btn-lg" href="<?= e($orderUrl . '/') ?>"><?= e(t('site.order_takeaway')) ?></a>
</div>

<section class="card stack-sm">
  <h2><?= e(t('site.location.address')) ?></h2>
  <address><?= e(lang_field($shop, 'shop_address')) ?></address>
  <p><?= e(t('shop.hours', ['open' => format_time($shop['open_time'] ?? '10:00'), 'close' => format_time($shop['close_time'] ?? '22:30')])) ?></p>
  <p><a href="/location.php"><?= e(t('site.location.directions')) ?></a></p>
</section>
<?php
view('site_footer');
