<?php

declare(strict_types=1);

// Admin: A4 sheet of table QR stickers (SPEC §12): table number + QR + "Scan to order", both
// languages. Print only from production — the QR codes point to this server's APP_URL_ORDER;
// on staging the codes stay scannable with a "TEST" band; anywhere else a "TEST – do not use" stamp
// is printed across each QR (QrService::mode).

use App\Auth;
use App\Lang;
use App\Services\QrService;
use App\Services\TableService;

require __DIR__ . '/../boot.php';

$user = Auth::requireAdmin();
$base = rtrim((string) config('APP_URL_ORDER', ''), '/');
$qrMode = QrService::mode(app_env(), $base . '/');
$active = array_filter((new TableService())->tables(), static fn (array $t): bool => (bool) $t['is_active']);
$mr = Lang::strings('mr');
$en = Lang::strings('en');
?>
<!doctype html>
<html lang="<?= e(lang()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= e(t('tables.admin.print_stickers')) ?></title>
<link rel="stylesheet" href="<?= e(asset('css/base.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/poster.css')) ?>">
</head>
<body class="poster-page">
<div class="poster-toolbar">
  <a class="btn btn-secondary" href="/admin/tables.php">← <?= e(t('admin.nav.tables')) ?></a>
  <button type="button" class="btn btn-primary" data-print-page><?= e(t('qr.print')) ?></button>
  <?php if ($qrMode === 'test'): ?>
  <p class="flash flash-error"><?= e(t('qr.staging_notice', ['url' => $base . '/'])) ?></p>
  <?php elseif ($qrMode === 'blocked'): ?>
  <p class="flash flash-error"><?= e(t('qr.not_production', ['url' => $base . '/'])) ?></p>
  <?php endif; ?>
</div>
<main class="sticker-sheet">
  <?php foreach ($active as $table): $number = table_number($table['code']); ?>
  <section class="sticker">
    <p class="sticker-table"><?= e(str_replace('{n}', $number, $mr['table.label'])) ?></p>
    <p class="sticker-table-en"><?= e(str_replace('{n}', $number, $en['table.label'])) ?><?= $table['area'] === 'outside' ? ' · ' . e($en['table.outside']) : '' ?></p>
    <div class="qr-box">
      <img class="sticker-qr" src="<?= e(QrService::svgDataUri($base . '/t.php?t=' . $table['qr_token'])) ?>" alt="QR <?= e($table['code']) ?>" width="240" height="240">
      <?php if ($qrMode === 'blocked'): ?><span class="test-stamp"><?= e($mr['qr.test_stamp']) ?><br><?= e($en['qr.test_stamp']) ?></span><?php endif; ?>
    </div>
    <?php if ($qrMode === 'test'): /* a test sticker has no room for both: the band replaces "Scan to order" */ ?>
    <p class="test-band"><?= e($mr['qr.test_band']) ?> · <?= e($en['qr.test_band']) ?></p>
    <?php else: ?>
    <p class="sticker-scan"><?= e($mr['tables.sticker_scan']) ?></p>
    <p class="sticker-scan-en"><?= e($en['tables.sticker_scan']) ?></p>
    <?php endif; ?>
  </section>
  <?php endforeach; ?>
</main>
<script src="<?= e(asset('js/admin.js')) ?>"></script>
</body>
</html>
