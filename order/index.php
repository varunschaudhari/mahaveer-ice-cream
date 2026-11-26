<?php

declare(strict_types=1);

// Takeaway ordering on the customer's phone (SPEC §6): menu → cart → checkout (name, mobile,
// pickup slot, note) → "pay at counter on pickup". Cart lives in sessionStorage (shop.js).

use App\RecentOrders;
use App\Services\MenuService;
use App\Services\OrderService;
use App\Services\SlotService;
use App\Settings;

require __DIR__ . '/boot.php';

$menuService = new MenuService();
$menuService->resetStockIfNewDay();
$menu = $menuService->publicMenu();
$slots = (new SlotService())->today(now());

// This phone's own recent orders (cookie with each order's secret key; never looked up by number).
$orders = new OrderService();
$recent = [];
foreach (RecentOrders::read() as $ref) {
    $order = $orders->findForCustomer($ref['id'], $ref['key']);
    if ($order !== null) {
        $recent[] = $order;
    }
}

$texts = [
    'items' => t('order.items_count'),
    'error' => t('error.generic'),
    'offline' => t('order.offline'),
    'name_required' => t('checkout.error.name'),
    'mobile_invalid' => t('checkout.error.mobile'),
    'slot_required' => t('checkout.error.slot'),
    'reorder_partial' => t('order.reorder_partial'),
    'no_slots' => t('order.no_slots'),
    'full' => t('order.slot_full'),
];

view('header', ['title' => t('order.page_title'), 'css' => ['css/base.css', 'css/shop.css']]);
?>
<div class="shop" data-shop
     data-parcel="<?= Settings::paise('parcel_charge') ?>"
     data-open="<?= $slots['open'] ? '1' : '0' ?>"
     data-texts="<?= e(json_encode($texts, JSON_UNESCAPED_UNICODE)) ?>">

  <?php if (!$slots['open']): ?>
  <p class="notice notice-danger"><?= e(t('shop.closed', ['time' => format_time($slots['opens_at'])])) ?></p>
  <?php endif; ?>

  <h1><?= e(t('order.heading')) ?></h1>
  <p class="muted"><?= e(t('order.intro')) ?></p>

  <?php if ($recent !== []): ?>
  <section class="card stack-sm recent-orders">
    <h2><?= e(t('order.recent')) ?></h2>
    <ul class="recent-list">
      <?php foreach ($recent as $order):
          $lines = array_map(static fn (array $l): array => ['v' => (int) $l['variant_id'], 'q' => (int) $l['qty']], $order['items']);
      ?>
      <li class="recent-order">
        <a href="/order.php?id=<?= (int) $order['id'] ?>&amp;k=<?= e($order['order_key']) ?>">
          <strong><?= e(t('online.token', ['n' => $order['token_no']])) ?></strong>
          · <?= e(date('d/m', strtotime($order['created_at']))) ?>
          · <?= e(money(paise_from_decimal((string) $order['total']))) ?>
          · <span class="muted"><?= e(order_status_label($order['status'])) ?></span>
        </a>
        <button type="button" class="btn btn-secondary" data-reorder="<?= e(json_encode($lines)) ?>"><?= e(t('order.reorder')) ?></button>
      </li>
      <?php endforeach; ?>
    </ul>
  </section>
  <?php endif; ?>

  <?php view('shop_menu', ['menu' => $menu]); ?>
  <?php view('shop_checkout', ['mode' => 'takeaway', 'slots' => $slots, 'open' => $slots['open']]); ?>
</div>

<div class="cart-bar" data-cart-bar hidden>
  <span><span data-cart-count></span> · <strong data-cart-total></strong></span>
  <button type="button" class="btn btn-primary" data-open-cart><?= e(t('order.view_cart')) ?></button>
</div>
<script src="<?= e(asset('js/shop.js')) ?>"></script>
<?php
view('footer');
