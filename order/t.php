<?php

declare(strict_types=1);

// Table ordering (SPEC §7): t.php?t=<table QR code>. "टेबल 4 / Table 4" at the top, the table's
// current orders with live status, Call staff / Request bill, then the menu. No slot, no parcel
// charge; every order joins the table's running bill. Pay at the counter before leaving.

use App\Lang;
use App\Services\MenuService;
use App\Services\TableService;

require __DIR__ . '/boot.php';

$tables = new TableService();
$table = $tables->findByToken((string) ($_GET['t'] ?? ''));
if ($table === null) {
    http_response_code(404);
    view('error', ['message' => t('table.error.invalid')]);
    exit;
}

$menuService = new MenuService();
$menuService->resetStockIfNewDay();
$menu = $menuService->publicMenu();
$session = $tables->openSession((int) $table['id']);
$number = table_number($table['code']);
// The banner is always "टेबल 4 / Table 4", whatever the screen language (SPEC §7.1).
$mr = Lang::strings('mr');
$en = Lang::strings('en');

$texts = [
    'items' => t('order.items_count'),
    'error' => t('error.generic'),
    'offline' => t('order.offline'),
    'name_required' => t('checkout.error.name'),
    'mobile_invalid' => t('checkout.error.mobile'),
    'reorder_partial' => t('order.reorder_partial'),
    'ordered_as' => t('table.ordering_as'),
    'placed' => t('table.order_placed'),
    'confirm_bill' => t('table.confirm_request_bill'),
];

view('header', ['title' => table_label($table['code'], $table['area']), 'css' => ['css/base.css', 'css/shop.css']]);
?>
<div class="shop table-shop" data-shop data-mode="table"
     data-table-token="<?= e($table['qr_token']) ?>"
     data-session="<?= $session === null ? '' : (int) $session['id'] ?>"
     data-parcel="0" data-open="1"
     data-texts="<?= e(json_encode($texts, JSON_UNESCAPED_UNICODE)) ?>">

  <section class="table-hero">
    <span class="table-hero__badge" aria-hidden="true"><?= e($number) ?></span>
    <p class="table-banner">
      <?= e(str_replace('{n}', $number, $mr['table.label'])) ?>
      <span>/ <?= e(str_replace('{n}', $number, $en['table.label'])) ?></span>
      <?php if ($table['area'] === 'outside'): ?><small><?= e(t('table.outside')) ?></small><?php endif; ?>
    </p>
    <div class="table-buttons">
      <button type="button" class="btn table-btn" data-table-alert="call_staff">
        <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M12 22a2.5 2.5 0 0 0 2.5-2.5h-5A2.5 2.5 0 0 0 12 22zm7-6V11a7 7 0 0 0-5.5-6.8V3.5a1.5 1.5 0 0 0-3 0v.7A7 7 0 0 0 5 11v5l-2 2v1h18v-1z" fill="currentColor"/></svg>
        <?= e(t('table.call_staff')) ?>
      </button>
      <button type="button" class="btn table-btn" data-table-alert="request_bill"<?= $session === null ? ' hidden' : '' ?>>
        <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M5 2v20l2.5-1.5L10 22l2-1.5 2 1.5 2.5-1.5L19 22V2l-2.5 1.5L14 2l-2 1.5L10 2 7.5 3.5zm3 6h8v2H8zm0 4h8v2H8z" fill="currentColor"/></svg>
        <?= e(t('table.request_bill')) ?>
      </button>
    </div>
  </section>
  <p class="flash" data-table-message role="status" hidden></p>

  <section class="card table-orders" data-table-orders data-token="<?= e($table['qr_token']) ?>"<?= $session === null ? ' hidden' : '' ?>>
    <h2 class="section-label"><?= e(t('table.your_orders')) ?></h2>
    <ul class="table-order-list" data-table-order-list></ul>
    <p class="spread table-total"><span><?= e(t('table.running_total')) ?></span><strong data-table-total></strong></p>
    <p class="notice" data-bill-requested hidden><?= e(t('table.bill_requested')) ?></p>
  </section>

  <h1 class="sr-only"><?= e(t('menu.title')) ?></h1>
  <div class="shop-layout">
    <div class="shop-main">
      <?php view('shop_menu', ['menu' => $menu]); ?>
    </div>
    <aside class="shop-aside">
      <?php view('shop_checkout', ['mode' => 'table', 'open' => true]); ?>
    </aside>
  </div>
</div>

<?php view('shop_cart_bar'); ?>
<script src="<?= e(asset('js/shop.js')) ?>"></script>
<script src="<?= e(asset('js/table.js')) ?>"></script>
<?php
view('footer');
