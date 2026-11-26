<?php
/** Closes counter_frame_start and adds the shared pieces counter.js drives. Var: $scripts. */
$scripts ??= [];
?>
  </main>
</div>

<p class="offline-banner" data-offline-banner role="alert" hidden><?= e(t('counter.offline')) ?></p>
<p class="sound-hint" data-sound-hint hidden><?= e(t('counter.tap_for_sound')) ?></p>
<p class="toast" data-toast role="status" hidden></p>

<dialog class="counter-dialog" data-numpad>
  <h2 data-numpad-title></h2>
  <p class="numpad-display" data-numpad-display></p>
  <p class="field-error" data-numpad-error hidden></p>
  <div class="numpad">
    <?php foreach (['1', '2', '3', '4', '5', '6', '7', '8', '9'] as $digit): ?>
    <button type="button" class="counter-btn" data-key="<?= $digit ?>"><?= $digit ?></button>
    <?php endforeach; ?>
    <button type="button" class="counter-btn" data-key="clear" aria-label="<?= e(t('counter.clear')) ?>">C</button>
    <button type="button" class="counter-btn" data-key="0">0</button>
    <button type="button" class="counter-btn" data-key="back" aria-label="<?= e(t('counter.backspace')) ?>">⌫</button>
  </div>
  <div class="dialog-actions">
    <button type="button" class="counter-btn" data-numpad-cancel><?= e(t('common.cancel')) ?></button>
    <button type="button" class="counter-btn btn-go" data-numpad-ok><?= e(t('common.ok')) ?></button>
  </div>
</dialog>

<dialog class="counter-dialog" data-choose>
  <h2 data-choose-title></h2>
  <div class="choose-options" data-choose-options></div>
  <div class="field" data-choose-other hidden>
    <input class="input" data-choose-input maxlength="100" autocomplete="off">
  </div>
  <div class="dialog-actions">
    <button type="button" class="counter-btn" data-choose-cancel><?= e(t('common.cancel')) ?></button>
    <button type="button" class="counter-btn btn-go" data-choose-ok hidden><?= e(t('common.ok')) ?></button>
  </div>
</dialog>

<dialog class="counter-dialog" data-cash-dialog>
  <h2><?= e(t('billing.cash_title')) ?> <span data-cash-total></span></h2>
  <div class="tender-grid" data-tender-options></div>
  <p class="change-line">
    <span><?= e(t('billing.received')) ?> <strong data-cash-received>—</strong></span>
    <span><?= e(t('billing.change')) ?> <strong class="change" data-cash-change>—</strong></span>
  </p>
  <div class="dialog-actions">
    <button type="button" class="counter-btn" data-cash-cancel><?= e(t('common.cancel')) ?></button>
    <button type="button" class="counter-btn btn-go" data-cash-save><?= e(t('billing.save_print')) ?></button>
  </div>
</dialog>

<iframe class="print-frame" data-print-frame title="print" tabindex="-1" aria-hidden="true"></iframe>
<?php view('counter_foot', ['scripts' => array_merge(['js/counter.js'], $scripts)]); ?>
