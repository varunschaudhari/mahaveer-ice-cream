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
<section class="page-hero">
  <div>
    <h1><?= e(t('site.nav.contact')) ?></h1>
    <p class="lead"><?= e(t('site.contact.intro')) ?></p>
  </div>
</section>

<ul class="contact-cards">
  <?php if ($phone !== ''): ?>
  <li><a class="contact-card" href="<?= e('tel:' . preg_replace('/[^\d+]/', '', $phone)) ?>">
    <span class="contact-card__icon"><?= icon('phone', 24) ?></span>
    <span class="contact-card__text"><strong><?= e(t('site.contact.call')) ?></strong><span><?= e($phone) ?></span></span>
    <?= icon('arrow', 18) ?>
  </a></li>
  <?php endif; ?>
  <?php if ($whatsapp !== null): ?>
  <li><a class="contact-card contact-card--whatsapp" href="<?= e($whatsapp) ?>" rel="noopener">
    <span class="contact-card__icon"><?= icon('chat', 24) ?></span>
    <span class="contact-card__text"><strong><?= e(t('site.contact.whatsapp')) ?></strong><span><?= e(t('site.contact.whatsapp_hint')) ?></span></span>
    <?= icon('arrow', 18) ?>
  </a></li>
  <?php endif; ?>
  <li><a class="contact-card" href="<?= e($orderUrl . '/') ?>">
    <span class="contact-card__icon"><?= icon('bag', 24) ?></span>
    <span class="contact-card__text"><strong><?= e(t('site.order_takeaway')) ?></strong><span><?= e(t('site.contact.order_hint')) ?></span></span>
    <?= icon('arrow', 18) ?>
  </a></li>
</ul>

<section class="content-card">
  <h2><?= e(t('site.location.address')) ?></h2>
  <ul class="icon-list">
    <li><?= icon('pin', 18) ?><address><?= e(lang_field($shop, 'shop_address')) ?></address></li>
    <li><?= icon('clock', 18) ?><span><?= e(t('shop.hours', ['open' => format_time($shop['open_time'] ?? '10:00'), 'close' => format_time($shop['close_time'] ?? '22:30')])) ?></span></li>
    <li><?= icon('directions', 18) ?><a href="/location.php"><?= e(t('site.location.directions')) ?></a></li>
  </ul>
</section>
<?php
view('site_footer');
