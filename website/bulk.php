<?php

declare(strict_types=1);

// Bulk & party orders (SPEC §13): how it works, the notice needed, button to the request form.

use App\Settings;

require __DIR__ . '/boot.php';

$shop = Settings::all();
$days = Settings::int('bulk_notice_days', 1);
$orderUrl = rtrim((string) config('APP_URL_ORDER', ''), '/');
$phone = (string) ($shop['shop_phone'] ?? '');

view('site_header', ['page' => 'bulk', 'path' => '/bulk.php']);
?>
<section class="page-hero">
  <div>
    <h1><?= e(t('site.nav.bulk')) ?></h1>
    <p class="lead"><?= e(t('bulk.intro', ['days' => $days])) ?></p>
  </div>
  <span class="page-hero__icon"><?= icon('gift', 32) ?></span>
</section>

<ol class="step-cards">
  <?php for ($n = 1; $n <= 4; $n++): ?>
  <li class="step-card">
    <span class="step-card__n"><?= $n ?></span>
    <h2 class="step-card__title"><?= e(t("site.bulk.step{$n}_title")) ?></h2>
    <p><?= e(t("site.bulk.step{$n}")) ?></p>
  </li>
  <?php endfor; ?>
</ol>

<section class="cta-band">
  <div>
    <h2><?= e(t('site.bulk.button')) ?></h2>
    <p><?= e(t('site.bulk.short', ['days' => $days])) ?></p>
  </div>
  <div class="cta-band__actions">
    <a class="btn btn-white btn-lg" href="<?= e(cross_link($orderUrl, '/bulk.php')) ?>"><?= icon('gift') ?><?= e(t('site.bulk.button')) ?></a>
    <?php if ($phone !== ''): ?>
    <a class="btn btn-outline-white btn-lg" href="<?= e('tel:' . preg_replace('/[^\d+]/', '', $phone)) ?>"><?= icon('phone') ?><?= e(t('site.contact.call')) ?></a>
    <?php endif; ?>
  </div>
</section>
<?php
view('site_footer');
