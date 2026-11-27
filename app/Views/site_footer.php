<?php
/**
 * Public website page end: footer (visit, contact, pages) and, on phones, a bottom bar with
 * Call + Order online. Optional var: $scripts (list of paths under assets/, loaded deferred).
 */

use App\Services\SiteService;
use App\Settings;

$shop = Settings::all();
$scripts ??= [];
$phone = (string) ($shop['shop_phone'] ?? '');
$tel = 'tel:' . preg_replace('/[^\d+]/', '', $phone);
$whatsapp = SiteService::whatsappUrl($shop);
$orderUrl = rtrim((string) config('APP_URL_ORDER', ''), '/');
$hours = t('shop.hours', ['open' => format_time($shop['open_time'] ?? '10:00'), 'close' => format_time($shop['close_time'] ?? '22:30')]);
?>
</main>
<footer class="site-footer">
  <div class="container site-footer__grid">
    <div class="site-footer__brand">
      <p class="site-footer__name"><img src="/assets/img/logo-compact.png" width="115" height="80" alt="<?= e(lang_field($shop, 'shop_name')) ?>"></p>
      <p class="site-footer__tagline"><?= e(t('shop.tagline')) ?></p>
      <p class="site-footer__years"><?= e(t('shop.years')) ?></p>
    </div>
    <div>
      <h2 class="site-footer__title"><?= e(t('site.visit_us')) ?></h2>
      <ul class="site-footer__list">
        <li><?= icon('pin', 18) ?><span><?= e(lang_field($shop, 'shop_address')) ?></span></li>
        <li><?= icon('clock', 18) ?><span><?= e($hours) ?></span></li>
        <li><?= icon('directions', 18) ?><a href="/location.php"><?= e(t('site.location.directions')) ?></a></li>
      </ul>
    </div>
    <div>
      <h2 class="site-footer__title"><?= e(t('site.nav.contact')) ?></h2>
      <ul class="site-footer__list">
        <?php if ($phone !== ''): ?>
        <li><?= icon('phone', 18) ?><a href="<?= e($tel) ?>"><?= e($phone) ?></a></li>
        <?php endif; ?>
        <?php if ($whatsapp !== null): ?>
        <li><?= icon('chat', 18) ?><a href="<?= e($whatsapp) ?>" rel="noopener"><?= e(t('site.contact.whatsapp')) ?></a></li>
        <?php endif; ?>
        <li><?= icon('bag', 18) ?><a href="<?= e($orderUrl . '/') ?>"><?= e(t('site.order_takeaway')) ?></a></li>
        <li><?= icon('gift', 18) ?><a href="/bulk.php"><?= e(t('site.nav.bulk')) ?></a></li>
      </ul>
    </div>
    <nav aria-label="<?= e(t('site.footer.pages')) ?>">
      <h2 class="site-footer__title"><?= e(t('site.footer.pages')) ?></h2>
      <ul class="site-footer__links">
        <?php foreach (SiteService::PAGES as $key => $href): ?>
        <li><a href="<?= e($href) ?>"><?= e(t('site.nav.' . $key)) ?></a></li>
        <?php endforeach; ?>
      </ul>
    </nav>
  </div>
  <p class="container site-footer__copy">© <?= e(now()->format('Y')) ?> <?= e(lang_field($shop, 'shop_name')) ?></p>
</footer>
<div class="action-bar">
  <?php if ($phone !== ''): ?>
  <a class="action-bar__call" href="<?= e($tel) ?>"><?= icon('phone', 20) ?><?= e(t('site.contact.call')) ?></a>
  <?php endif; ?>
  <a class="action-bar__order" href="<?= e($orderUrl . '/') ?>"><?= icon('bag', 20) ?><?= e(t('site.order_online')) ?></a>
</div>
<?php foreach ($scripts as $script): ?>
<script src="<?= e(asset($script)) ?>" defer></script>
<?php endforeach; ?>
</body>
</html>
