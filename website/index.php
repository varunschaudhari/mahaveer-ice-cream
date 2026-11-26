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

view('site_header', [
    'page' => 'home',
    'path' => '/',
    'ogImage' => $heroUrl === null ? null : $siteUrl . $heroUrl,
    'jsonLd' => SiteService::structuredData($shop, $siteUrl, $heroUrl === null ? null : $siteUrl . $heroUrl),
]);
?>
<section class="hero">
  <?php if ($hero !== null): ?>
  <img class="hero__img" src="<?= e($heroUrl) ?>" width="<?= (int) $hero['width'] ?>" height="<?= (int) $hero['height'] ?>"
       alt="<?= e(lang_field($hero, 'caption') ?: lang_field($shop, 'shop_name')) ?>" fetchpriority="high">
  <?php else: ?>
  <?php /* TODO: owner content – a shop or ice cream photo (admin → Website content → Gallery). */ ?>
  <div class="hero__placeholder"><img src="/assets/img/cone.svg" width="160" height="200" alt=""></div>
  <?php endif; ?>
  <div class="hero__text stack-sm">
    <p class="hero__years"><?= e(t('shop.years')) ?></p>
    <h1 class="hero__title"><?= e(lang_field($shop, 'shop_name')) ?></h1>
    <p class="hero__tagline"><?= e(t('shop.tagline')) ?></p>
    <div class="hero__actions">
      <a class="btn btn-primary btn-lg" href="<?= e($orderUrl . '/') ?>"><?= e(t('site.order_takeaway')) ?></a>
      <a class="btn btn-secondary btn-lg" href="/bulk.php"><?= e(t('site.bulk_order')) ?></a>
    </div>
    <p class="muted text-sm"><?= e(t('shop.hours', ['open' => format_time($shop['open_time'] ?? '10:00'), 'close' => format_time($shop['close_time'] ?? '22:30')])) ?></p>
  </div>
</section>

<?php if ($bestSellers !== []): ?>
<section class="stack-sm">
  <h2><?= e(t('site.best_sellers')) ?></h2>
  <ul class="product-grid">
    <?php foreach ($bestSellers as $item): ?>
    <li class="product-card<?= $item['is_available'] ? '' : ' is-sold-out' ?>">
      <?php if ($item['photo']): ?>
      <img class="product-card__img" src="/media.php?f=<?= e(rawurlencode($item['photo'])) ?>" alt="" loading="lazy" width="400" height="300">
      <?php else: ?>
      <div class="product-card__img product-card__img--placeholder"><img src="/assets/img/cone.svg" width="48" height="60" alt=""></div>
      <?php endif; ?>
      <div class="product-card__body">
        <h3 class="product-card__name"><?= e(lang_field($item, 'name')) ?></h3>
        <?php if (!$item['is_available']): ?><span class="badge badge-muted"><?= e(t('menu.sold_out')) ?></span><?php endif; ?>
        <p class="product-card__prices">
          <?php foreach ($item['variants'] as $i => $variant): ?>
          <?= $i > 0 ? ' · ' : '' ?><span><?= e(lang_field($variant, 'label')) ?> <strong><?= e(money($variant['price_paise'])) ?></strong></span>
          <?php endforeach; ?>
        </p>
      </div>
    </li>
    <?php endforeach; ?>
  </ul>
  <p><a class="btn btn-secondary" href="/menu.php"><?= e(t('site.full_menu')) ?></a></p>
</section>
<?php endif; ?>

<section class="info-grid">
  <div class="card stack-sm">
    <h2><?= e(t('site.visit_us')) ?></h2>
    <p><?= e(lang_field($shop, 'shop_address')) ?></p>
    <p><a class="btn btn-secondary" href="/location.php"><?= e(t('site.location.directions')) ?></a></p>
  </div>
  <div class="card stack-sm">
    <h2><?= e(t('site.nav.bulk')) ?></h2>
    <p><?= e(t('site.bulk.short', ['days' => Settings::int('bulk_notice_days', 1)])) ?></p>
    <p><a class="btn btn-secondary" href="/bulk.php"><?= e(t('site.more')) ?></a></p>
  </div>
</section>
<?php
view('site_footer');
