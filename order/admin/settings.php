<?php

declare(strict_types=1);

// Admin: shop details, hours and business rules (SPEC §14). Every change is audit logged.

use App\Auth;
use App\Csrf;
use App\Services\SettingsService;
use App\Settings;
use App\ValidationException;

require __DIR__ . '/../boot.php';

$user = Auth::requireAdmin();
$service = new SettingsService();
$errors = [];
$values = Settings::all();

if (is_post()) {
    Csrf::check();
    try {
        $changed = $service->update($_POST, $user['id']);
        flash('success', $changed === [] ? t('settings.no_changes') : t('settings.saved', ['n' => count($changed)]));
        redirect('/admin/settings.php');
    } catch (ValidationException $e) {
        $errors = $e->errors;
        foreach (array_keys(SettingsService::FIELDS) as $key) {
            $values[$key] = (string) ($_POST[$key] ?? '');
        }
    }
}

// Display order and labels. Input types: text, time, int, money, phone, url.
$groups = [
    [t('settings.group.shop'), [
        'shop_name_mr' => [t('settings.shop_name_mr'), 'text', null],
        'shop_name_en' => [t('settings.shop_name_en'), 'text', null],
        'shop_address_mr' => [t('settings.shop_address_mr'), 'text', null],
        'shop_address_en' => [t('settings.shop_address_en'), 'text', null],
        'shop_phone' => [t('settings.shop_phone'), 'phone', null],
    ]],
    [t('settings.group.hours'), [
        'open_time' => [t('settings.open_time'), 'time', null],
        'close_time' => [t('settings.close_time'), 'time', null],
        'last_order_mins' => [t('settings.last_order_mins'), 'int', t('settings.help.minutes_before_close')],
    ]],
    [t('settings.group.takeaway'), [
        'parcel_charge' => [t('settings.parcel_charge'), 'money', t('settings.help.parcel_charge')],
        'slot_mins' => [t('settings.slot_mins'), 'int', t('settings.help.minutes')],
        'slot_capacity' => [t('settings.slot_capacity'), 'int', t('settings.help.slot_capacity')],
        'min_lead_mins' => [t('settings.min_lead_mins'), 'int', t('settings.help.minutes')],
        'noshow_after_mins' => [t('settings.noshow_after_mins'), 'int', t('settings.help.noshow_after_mins')],
        'noshow_block_count' => [t('settings.noshow_block_count'), 'int', t('settings.help.noshow_block_count')],
    ]],
    [t('settings.group.bulk_tables'), [
        'bulk_notice_days' => [t('settings.bulk_notice_days'), 'int', t('settings.help.days')],
        'table_unsettled_mins' => [t('settings.table_unsettled_mins'), 'int', t('settings.help.table_unsettled_mins')],
    ]],
    [t('settings.group.bill_website'), [
        'bill_footer_mr' => [t('settings.bill_footer_mr'), 'text', null],
        'bill_footer_en' => [t('settings.bill_footer_en'), 'text', null],
        'whatsapp_number' => [t('settings.whatsapp_number'), 'phone', t('settings.help.whatsapp_number')],
        'google_maps_url' => [t('settings.google_maps_url'), 'url', t('settings.help.google_maps_url')],
    ]],
];

view('admin_header', ['title' => t('admin.nav.settings'), 'active' => 'settings', 'user' => $user]);
?>
<h1><?= e(t('admin.nav.settings')) ?></h1>
<?php if ($errors !== []): ?><p class="flash flash-error" role="alert"><?= e(t('settings.fix_errors')) ?></p><?php endif; ?>

<form method="post" action="/admin/settings.php" class="stack">
  <?= csrf_field() ?>
  <?php foreach ($groups as [$groupTitle, $fields]): ?>
  <fieldset class="card stack">
    <legend><?= e($groupTitle) ?></legend>
    <div class="form-grid">
      <?php foreach ($fields as $key => [$label, $type, $help]):
          $rule = SettingsService::FIELDS[$key];
          $value = $values[$key] ?? '';
      ?>
      <div class="field<?= isset($errors[$key]) ? ' has-error' : '' ?>">
        <label for="s-<?= e($key) ?>"><?= e($label) ?></label>
        <?php if ($type === 'time'): ?>
        <input class="input" id="s-<?= e($key) ?>" name="<?= e($key) ?>" type="time" value="<?= e($value) ?>" required>
        <?php elseif ($type === 'int'): ?>
        <input class="input" id="s-<?= e($key) ?>" name="<?= e($key) ?>" type="number" inputmode="numeric"
               min="<?= (int) $rule['min'] ?>" max="<?= (int) $rule['max'] ?>" value="<?= e($value) ?>" required>
        <?php elseif ($type === 'money'): ?>
        <input class="input" id="s-<?= e($key) ?>" name="<?= e($key) ?>" inputmode="decimal" value="<?= e($value) ?>" required>
        <?php elseif ($type === 'phone'): ?>
        <input class="input" id="s-<?= e($key) ?>" name="<?= e($key) ?>" type="tel" value="<?= e($value) ?>">
        <?php elseif ($type === 'url'): ?>
        <input class="input" id="s-<?= e($key) ?>" name="<?= e($key) ?>" type="url" value="<?= e($value) ?>" placeholder="https://">
        <?php else: ?>
        <input class="input" id="s-<?= e($key) ?>" name="<?= e($key) ?>" value="<?= e($value) ?>" maxlength="<?= (int) $rule['max'] ?>">
        <?php endif; ?>
        <?php if ($help !== null): ?><p class="text-sm muted"><?= e($help) ?></p><?php endif; ?>
        <?= field_error($errors, $key) ?>
      </div>
      <?php endforeach; ?>
    </div>
  </fieldset>
  <?php endforeach; ?>
  <div><button type="submit" class="btn btn-primary btn-lg"><?= e(t('common.save')) ?></button></div>
</form>
<?php
view('admin_footer');
