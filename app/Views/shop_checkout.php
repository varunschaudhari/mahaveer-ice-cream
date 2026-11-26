<?php
/**
 * Customer cart + checkout (takeaway and table). Vars: $mode ('takeaway' | 'table'),
 * $slots (SlotService::today, takeaway only), $open (bool).
 * @var string $mode
 * @var bool $open
 */
$slots ??= null;
$isTable = $mode === 'table';
?>
<section class="card stack checkout" id="checkout" data-checkout hidden>
  <h2><?= e(t('order.your_order')) ?></h2>
  <div class="checkout-lines" data-cart-lines></div>
  <dl class="totals">
    <div><dt><?= e(t('billing.subtotal')) ?></dt><dd data-subtotal></dd></div>
    <?php if (!$isTable): ?>
    <div><dt><?= e(t('billing.parcel_charge')) ?></dt><dd data-parcel-amount></dd></div>
    <?php endif; ?>
    <div class="totals__grand"><dt><?= e(t('billing.total')) ?></dt><dd data-total></dd></div>
  </dl>

  <form class="stack" data-checkout-form novalidate>
    <?php if ($isTable): ?>
    <p class="muted" data-identity-known hidden>
      <span data-identity-name></span> · <button type="button" class="link-btn" data-identity-change><?= e(t('table.change_name')) ?></button>
    </p>
    <?php endif; ?>
    <div class="stack" data-identity>
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
    <?php if (!$isTable): ?>
    <fieldset class="field slot-field">
      <legend><?= e(t('checkout.slot')) ?></legend>
      <div class="slot-grid" data-slots>
        <?php foreach ($slots['slots'] ?? [] as $slot): ?>
        <label class="slot<?= $slot['full'] ? ' is-full' : '' ?>">
          <input type="radio" name="slot" value="<?= e($slot['start']) ?>"<?= $slot['full'] ? ' disabled' : '' ?>>
          <span><?= e(format_time($slot['start'])) ?></span>
          <?php if ($slot['full']): ?><small><?= e(t('order.slot_full')) ?></small><?php endif; ?>
        </label>
        <?php endforeach; ?>
      </div>
      <p class="field-error" data-error-for="slot" hidden></p>
    </fieldset>
    <?php endif; ?>
    <div class="field">
      <label for="c-note"><?= e(t('checkout.note')) ?></label>
      <textarea class="textarea" id="c-note" name="note" maxlength="200" placeholder="<?= e(t('checkout.note_hint')) ?>"></textarea>
      <p class="field-error" data-error-for="note" hidden></p>
    </div>
    <p class="notice"><?= e($isTable ? t('table.pay_before_leaving') : t('checkout.pay_at_counter')) ?></p>
    <p class="flash flash-error" data-error-for="_" role="alert" hidden></p>
    <button type="submit" class="btn btn-primary btn-block btn-lg" data-place<?= $open ? '' : ' disabled' ?>><?= e(t('checkout.place')) ?></button>
    <button type="button" class="btn btn-secondary btn-block" data-back><?= e(t('checkout.add_more')) ?></button>
  </form>
</section>
