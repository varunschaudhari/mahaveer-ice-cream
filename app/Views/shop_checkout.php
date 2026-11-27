<?php
/**
 * Customer cart + checkout (takeaway and table). Vars: $mode ('takeaway' | 'table'),
 * $slots (SlotService::today, takeaway only), $open (bool).
 * Phones: a full-screen sheet opened from the cart bar. Wide screens (≥ 1024 px): always shown
 * as the side panel next to the menu (shop.js decides; the markup is the same).
 * @var string $mode
 * @var bool $open
 */
$slots ??= null;
$isTable = $mode === 'table';
?>
<section class="checkout" id="checkout" data-checkout hidden aria-labelledby="checkout-title">
  <div class="checkout__head">
    <h2 id="checkout-title">
      <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path d="M7 18a2 2 0 1 0 0 4 2 2 0 0 0 0-4zm10 0a2 2 0 1 0 0 4 2 2 0 0 0 0-4zM5.2 4l.6 3H21l-2 8H7.4L4.3 2H1v2z" fill="currentColor"/></svg>
      <?= e(t('order.your_order')) ?>
    </h2>
    <button type="button" class="checkout__close" data-back aria-label="<?= e(t('order.close')) ?>">
      <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M6.4 5 5 6.4 10.6 12 5 17.6 6.4 19l5.6-5.6 5.6 5.6 1.4-1.4-5.6-5.6L19 6.4 17.6 5 12 10.6z" fill="currentColor"/></svg>
    </button>
  </div>

  <div class="checkout__empty" data-cart-empty>
    <span class="checkout__empty-icon" aria-hidden="true">🍨</span>
    <p class="checkout__empty-title"><?= e(t('order.cart_empty_title')) ?></p>
    <p class="muted text-sm"><?= e(t('order.cart_empty_hint')) ?></p>
  </div>

  <div class="checkout__body" data-cart-filled>
    <div class="checkout-lines" data-cart-lines></div>
    <dl class="totals">
      <div><dt><?= e(t('billing.subtotal')) ?></dt><dd data-subtotal></dd></div>
      <?php if (!$isTable): ?>
      <div><dt><?= e(t('billing.parcel_charge')) ?></dt><dd data-parcel-amount></dd></div>
      <?php endif; ?>
      <div class="totals__grand"><dt><?= e(t('billing.total')) ?></dt><dd data-total></dd></div>
    </dl>

    <form class="checkout-form" data-checkout-form novalidate>
      <?php if (!$isTable): $picked = false; ?>
      <fieldset class="field slot-field">
        <legend>
          <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="2"/><path d="M12 7v5l3 2" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
          <?= e(t('checkout.slot')) ?>
        </legend>
        <?php /* The earliest free time is selected already (client decision 2026-11-27); the customer can tap another. */ ?>
        <div class="slot-grid" data-slots>
          <?php foreach ($slots['slots'] ?? [] as $slot): $check = !$slot['full'] && !$picked; $picked = $picked || $check; ?>
          <label class="slot<?= $slot['full'] ? ' is-full' : '' ?>">
            <input type="radio" name="slot" value="<?= e($slot['start']) ?>"<?= $slot['full'] ? ' disabled' : '' ?><?= $check ? ' checked' : '' ?>>
            <span><?= e(format_time($slot['start'])) ?></span>
            <?php if ($slot['full']): ?><small><?= e(t('order.slot_full')) ?></small><?php endif; ?>
          </label>
          <?php endforeach; ?>
        </div>
        <p class="field-error" data-error-for="slot" hidden></p>
      </fieldset>
      <?php endif; ?>
      <?php if ($isTable): ?>
      <p class="checkout-known" data-identity-known hidden>
        <span data-identity-name></span> · <button type="button" class="link-btn" data-identity-change><?= e(t('table.change_name')) ?></button>
      </p>
      <?php endif; ?>
      <div class="checkout-form__pair" data-identity>
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
      </div>
      <div class="field">
        <label for="c-note"><?= e(t('checkout.note')) ?></label>
        <textarea class="textarea" id="c-note" name="note" maxlength="200" rows="2" placeholder="<?= e(t('checkout.note_hint')) ?>"></textarea>
        <p class="field-error" data-error-for="note" hidden></p>
      </div>
      <p class="info-note">
        <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="2"/><path d="M12 11v5M12 7.5v.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
        <span><?= e($isTable ? t('table.pay_before_leaving') : t('checkout.pay_at_counter')) ?></span>
      </p>
      <div class="checkout-submit">
        <p class="flash flash-error" data-error-for="_" role="alert" hidden></p>
        <button type="submit" class="btn btn-primary btn-block btn-lg btn-place" data-place<?= $open ? '' : ' disabled' ?>><span><?= e(t('checkout.place')) ?></span><span class="btn-place__total" data-place-total></span></button>
      </div>
      <button type="button" class="btn btn-ghost btn-block btn-more" data-back><?= e(t('checkout.add_more')) ?></button>
    </form>
  </div>
</section>
