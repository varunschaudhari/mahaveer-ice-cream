<?php

declare(strict_types=1);

// Bulk / party order request (SPEC §8): items with decimal quantities (kg, packs), event date at
// least bulk_notice_days ahead, pickup time, contact, note. The owner approves and sets an advance.

use App\Services\BulkService;
use App\Services\MenuService;
use App\Settings;

require __DIR__ . '/boot.php';

$menu = (new MenuService())->publicMenu();
$today = now()->setTime(0, 0);
$notice = max(0, Settings::int('bulk_notice_days', 1));
$open = Settings::get('open_time', '10:00');
$close = Settings::get('close_time', '22:30');

$texts = [
    'error' => t('error.generic'),
    'offline' => t('order.offline'),
    'choose' => t('bulk.error.choose_item'),
    'qty' => t('cart.error.bulk_qty'),
    'no_lines' => t('cart.error.empty'),
    'remove' => t('billing.remove'),
];

view('header', ['title' => t('bulk.heading'), 'css' => ['css/base.css', 'css/shop.css']]);
?>
<div class="bulk-page stack" data-bulk data-texts="<?= e(json_encode($texts, JSON_UNESCAPED_UNICODE)) ?>">
  <h1><?= e(t('bulk.heading')) ?></h1>
  <p class="muted"><?= e(t('bulk.intro', ['days' => $notice])) ?></p>

  <form class="card stack" data-bulk-form novalidate>
    <h2><?= e(t('bulk.items')) ?></h2>
    <div class="bulk-add">
      <select class="select" data-bulk-variant aria-label="<?= e(t('bulk.choose_item')) ?>">
        <option value=""><?= e(t('bulk.choose_item')) ?></option>
        <?php foreach ($menu as $category): ?>
        <optgroup label="<?= e(lang_field($category, 'name')) ?>">
          <?php foreach ($category['items'] as $item): ?>
          <?php foreach ($item['variants'] as $variant): $label = lang_field($item, 'name') . ' (' . lang_field($variant, 'label') . ')'; ?>
          <option value="<?= (int) $variant['id'] ?>" data-name="<?= e($label) ?>" data-price="<?= (int) $variant['price_paise'] ?>">
            <?= e($label . ' – ' . money($variant['price_paise'])) ?>
          </option>
          <?php endforeach; ?>
          <?php endforeach; ?>
        </optgroup>
        <?php endforeach; ?>
      </select>
      <input class="input bulk-qty" data-bulk-qty inputmode="decimal" value="1" aria-label="<?= e(t('bulk.qty')) ?>">
      <button type="button" class="btn btn-secondary" data-bulk-add><?= e(t('bulk.add')) ?></button>
    </div>
    <p class="text-sm muted"><?= e(t('bulk.qty_help')) ?></p>
    <ul class="bulk-lines" data-bulk-lines></ul>
    <p class="spread bulk-estimate"><span><?= e(t('bulk.estimate')) ?></span><strong data-bulk-total>₹0</strong></p>
    <p class="field-error" data-error-for="lines" hidden></p>

    <div class="form-grid">
      <div class="field">
        <label for="b-date"><?= e(t('bulk.event_date')) ?></label>
        <input class="input" id="b-date" name="event_date" type="date" required
               min="<?= e($today->modify("+{$notice} days")->format('Y-m-d')) ?>"
               max="<?= e($today->modify('+' . BulkService::MAX_DAYS_AHEAD . ' days')->format('Y-m-d')) ?>">
        <p class="field-error" data-error-for="event_date" hidden></p>
      </div>
      <div class="field">
        <label for="b-time"><?= e(t('bulk.pickup_time')) ?></label>
        <input class="input" id="b-time" name="pickup_time" type="time" required min="<?= e($open) ?>" max="<?= e($close) ?>" step="900">
        <p class="text-sm muted"><?= e(t('bulk.time_help', ['open' => format_time($open), 'close' => format_time($close)])) ?></p>
        <p class="field-error" data-error-for="pickup_time" hidden></p>
      </div>
      <div class="field">
        <label for="b-name"><?= e(t('checkout.name')) ?></label>
        <input class="input" id="b-name" name="name" maxlength="60" autocomplete="name" required>
        <p class="field-error" data-error-for="name" hidden></p>
      </div>
      <div class="field">
        <label for="b-mobile"><?= e(t('checkout.mobile')) ?></label>
        <input class="input" id="b-mobile" name="mobile" type="tel" inputmode="numeric" maxlength="14" autocomplete="tel" required>
        <p class="field-error" data-error-for="mobile" hidden></p>
      </div>
    </div>
    <div class="field">
      <label for="b-note"><?= e(t('checkout.note')) ?></label>
      <textarea class="textarea" id="b-note" name="note" maxlength="<?= BulkService::MAX_NOTE ?>" placeholder="<?= e(t('bulk.note_hint')) ?>"></textarea>
      <p class="field-error" data-error-for="note" hidden></p>
    </div>
    <p class="notice"><?= e(t('bulk.how_it_works')) ?></p>
    <p class="flash flash-error" data-error-for="_" role="alert" hidden></p>
    <button type="submit" class="btn btn-primary btn-block btn-lg" data-bulk-send><?= e(t('bulk.send')) ?></button>
  </form>
</div>
<script src="<?= e(asset('js/bulk_form.js')) ?>"></script>
<?php
view('footer');
