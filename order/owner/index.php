<?php

declare(strict_types=1);

// Owner's phone (SPEC §11): installable page (manifest + service worker) with today's sales,
// live takeaway / table / bulk orders, Accept / Cancel / Approve, and Web Push with a mute
// switch per phone. The lists refresh from api/poll.php?view=owner every 10 s and beep on new
// orders while the page is open (the fallback when notifications are blocked).

use App\Auth;
use App\Services\PushService;
use App\Settings;

require __DIR__ . '/../boot.php';

$user = Auth::requireAdmin('/admin/?next=%2Fowner%2F');

$keys = [
    'owner.today_sales', 'owner.bills', 'owner.alerts', 'owner.orders', 'owner.no_orders', 'owner.bulk', 'owner.no_bulk',
    'owner.offline', 'owner.updated', 'owner.table', 'owner.token', 'owner.pickup', 'owner.age', 'owner.just_now',
    'owner.accept', 'owner.cancel', 'owner.approve', 'owner.reject', 'owner.paid', 'owner.advance',
    'owner.cancel_title', 'owner.cancel_bulk_title', 'owner.reject_title', 'owner.approve_title',
    'owner.advance_label', 'owner.advance_help', 'owner.reason_other',
    'owner.push.enable', 'owner.push.on', 'owner.push.muted', 'owner.push.unsupported',
    'owner.push.not_configured', 'owner.push.denied', 'owner.push.failed',
];
$texts = [];
foreach ($keys as $key) {
    $texts[substr($key, strlen('owner.'))] = t($key); // owner.js uses the short names
}
$texts['error'] = t('error.generic');
$cancelReasons = [t('cancel.reason.customer_called'), t('cancel.reason.out_of_stock'), t('owner.reason.busy'), t('cancel.reason.duplicate')];
$bulkReasons = [t('cancel.reason.customer_called'), t('cancel.reason.out_of_stock'), t('owner.reason.busy'), t('owner.reason.unpaid_bulk')];
$shopName = lang_field(Settings::all(), 'shop_name');
?>
<!doctype html>
<html lang="<?= e(lang()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<meta name="theme-color" content="#FBF8F3">
<?= csrf_meta() ?>
<title><?= e(t('owner.title')) ?> – <?= e($shopName) ?></title>
<link rel="manifest" href="/owner/manifest.json">
<link rel="icon" href="/assets/img/favicon.png" type="image/png">
<link rel="icon" href="/assets/img/icon-192.png" sizes="192x192">
<link rel="apple-touch-icon" href="/assets/img/icon-192.png">
<link rel="stylesheet" href="<?= e(asset('css/base.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/owner.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/app-nav.css')) ?>">
</head>
<body class="owner has-nav">
<?php view('app_nav', ['user' => $user, 'active' => 'owner', 'tag' => t('owner.title')]); ?>

<main class="container page stack" data-owner
      data-lang="<?= e(lang()) ?>"
      data-vapid="<?= e(PushService::isConfigured() ? (string) config('VAPID_PUBLIC') : '') ?>"
      data-texts="<?= e(json_encode($texts, JSON_UNESCAPED_UNICODE)) ?>"
      data-cancel-reasons="<?= e(json_encode($cancelReasons, JSON_UNESCAPED_UNICODE)) ?>"
      data-bulk-reasons="<?= e(json_encode($bulkReasons, JSON_UNESCAPED_UNICODE)) ?>">

  <p class="owner-offline" data-offline hidden><?= e(t('owner.offline')) ?></p>
  <p class="owner-hint" data-sound-hint hidden><?= e(t('owner.sound_hint')) ?></p>
  <p class="owner-toast" data-toast role="status" hidden></p>

  <section class="owner-sales">
    <p class="owner-sales__label"><?= e(t('owner.today_sales')) ?></p>
    <p class="owner-sales__total" data-sales>–</p>
    <p class="owner-sales__meta"><span data-bills></span> <span data-updated></span></p>
  </section>

  <section class="card stack-sm owner-push" data-push>
    <div class="spread">
      <h2 class="owner-h2"><?= e(t('owner.push.heading')) ?></h2>
      <label class="owner-switch" data-push-mute-row hidden>
        <input type="checkbox" data-push-mute>
        <span><?= e(t('owner.push.mute')) ?></span>
      </label>
    </div>
    <p class="text-sm" data-push-state><?= e(t('owner.loading')) ?></p>
    <div class="cluster">
      <button type="button" class="btn btn-primary" data-push-enable hidden><?= e(t('owner.push.enable')) ?></button>
      <button type="button" class="btn btn-secondary" data-push-test hidden><?= e(t('owner.push.test')) ?></button>
    </div>
    <p class="muted text-sm" data-install-hint><?= e(t('owner.push.install_hint')) ?></p>
  </section>

  <section class="stack-sm" data-alerts-section hidden>
    <h2 class="owner-h2"><?= e(t('owner.alerts')) ?></h2>
    <ul class="owner-alerts" data-alerts></ul>
  </section>

  <section class="stack-sm">
    <h2 class="owner-h2"><?= e(t('owner.orders')) ?></h2>
    <p class="muted" data-orders-empty><?= e(t('owner.loading')) ?></p>
    <div class="owner-list" data-orders></div>
  </section>

  <section class="stack-sm">
    <h2 class="owner-h2"><?= e(t('owner.bulk')) ?></h2>
    <p class="muted" data-bulk-empty><?= e(t('owner.loading')) ?></p>
    <div class="owner-list" data-bulk></div>
  </section>
</main>

<dialog class="owner-dialog" data-dialog>
  <form method="dialog" class="stack" data-dialog-form>
    <h2 class="owner-h2" data-dialog-title></h2>
    <div class="owner-choices" data-dialog-choices></div>
    <div class="field" data-dialog-field>
      <label for="owner-dialog-input" data-dialog-label></label>
      <input class="input" id="owner-dialog-input" data-dialog-input autocomplete="off">
      <p class="muted text-sm" data-dialog-help></p>
    </div>
    <div class="cluster owner-dialog__actions">
      <button type="button" class="btn btn-secondary" data-dialog-back><?= e(t('owner.back')) ?></button>
      <button type="submit" class="btn btn-primary" data-dialog-ok><?= e(t('owner.confirm')) ?></button>
    </div>
  </form>
</dialog>

<script src="<?= e(asset('js/owner.js')) ?>"></script>
<script src="<?= e(asset('js/app-nav.js')) ?>"></script>
</body>
</html>
