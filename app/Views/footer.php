<?php

use App\Services\SiteService;
use App\Settings;

$shop = Settings::all();
$phone = $shop['shop_phone'] ?? '';
$websiteUrl = (string) config('APP_URL_WEBSITE', '');
?>
</main>
<footer class="site-footer shop-footer">
  <div class="container shop-footer__inner">
    <p class="shop-footer__name"><?= e(lang_field($shop, 'shop_name')) ?></p>
    <p class="shop-footer__row">
      <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path d="M12 2a7 7 0 0 0-7 7c0 5.2 7 13 7 13s7-7.8 7-13a7 7 0 0 0-7-7zm0 9.5A2.5 2.5 0 1 1 12 6.5a2.5 2.5 0 0 1 0 5z" fill="currentColor"/></svg>
      <span><?= e(lang_field($shop, 'shop_address')) ?></span>
    </p>
    <p class="shop-footer__row">
      <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="2"/><path d="M12 7v5l3 2" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
      <span><?= e(t('shop.hours', [
          'open' => format_time($shop['open_time'] ?? '10:00'),
          'close' => format_time($shop['close_time'] ?? '22:30'),
      ])) ?></span>
    </p>
    <?php if ($phone !== ''): ?>
    <p class="shop-footer__row">
      <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path d="M6.6 10.8a15.1 15.1 0 0 0 6.6 6.6l2.2-2.2a1 1 0 0 1 1-.25 11.4 11.4 0 0 0 3.6.57 1 1 0 0 1 1 1V20a1 1 0 0 1-1 1A17 17 0 0 1 3 4a1 1 0 0 1 1-1h3.5a1 1 0 0 1 1 1c0 1.25.2 2.45.57 3.6a1 1 0 0 1-.25 1z" fill="currentColor"/></svg>
      <a href="tel:<?= e(preg_replace('/[^\d+]/', '', $phone)) ?>"><?= e($phone) ?></a>
    </p>
    <?php endif; ?>
    <?php if ($websiteUrl !== ''): ?>
    <nav class="shop-footer__links" aria-label="<?= e(t('order.website_links')) ?>">
      <span class="shop-footer__links-label"><?= e(t('order.website_links')) ?></span>
      <?php foreach (['home', 'menu', 'about', 'location', 'contact'] as $key): ?>
      <a href="<?= e(cross_link($websiteUrl, SiteService::PAGES[$key])) ?>"><?= e(t('site.nav.' . $key)) ?></a>
      <?php endforeach; ?>
    </nav>
    <?php endif; ?>
  </div>
</footer>
</body>
</html>
