<?php

declare(strict_types=1);

// About (SPEC §13): the family story the owner writes in admin → Website content.

use App\Services\SiteService;
use App\Settings;

require __DIR__ . '/boot.php';

$shop = Settings::all();
$about = (new SiteService())->text('about');
$text = lang_field($about, 'value');
// Blank lines start a new paragraph; single line breaks stay inside it.
$paragraphs = array_values(array_filter(array_map('trim', preg_split('/\n\s*\n/', $text) ?: []), static fn (string $p): bool => $p !== ''));

view('site_header', ['page' => 'about', 'path' => '/about.php']);
?>
<section class="page-hero">
  <div>
    <h1><?= e(t('site.nav.about')) ?></h1>
    <p class="lead"><?= e(t('shop.tagline')) ?></p>
  </div>
  <p class="pill"><?= icon('star', 16) ?><?= e(t('shop.years')) ?></p>
</section>

<div class="about-grid">
  <article class="content-card prose">
    <?php if ($paragraphs === []): ?>
    <p class="muted"><?= e(t('site.about_placeholder')) ?></p>
    <?php endif; ?>
    <?php foreach ($paragraphs as $paragraph): ?>
    <p><?= nl2br(e($paragraph), false) ?></p>
    <?php endforeach; ?>
  </article>
  <aside class="content-card about-facts">
    <div class="about-facts__art"><img src="/assets/img/logo-mark.png" width="57" height="64" alt=""></div>
    <ul class="icon-list">
      <li><?= icon('pin', 18) ?><span><?= e(lang_field($shop, 'shop_address')) ?></span></li>
      <li><?= icon('clock', 18) ?><span><?= e(t('shop.hours', ['open' => format_time($shop['open_time'] ?? '10:00'), 'close' => format_time($shop['close_time'] ?? '22:30')])) ?></span></li>
    </ul>
    <a class="btn btn-primary btn-block" href="/menu.php"><?= e(t('site.nav.menu')) ?></a>
  </aside>
</div>
<?php
view('site_footer');
