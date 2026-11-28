<?php

declare(strict_types=1);

// Counter Billing tab (SPEC §9): category → item → Half/Full, cart, Dine-in/Parcel,
// Cash / UPI / Mixed, discount (owner PIN), Save & Print.

use App\Auth;
use App\Services\MenuService;
use App\Settings;

require __DIR__ . '/../boot.php';

$user = Auth::requireStaff();
$menuService = new MenuService();
$menuService->resetStockIfNewDay();
$menu = $menuService->counterMenu();

$texts = [
    'saved' => t('billing.saved'),
    'items' => t('order.items_count'),
    'duplicate' => t('billing.duplicate'),
    'confirm_clear' => t('billing.confirm_clear'),
    'discount_amount' => t('billing.discount_amount'),
    'discount_reason' => t('billing.discount_reason'),
    'discount_approved' => t('billing.discount_approved'),
    'remove_discount' => t('billing.remove_discount'),
    'discount' => t('billing.discount'),
    'remove' => t('billing.remove'),
];
$discountReasons = [
    t('discount.reason.regular'),
    t('discount.reason.large_order'),
    t('discount.reason.staff'),
    t('discount.reason.complaint'),
];

view('counter_head', ['title' => t('counter.tab.billing')]);
view('counter_frame_start', ['user' => $user, 'active' => 'billing', 'withCart' => true]);
?>
<section class="counter-panel billing-menu" data-billing
         data-parcel="<?= Settings::paise('parcel_charge') ?>"
         data-texts="<?= e(json_encode($texts, JSON_UNESCAPED_UNICODE)) ?>"
         data-discount-reasons="<?= e(json_encode($discountReasons, JSON_UNESCAPED_UNICODE)) ?>">
  <?php if ($menu === []): ?>
  <p class="notice"><?= e(t('menu.empty')) ?></p>
  <?php endif; ?>
  <div class="category-bar">
    <?php foreach ($menu as $i => $category): ?>
    <button type="button" class="counter-btn<?= $i === 0 ? ' is-selected' : '' ?>" data-category="<?= (int) $category['id'] ?>">
      <?= e(lang_field($category, 'name')) ?>
    </button>
    <?php endforeach; ?>
  </div>
  <?php foreach ($menu as $i => $category): ?>
  <div class="item-grid" data-category-panel="<?= (int) $category['id'] ?>"<?= $i === 0 ? '' : ' hidden' ?>>
    <?php foreach ($category['items'] as $item): $name = lang_field($item, 'name'); ?>
    <div class="item-card<?= $item['is_available'] ? '' : ' is-sold-out' ?>">
      <p class="item-card__name"><?= e($name) ?></p>
      <?php if (!$item['is_available']): ?><p class="item-card__sold"><?= e(t('menu.sold_out')) ?></p><?php endif; ?>
      <div class="item-card__variants">
        <?php foreach ($item['variants'] as $variant): ?>
        <button type="button" class="counter-btn variant-btn"
                data-variant="<?= (int) $variant['id'] ?>" data-name="<?= e($name) ?>"
                data-label="<?= e(lang_field($variant, 'label')) ?>" data-price="<?= (int) $variant['price_paise'] ?>"
                <?= $item['is_available'] ? '' : 'disabled' ?>>
          <span><?= e(lang_field($variant, 'label')) ?></span>
          <span class="variant-btn__price"><?= e(money($variant['price_paise'])) ?></span>
        </button>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endforeach; ?>
</section>

<aside class="counter-cart" data-cart>
  <h2 class="counter-cart__head"><?= icon('receipt', 22) ?><span><?= e(t('billing.current_bill')) ?></span>
    <button type="button" class="counter-cart__close" data-cart-close aria-label="<?= e(t('order.close')) ?>"><?= icon('close', 22) ?></button></h2>
  <div class="counter-cart__lines" data-cart-lines></div>
  <p class="counter-cart__empty muted" data-cart-empty><?= icon('receipt', 40) ?><span><?= e(t('billing.empty')) ?></span></p>
  <div class="counter-cart__footer">
    <div class="segmented" data-service>
      <button type="button" class="counter-btn is-selected" data-value="dine_in"><?= e(t('billing.dine_in')) ?></button>
      <button type="button" class="counter-btn" data-value="parcel"><?= e(t('billing.parcel')) ?></button>
    </div>
    <dl class="totals">
      <div><dt><?= e(t('billing.subtotal')) ?></dt><dd data-subtotal>₹0</dd></div>
      <div data-parcel-row hidden><dt><?= e(t('billing.parcel_charge')) ?></dt><dd data-parcel-amount></dd></div>
      <div data-discount-row hidden><dt><?= e(t('billing.discount')) ?> <span class="muted" data-discount-reason></span></dt><dd data-discount-amount></dd></div>
      <div data-roundoff-row hidden><dt><?= e(t('billing.round_off')) ?></dt><dd data-roundoff></dd></div>
      <div class="totals__grand"><dt><?= e(t('billing.total')) ?></dt><dd data-total>₹0</dd></div>
    </dl>
    <div class="cart-actions">
      <button type="button" class="counter-btn" data-discount><?= e(t('billing.discount')) ?></button>
      <button type="button" class="counter-btn" data-clear><?= e(t('billing.clear')) ?></button>
    </div>
    <div class="pay-buttons">
      <button type="button" class="counter-btn pay-btn" data-pay="cash" disabled><?= e(t('billing.pay_cash')) ?><span data-pay-total></span></button>
      <button type="button" class="counter-btn pay-btn" data-pay="upi" disabled><?= e(t('billing.pay_upi')) ?><span data-pay-total></span></button>
      <button type="button" class="counter-btn pay-btn pay-btn--small" data-pay="mixed" disabled><?= e(t('billing.pay_mixed')) ?></button>
    </div>
  </div>
</aside>
<?php /* Phones: the bill is a bottom bar that opens the bill above as a sheet (billing.js). */ ?>
<button type="button" class="bill-bar" data-cart-open hidden>
  <span class="bill-bar__icon" aria-hidden="true"><?= icon('receipt', 22) ?></span>
  <span class="bill-bar__summary"><span data-bill-count></span><strong data-bill-total></strong></span>
  <span class="bill-bar__cta"><?= e(t('counter.view_bill')) ?></span>
</button>
<div class="cart-scrim" data-cart-close hidden></div>
<?php
view('counter_frame_end', ['scripts' => ['js/billing.js']]);
