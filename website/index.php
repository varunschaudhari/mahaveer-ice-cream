<?php

declare(strict_types=1);

// Home (SPEC §13): hero photo, "45+ years in Amalner", order buttons, the menu at a glance,
// automatic best sellers, the shop's story, party orders and how to find us.

use App\Services\MenuService;
use App\Services\SiteService;
use App\Settings;

require __DIR__ . '/boot.php';

$menuService = new MenuService();
$menuService->resetStockIfNewDay(); // yesterday's sold-out marks must not show this morning
$categories = SiteService::categorySummary($menuService->publicMenu());
$itemCount = array_sum(array_column($categories, 'count'));
$site = new SiteService();
$shop = Settings::all();
$siteUrl = rtrim((string) config('APP_URL_WEBSITE', ''), '/');
$orderUrl = rtrim((string) config('APP_URL_ORDER', ''), '/');
$hero = $site->heroPhoto();
$heroUrl = $hero === null ? null : '/media.php?f=' . rawurlencode($hero['file_name']);
$storyPhoto = array_values(array_filter($site->photos(), static fn (array $p): bool => $p['width'] !== null && ($hero === null || $p['id'] !== $hero['id'])))[0] ?? null;
$bestSellers = $site->bestSellers(now());
$hours = t('shop.hours', ['open' => format_time($shop['open_time'] ?? '10:00'), 'close' => format_time($shop['close_time'] ?? '22:30')]);
$phone = (string) ($shop['shop_phone'] ?? '');
[$titleBefore, $titleAfter] = array_pad(explode('{accent}', t('site.hero.title'), 2), 2, '');

/** Lowest price of an item (paise), for "from ₹X". */
$fromPrice = static fn (array $item): int => min(array_map(static fn (array $v): int => (int) $v['price_paise'], $item['variants']));

view('site_header', [
    'page' => 'home',
    'path' => '/',
    'ogImage' => $heroUrl === null ? null : $siteUrl . $heroUrl,
    'jsonLd' => SiteService::structuredData($shop, $siteUrl, $heroUrl === null ? null : $siteUrl . $heroUrl),
]);
?>
<section class="home-hero">
  <div class="home-hero__text">
    <p class="eyebrow"><?= e(t('shop.years')) ?></p>
    <h1 class="home-hero__title"><?= e($titleBefore) ?><em><?= e(t('site.hero.accent')) ?></em><?= e($titleAfter) ?></h1>
    <p class="home-hero__lead"><?= e(t('order.intro')) ?></p>
    <div class="home-hero__actions">
      <a class="btn btn-ink btn-lg" href="<?= e(cross_link($orderUrl, '/')) ?>"><?= e(t('site.order_takeaway')) ?> <?= icon('arrow', 18) ?></a>
      <a class="text-link" href="/menu.php"><?= e(t('site.full_menu')) ?></a>
    </div>
    <dl class="facts">
      <div><dt><?= e(t('site.facts.years_label')) ?></dt><dd><?= e(t('site.facts.years')) ?></dd></div>
      <div><dt><?= e(t('site.facts.items_label')) ?></dt><dd><?= (int) $itemCount ?></dd></div>
      <div><dt><?= e(format_time($shop['open_time'] ?? '10:00') . ' – ' . format_time($shop['close_time'] ?? '22:30')) ?></dt><dd><?= e(t('site.facts.daily')) ?></dd></div>
    </dl>
  </div>
  <div class="home-hero__media">
    <?php if ($hero !== null): ?>
    <img class="home-hero__img" src="<?= e($heroUrl) ?>" width="<?= (int) $hero['width'] ?>" height="<?= (int) $hero['height'] ?>"
         alt="<?= e(lang_field($hero, 'caption') ?: lang_field($shop, 'shop_name')) ?>" fetchpriority="high">
    <?php else: ?>
    <?php /* TODO: owner content – a shop or ice cream photo (admin → Website content → Gallery). */ ?>
    <div class="home-hero__art"><img src="/assets/img/logo.png" width="468" height="360" alt=""></div>
    <?php endif; ?>
    <?php if ($bestSellers !== []): $top = $bestSellers[0]; ?>
    <a class="float-card" href="/menu.php">
      <span class="float-card__dot tone-<?= (int) $top['id'] % 6 ?>" aria-hidden="true"></span>
      <span><small><?= e(t('site.best_sellers')) ?></small><strong><?= e(lang_field($top, 'name')) ?> · <?= e(t('site.from', ['price' => money($fromPrice($top))])) ?></strong></span>
    </a>
    <?php endif; ?>
  </div>
</section>

<?php if ($categories !== []): ?>
<section class="home-section">
  <div class="section-head">
    <div><p class="eyebrow"><?= e(t('menu.title')) ?></p><h2><?= e(t('site.menu_glance')) ?></h2></div>
    <a class="text-link" href="/menu.php"><?= e(t('site.full_menu')) ?></a>
  </div>
  <ul class="pans">
    <?php foreach ($categories as $i => $category): ?>
    <li>
      <a href="/menu.php#cat-<?= (int) $category['id'] ?>">
        <span class="pan pan-<?= $i % 6 ?>" aria-hidden="true"></span>
        <span class="pans__name"><?= e(lang_field($category, 'name')) ?></span>
        <span class="pans__meta"><?= e(t('order.items_count', ['n' => $category['count']])) ?> · <?= e(t('site.from', ['price' => money($category['from_paise'])])) ?></span>
      </a>
    </li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<?php if ($bestSellers !== []): ?>
