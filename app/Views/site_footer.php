<?php
/**
 * Public website page end. Optional var: $scripts (list of paths under assets/, loaded deferred).
 */

use App\Services\SiteService;
use App\Settings;

$shop = Settings::all();
$scripts ??= [];
$phone = (string) ($shop['shop_phone'] ?? '');
$whatsapp = SiteService::whatsappUrl($shop);
$orderUrl = rtrim((string) config('APP_URL_ORDER', ''), '/');
?>
</main>
<footer class="site-footer">
  <div class="container site-footer__grid">
    <div class="stack-sm">
      <p class="site-footer__name"><?= e(lang_field($shop, 'shop_name')) ?></p>
      <p><?= e(t('shop.tagline')) ?> · <?= e(t('shop.years')) ?></p>
      <p><?= e(lang_field($shop, 'shop_address')) ?></p>
      <p><?= e(t('shop.hours', ['open' => format_time($shop['open_time'] ?? '10:00'), 'close' => format_time($shop['close_time'] ?? '22:30')])) ?></p>
    </div>
    <div class="stack-sm">
      <?php if ($phone !== ''): ?>
      <p><a href="tel:<?= e(preg_replace('/[^\d+]/', '', $phone)) ?>"><?= e(t('site.contact.call')) ?>: <?= e($phone) ?></a></p>
      <?php endif; ?>
      <?php if ($whatsapp !== null): ?>
      <p><a href="<?= e($whatsapp) ?>" rel="noopener"><?= e(t('site.contact.whatsapp')) ?></a></p>
      <?php endif; ?>
      <p><a href="<?= e($orderUrl . '/') ?>"><?= e(t('site.order_takeaway')) ?></a></p>
      <p><a href="/location.php"><?= e(t('site.nav.location')) ?></a></p>
    </div>
  </div>
  <p class="container text-sm site-footer__copy">© <?= e(now()->format('Y')) ?> <?= e(lang_field($shop, 'shop_name')) ?></p>
</footer>
<?php foreach ($scripts as $script): ?>
<script src="<?= e(asset($script)) ?>" defer></script>
<?php endforeach; ?>
</body>
</html>
