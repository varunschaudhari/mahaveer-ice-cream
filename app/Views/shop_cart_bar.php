<?php
/**
 * Floating cart bar on phones (takeaway and table pages): count + total, opens the checkout
 * sheet. Hidden while the cart is empty, while the sheet is open and on wide screens (shop.js).
 */
?>
<div class="cart-bar" data-cart-bar hidden>
  <button type="button" class="cart-bar__button" data-open-cart>
    <span class="cart-bar__icon" aria-hidden="true">
      <svg viewBox="0 0 24 24" width="22" height="22"><path d="M7 18a2 2 0 1 0 0 4 2 2 0 0 0 0-4zm10 0a2 2 0 1 0 0 4 2 2 0 0 0 0-4zM5.2 4l.6 3H21l-2 8H7.4L4.3 2H1v2z" fill="currentColor"/></svg>
    </span>
    <span class="cart-bar__summary">
      <span class="cart-bar__count" data-cart-count></span>
      <strong class="cart-bar__total" data-cart-total></strong>
    </span>
    <span class="cart-bar__cta"><?= e(t('order.view_cart')) ?>
      <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M9.3 5.3 7.9 6.7l5.3 5.3-5.3 5.3 1.4 1.4 6.7-6.7z" fill="currentColor"/></svg>
    </span>
  </button>
</div>
