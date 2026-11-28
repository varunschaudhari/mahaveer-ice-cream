<?php

declare(strict_types=1);

// Admin: A4 takeaway QR poster for the door / counter (SPEC §12). The QR points to
// APP_URL_ORDER of this server, so print posters only from production (DEPLOYMENT.md §5).
// On staging the code stays scannable for testing, with a "TEST – not for customers" band above and
// below it; anywhere else a "TEST – do not use" stamp is printed across the QR (QrService::mode).

use App\Auth;
use App\Lang;
use App\Services\QrService;
use App\Settings;

require __DIR__ . '/../boot.php';

$user = Auth::requireAdmin();
$url = rtrim((string) config('APP_URL_ORDER', ''), '/') . '/';
$qrMode = QrService::mode(app_env(), $url);
$shop = Settings::all();
// The poster is printed in both languages, whatever the screen language is.
$mr = Lang::strings('mr');
$en = Lang::strings('en');
?>
<!doctype html>
<html lang="<?= e(lang()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= e(t('qr.title')) ?></title>
<link rel="stylesheet" href="<?= e(asset('css/base.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/poster.css')) ?>">
</head>
<body class="poster-page">
<div class="poster-toolbar">
  <a class="btn btn-secondary" href="/admin/">← <?= e(t('admin.title')) ?></a>
  <button type="button" class="btn btn-primary" data-print-page><?= e(t('qr.print')) ?></button>
  <?php if ($qrMode === 'test'): ?>
  <p class="flash flash-error"><?= e(t('qr.staging_notice', ['url' => $url])) ?></p>
  <?php elseif ($qrMode === 'blocked'): ?>
  <p class="flash flash-error"><?= e(t('qr.not_production', ['url' => $url])) ?></p>
  <?php endif; ?>
</div>

<main class="poster">
  <p class="poster-shop"><?= e($shop['shop_name_mr'] ?? '') ?></p>
  <p class="poster-shop-en"><?= e($shop['shop_name_en'] ?? '') ?></p>
  <p class="poster-title"><?= e($mr['qr.poster_title']) ?></p>
  <p class="poster-title-en"><?= e($en['qr.poster_title']) ?></p>
  <?php if ($qrMode === 'test'): ?><p class="test-band"><?= e($mr['qr.test_band']) ?> · <?= e($en['qr.test_band']) ?></p><?php endif; ?>
  <div class="qr-box">
    <img class="poster-qr" src="<?= e(QrService::svgDataUri($url)) ?>" alt="QR: <?= e($url) ?>" width="560" height="560">
    <?php if ($qrMode === 'blocked'): ?><span class="test-stamp"><?= e($mr['qr.test_stamp']) ?><br><?= e($en['qr.test_stamp']) ?></span><?php endif; ?>
  </div>
  <?php if ($qrMode === 'test'): ?><p class="test-band"><?= e($mr['qr.test_band']) ?> · <?= e($en['qr.test_band']) ?></p><?php endif; ?>
  <ol class="poster-steps">
    <?php foreach (['qr.poster_step_scan', 'qr.poster_step_order', 'qr.poster_step_pickup'] as $step): ?>
    <li><?= e($mr[$step]) ?> · <?= e($en[$step]) ?></li>
    <?php endforeach; ?>
  </ol>
  <p class="poster-url"><?= e(preg_replace('#^https?://#', '', rtrim($url, '/'))) ?></p>
  <p class="poster-phone"><?= e($shop['shop_phone'] ?? '') ?></p>
</main>
<script src="<?= e(asset('js/admin.js')) ?>"></script>
</body>
</html>
