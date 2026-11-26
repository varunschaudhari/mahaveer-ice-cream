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
<h1><?= e(t('site.nav.bulk')) ?></h1>
<p class="lead"><?= e(t('bulk.intro', ['days' => $days])) ?></p>

<ol class="steps">
  <li><strong><?= e(t('site.bulk.step1_title')) ?></strong><br><?= e(t('site.bulk.step1')) ?></li>
  <li><strong><?= e(t('site.bulk.step2_title')) ?></strong><br><?= e(t('site.bulk.step2')) ?></li>
  <li><strong><?= e(t('site.bulk.step3_title')) ?></strong><br><?= e(t('site.bulk.step3')) ?></li>
  <li><strong><?= e(t('site.bulk.step4_title')) ?></strong><br><?= e(t('site.bulk.step4')) ?></li>
</ol>

<div class="cluster">
  <a class="btn btn-primary btn-lg" href="<?= e($orderUrl . '/bulk.php') ?>"><?= e(t('site.bulk.button')) ?></a>
  <?php if ($phone !== ''): ?>
  <a class="btn btn-secondary btn-lg" href="tel:<?= e(preg_replace('/[^\d+]/', '', $phone)) ?>"><?= e(t('site.contact.call')) ?></a>
  <?php endif; ?>
</div>
<?php
view('site_footer');
