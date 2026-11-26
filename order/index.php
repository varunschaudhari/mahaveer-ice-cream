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

  <?php if ($menu === []): ?>
  <p class="muted"><?= e(t('menu.empty')) ?></p>
  <?php else: ?>
  <nav class="chip-row" aria-label="<?= e(t('menu.categories')) ?>">
    <?php foreach ($menu as $category): ?>
    <a class="chip" href="#cat-<?= (int) $category['id'] ?>"><?= e(lang_field($category, 'name')) ?></a>
    <?php endforeach; ?>
  </nav>

  <?php foreach ($menu as $category): ?>
  <section class="menu-category" id="cat-<?= (int) $category['id'] ?>">
    <h2 class="menu-category__title"><?= e(lang_field($category, 'name')) ?></h2>
    <ul class="shop-list">
      <?php foreach ($category['items'] as $item): $name = lang_field($item, 'name'); ?>
      <li class="shop-item<?= $item['is_available'] ? '' : ' is-sold-out' ?>">
        <div class="shop-item__name">
          <?= e($name) ?>
          <?php if (!$item['is_available']): ?><span class="badge badge-muted"><?= e(t('menu.sold_out')) ?></span><?php endif; ?>
        </div>
        <div class="shop-item__variants">
          <?php foreach ($item['variants'] as $variant): ?>
          <button type="button" class="btn btn-secondary add-btn"
                  data-variant="<?= (int) $variant['id'] ?>" data-name="<?= e($name) ?>"
                  data-label="<?= e(lang_field($variant, 'label')) ?>" data-price="<?= (int) $variant['price_paise'] ?>"
                  <?= $item['is_available'] ? '' : 'disabled' ?>>
            <?= e(lang_field($variant, 'label')) ?> <?= e(money($variant['price_paise'])) ?> <span aria-hidden="true">+</span>
          </button>
          <?php endforeach; ?>
        </div>
      </li>
      <?php endforeach; ?>
    </ul>
  </section>
  <?php endforeach; ?>
  <?php endif; ?>

  <section class="card stack checkout" id="checkout" data-checkout hidden>
    <h2><?= e(t('order.your_order')) ?></h2>
    <div class="checkout-lines" data-cart-lines></div>
    <dl class="totals">
      <div><dt><?= e(t('billing.subtotal')) ?></dt><dd data-subtotal></dd></div>
      <div><dt><?= e(t('billing.parcel_charge')) ?></dt><dd data-parcel-amount></dd></div>
      <div class="totals__grand"><dt><?= e(t('billing.total')) ?></dt><dd data-total></dd></div>
    </dl>

    <form class="stack" data-checkout-form novalidate>
      <div class="field">
        <label for="c-name"><?= e(t('checkout.name')) ?></label>
        <input class="input" id="c-name" name="name" maxlength="60" autocomplete="name" required>
        <p class="field-error" data-error-for="name" hidden></p>
      </div>
      <div class="field">
        <label for="c-mobile"><?= e(t('checkout.mobile')) ?></label>
        <input class="input" id="c-mobile" name="mobile" type="tel" inputmode="numeric" maxlength="14" autocomplete="tel" required>
        <p class="field-error" data-error-for="mobile" hidden></p>
      </div>
      <fieldset class="field slot-field">
        <legend><?= e(t('checkout.slot')) ?></legend>
        <div class="slot-grid" data-slots>
          <?php foreach ($slots['slots'] as $slot): ?>
          <label class="slot<?= $slot['full'] ? ' is-full' : '' ?>">
            <input type="radio" name="slot" value="<?= e($slot['start']) ?>"<?= $slot['full'] ? ' disabled' : '' ?>>
            <span><?= e(format_time($slot['start'])) ?></span>
            <?php if ($slot['full']): ?><small><?= e(t('order.slot_full')) ?></small><?php endif; ?>
          </label>
          <?php endforeach; ?>
        </div>
        <p class="field-error" data-error-for="slot" hidden></p>
      </fieldset>
      <div class="field">
        <label for="c-note"><?= e(t('checkout.note')) ?></label>
        <textarea class="textarea" id="c-note" name="note" maxlength="200" placeholder="<?= e(t('checkout.note_hint')) ?>"></textarea>
        <p class="field-error" data-error-for="note" hidden></p>
      </div>
      <p class="notice"><?= e(t('checkout.pay_at_counter')) ?></p>
      <p class="flash flash-error" data-error-for="_" role="alert" hidden></p>
      <button type="submit" class="btn btn-primary btn-block btn-lg" data-place<?= $slots['open'] ? '' : ' disabled' ?>><?= e(t('checkout.place')) ?></button>
      <button type="button" class="btn btn-secondary btn-block" data-back><?= e(t('checkout.add_more')) ?></button>
    </form>
  </section>
</div>

<div class="cart-bar" data-cart-bar hidden>
  <span><span data-cart-count></span> · <strong data-cart-total></strong></span>
  <button type="button" class="btn btn-primary" data-open-cart><?= e(t('order.view_cart')) ?></button>
</div>
<script src="<?= e(asset('js/shop.js')) ?>"></script>
<?php
view('footer');
