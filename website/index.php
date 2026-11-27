<?php

declare(strict_types=1);

// Home (SPEC §13): hero photo, "45+ years in Amalner", order buttons, automatic best sellers.

use App\Services\MenuService;
use App\Services\SiteService;
use App\Settings;

require __DIR__ . '/boot.php';

(new MenuService())->resetStockIfNewDay(); // yesterday's sold-out marks must not show this morning
$site = new SiteService();
$shop = Settings::all();
$siteUrl = rtrim((string) config('APP_URL_WEBSITE', ''), '/');
$orderUrl = rtrim((string) config('APP_URL_ORDER', ''), '/');
$hero = $site->heroPhoto();
$heroUrl = $hero === null ? null : '/media.php?f=' . rawurlencode($hero['file_name']);
$bestSellers = $site->bestSellers(now());
$hours = t('shop.hours', ['open' => format_time($shop['open_time'] ?? '10:00'), 'close' => format_time($shop['close_time'] ?? '22:30')]);

view('site_header', [
    'page' => 'home',
    'path' => '/',
    'ogImage' => $heroUrl === null ? null : $siteUrl . $heroUrl,
    'jsonLd' => SiteService::structuredData($shop, $siteUrl, $heroUrl === null ? null : $siteUrl . $heroUrl),
]);
?>
<section class="home-hero">
  <div class="home-hero__text">
    <p class="pill"><?= icon('star', 16) ?><?= e(t('shop.years')) ?></p>
    <h1 class="home-hero__title"><?= e(lang_field($shop, 'shop_name')) ?></h1>
    <p class="home-hero__tagline"><?= e(t('shop.tagline')) ?></p>
    <div class="home-hero__actions">
      <a class="btn btn-primary btn-lg" href="<?= e($orderUrl . '/') ?>"><?= icon('bag') ?><?= e(t('site.order_takeaway')) ?></a>
      <a class="btn btn-light btn-lg" href="/bulk.php"><?= icon('gift') ?><?= e(t('site.bulk_order')) ?></a>
    </div>
    <p class="home-hero__hours"><?= icon('clock', 18) ?><?= e($hours) ?></p>
  </div>
  <div class="home-hero__media">
    <?php if ($hero !== null): ?>
    <img class="home-hero__img" src="<?= e($heroUrl) ?>" width="<?= (int) $hero['width'] ?>" height="<?= (int) $hero['height'] ?>"
         alt="<?= e(lang_field($hero, 'caption') ?: lang_field($shop, 'shop_name')) ?>" fetchpriority="high">
    <?php else: ?>
    <?php /* TODO: owner content – a shop or ice cream photo (admin → Website content → Gallery). */ ?>
    <div class="home-hero__art"><img src="/assets/img/cone.svg" width="160" height="200" alt=""></div>
    <?php endif; ?>
  </div>
</section>

<ul class="features">
  <li class="feature">
    <span class="feature__icon"><?= icon('star', 22) ?></span>
    <h2 class="feature__title"><?= e(t('shop.years')) ?></h2>
    <p><?= e(t('site.feature.years')) ?></p>
  </li>
  <li class="feature">
    <span class="feature__icon"><?= icon('bag', 22) ?></span>
    <h2 class="feature__title"><?= e(t('site.order_online')) ?></h2>
    <p><?= e(t('site.feature.order')) ?></p>
  </li>
  <li class="feature">
    <span class="feature__icon"><?= icon('gift', 22) ?></span>
    <h2 class="feature__title"><?= e(t('site.nav.bulk')) ?></h2>
    <p><?= e(t('site.bulk.short', ['days' => Settings::int('bulk_notice_days', 1)])) ?></p>
  </li>
  <li class="feature">
    <span class="feature__icon"><?= icon('clock', 22) ?></span>
    <h2 class="feature__title"><?= e(t('site.feature.hours_title')) ?></h2>
    <p><?= e($hours) ?></p>
  </li>
</ul>

<?php if ($bestSellers !== []): ?>
<section class="home-section">
  <div class="section-head">
    <h2><?= e(t('site.best_sellers')) ?></h2>
    <a class="text-link" href="/menu.php"><?= e(t('site.full_menu')) ?></a>
  </div>
  <ul class="product-grid">
    <?php foreach ($bestSellers as $item): ?>
    <li class="product-card<?= $item['is_available'] ? '' : ' is-sold-out' ?>">
      <?php if ($item['photo']): ?>
      <img class="product-card__img" src="/media.php?f=<?= e(rawurlencode($item['photo'])) ?>" alt="" loading="lazy" width="400" height="300">
      <?php else: ?>
      <div class="product-card__img product-card__img--placeholder tone-<?= (int) $item['id'] % 6 ?>"><img src="/assets/img/cone.svg" width="48" height="60" alt=""></div>
      <?php endif; ?>
      <div class="product-card__body">
        <h3 class="product-card__name"><?= e(lang_field($item, 'name')) ?></h3>
        <?php if (!$item['is_available']): ?><span class="badge badge-muted"><?= e(t('menu.sold_out')) ?></span><?php endif; ?>
        <ul class="price-pills">
          <?php foreach ($item['variants'] as $variant): ?>
          <li><span><?= e(lang_field($variant, 'label')) ?></span> <strong><?= e(money($variant['price_paise'])) ?></strong></li>
          <?php endforeach; ?>
        </ul>
      </div>
    </li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<section class="home-section info-grid">
  <div class="info-card">
    <span class="info-card__icon"><?= icon('pin', 22) ?></span>
    <h2><?= e(t('site.visit_us')) ?></h2>
    <p><?= e(lang_field($shop, 'shop_address')) ?></p>
    <a class="text-link" href="/location.php"><?= e(t('site.location.directions')) ?> <?= icon('arrow', 16) ?></a>
  </div>
  <div class="info-card">
    <span class="info-card__icon"><?= icon('gift', 22) ?></span>
    <h2><?= e(t('site.nav.bulk')) ?></h2>
    <p><?= e(t('site.bulk.short', ['days' => Settings::int('bulk_notice_days', 1)])) ?></p>
    <a class="text-link" href="/bulk.php"><?= e(t('site.more')) ?></a>
  </div>
</section>

<section class="cta-band">
  <div>
    <h2><?= e(t('site.cta.title')) ?></h2>
    <p><?= e(t('checkout.pay_at_counter')) ?></p>
  </div>
  <div class="cta-band__actions">
    <a class="btn btn-white btn-lg" href="<?= e($orderUrl . '/') ?>"><?= icon('bag') ?><?= e(t('site.order_takeaway')) ?></a>
    <?php if (($shop['shop_phone'] ?? '') !== ''): ?>
    <a class="btn btn-outline-white btn-lg" href="tel:<?= e(preg_replace('/[^\d+]/', '', $shop['shop_phone'])) ?>"><?= icon('phone') ?><?= e(t('site.contact.call')) ?></a>
    <?php endif; ?>
  </div>
</section>
<?php
view('site_footer');
