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

  <section class="shop-hero">
    <div class="shop-hero__text">
      <?php if ($slots['open']): ?>
      <p class="open-pill"><span class="open-pill__dot" aria-hidden="true"></span><?= e(t('order.open_until', ['time' => format_time(Settings::get('close_time', '22:30'))])) ?></p>
      <?php else: ?>
      <p class="open-pill is-closed"><span class="open-pill__dot" aria-hidden="true"></span><?= e(t('shop.closed', ['time' => format_time($slots['opens_at'])])) ?></p>
      <?php endif; ?>
      <h1><?= e(t('order.heading')) ?></h1>
      <p class="shop-hero__intro"><?= e(t('order.intro')) ?></p>
      <ol class="hero-steps">
        <li><span class="hero-steps__n">1</span><?= e(t('order.step_choose')) ?></li>
        <li><span class="hero-steps__n">2</span><?= e(t('order.step_time')) ?></li>
        <li><span class="hero-steps__n">3</span><?= e(t('order.step_pay')) ?></li>
      </ol>
      <a class="party-link" href="/bulk.php"><?= e(t('bulk.link')) ?></a>
    </div>
    <div class="shop-hero__art" aria-hidden="true"><img src="/assets/img/logo-mark.svg" width="120" height="120" alt=""></div>
  </section>

  <?php if ($recent !== []): ?>
  <section class="recent-orders">
    <h2 class="section-label"><?= e(t('order.recent')) ?></h2>
    <ul class="recent-list">
      <?php foreach ($recent as $order):
          $lines = array_map(static fn (array $l): array => ['v' => (int) $l['variant_id'], 'q' => (int) $l['qty']], $order['items']);
      ?>
      <li class="recent-order">
        <a class="recent-order__link" href="/order.php?id=<?= (int) $order['id'] ?>&amp;k=<?= e($order['order_key']) ?>">
          <span class="recent-order__token"><?= (int) $order['token_no'] ?></span>
          <span class="recent-order__info">
            <strong><?= e(t('online.token', ['n' => $order['token_no']])) ?></strong>
            <span class="muted text-sm"><?= e(date('d/m', strtotime($order['created_at']))) ?> · <?= e(money(paise_from_decimal((string) $order['total']))) ?> · <?= e(order_status_label($order['status'])) ?></span>
          </span>
        </a>
        <button type="button" class="btn btn-soft" data-reorder="<?= e(json_encode($lines)) ?>"><?= e(t('order.reorder')) ?></button>
      </li>
      <?php endforeach; ?>
    </ul>
  </section>
  <?php endif; ?>

  <div class="shop-layout">
    <div class="shop-main">
      <?php view('shop_menu', ['menu' => $menu]); ?>
    </div>
    <aside class="shop-aside">
      <?php view('shop_checkout', ['mode' => 'takeaway', 'slots' => $slots, 'open' => $slots['open']]); ?>
    </aside>
  </div>
</div>

<?php view('shop_cart_bar'); ?>
<script src="<?= e(asset('js/shop.js')) ?>"></script>
<?php
view('footer');