<section class="home-section">
  <div class="section-head">
    <div><p class="eyebrow"><?= e(t('site.best_sellers')) ?></p><h2><?= e(t('site.best_sellers_title')) ?></h2></div>
    <a class="text-link" href="/menu.php"><?= e(t('site.full_menu')) ?></a>
  </div>
  <ul class="product-grid">
    <?php foreach ($bestSellers as $item): ?>
    <li class="product-card<?= $item['is_available'] ? '' : ' is-sold-out' ?>">
      <?php if ($item['photo']): ?>
      <img class="product-card__img" src="/media.php?f=<?= e(rawurlencode($item['photo'])) ?>" alt="" loading="lazy" width="400" height="400">
      <?php else: ?>
      <div class="product-card__img product-card__img--placeholder tone-<?= (int) $item['id'] % 6 ?>"><img src="/assets/img/logo-mark.png" width="85" height="96" alt=""></div>
      <?php endif; ?>
      <div class="product-card__body">
        <div>
          <h3 class="product-card__name"><?= e(lang_field($item, 'name')) ?></h3>
          <?php if (!$item['is_available']): ?><span class="badge badge-muted"><?= e(t('menu.sold_out')) ?></span><?php endif; ?>
          <ul class="price-list">
            <?php foreach ($item['variants'] as $variant): ?>
            <li><?= e(lang_field($variant, 'label')) ?> <strong><?= e(money($variant['price_paise'])) ?></strong></li>
            <?php endforeach; ?>
          </ul>
        </div>
        <a class="round-btn" href="<?= e(cross_link($orderUrl, '/')) ?>" aria-label="<?= e(t('site.order_takeaway') . ': ' . lang_field($item, 'name')) ?>">+</a>
      </div>
    </li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<section class="home-section story">
  <div class="story__media">
    <?php if ($storyPhoto !== null): ?>
    <img src="/media.php?f=<?= e(rawurlencode($storyPhoto['file_name'])) ?>" width="<?= (int) $storyPhoto['width'] ?>" height="<?= (int) $storyPhoto['height'] ?>"
         alt="<?= e(lang_field($storyPhoto, 'caption') ?: lang_field($shop, 'shop_name')) ?>" loading="lazy">
    <?php else: ?>
    <?php /* TODO: owner content – a photo of the shop or the family (admin → Website content → Gallery). */ ?>
    <div class="story__art tone-5"><img src="/assets/img/logo-mark.png" width="106" height="120" alt=""></div>
    <?php endif; ?>
  </div>
  <div>
    <p class="eyebrow"><?= e(t('site.nav.about')) ?></p>
    <h2><?= e(t('site.story.title')) ?></h2>
    <p class="story__text"><?= e(t('site.story.text')) ?></p>
    <p class="story__sign"><?= e(t('shop.tagline')) ?></p>
    <a class="text-link" href="/about.php"><?= e(t('site.nav.about')) ?> <?= icon('arrow', 16) ?></a>
  </div>
</section>

<section class="band">
  <div>
    <p class="eyebrow"><?= e(t('site.band.eyebrow')) ?></p>
    <h2><?= e(t('site.band.title')) ?></h2>
    <p><?= e(t('site.bulk.short', ['days' => Settings::int('bulk_notice_days', 1)])) ?></p>
    <a class="btn btn-outline-light" href="/bulk.php"><?= e(t('site.bulk.button')) ?> <?= icon('arrow', 18) ?></a>
  </div>
  <ol class="band__steps">
    <?php for ($n = 1; $n <= 4; $n++): ?>
    <li><span class="band__n">0<?= $n ?></span><span><strong><?= e(t("site.bulk.step{$n}_title")) ?></strong><span><?= e(t("site.bulk.step{$n}")) ?></span></span></li>
    <?php endfor; ?>
  </ol>
</section>

<section class="home-section visit">
  <div>
    <p class="eyebrow"><?= e(t('site.nav.location')) ?></p>
    <h3><?= e(lang_field($shop, 'shop_address')) ?></h3>
    <a class="text-link" href="/location.php"><?= e(t('site.location.directions')) ?></a>
  </div>
  <div>
    <p class="eyebrow"><?= e(t('site.visit.hours')) ?></p>
    <h3><?= e(t('site.feature.hours_title')) ?></h3>
    <p><?= e($hours) ?></p>
  </div>
  <div>
    <p class="eyebrow"><?= e(t('site.nav.contact')) ?></p>
    <?php if ($phone !== ''): ?>
    <h3><a href="tel:<?= e(preg_replace('/[^\d+]/', '', $phone)) ?>"><?= e($phone) ?></a></h3>
    <?php endif; ?>
    <p><?= e(t('site.contact.intro')) ?></p>
  </div>
  <a class="visit__map" href="/location.php" aria-label="<?= e(t('site.location.directions')) ?>"><span class="visit__pin" aria-hidden="true"></span></a>
</section>
<?php
view('site_footer');
