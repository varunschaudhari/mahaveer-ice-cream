<?php
/**
 * Counter app frame (after login): header with staff name + Lock, tabs. Close with
 * counter_frame_end. Vars: $user, $active (tab key), optional $withCart (billing layout).
 * @var array $user
 * @var string $active
 */

use App\Settings;

$withCart ??= false;
$otherLang = lang() === 'mr' ? 'en' : 'mr';
$tabs = [
    'billing' => ['/counter/billing.php', t('counter.tab.billing'), 'receipt'],
    'online' => ['/counter/online.php', t('counter.tab.online'), 'globe'],
    'tables' => ['/counter/tables.php', t('counter.tab.tables'), 'table'],
    'bulk' => ['/counter/bulk.php', t('counter.tab.bulk'), 'gift'],
    'stock' => ['/counter/stock.php', t('counter.tab.stock'), 'box'],
    'today' => ['/counter/today.php', t('counter.tab.today'), 'chart'],
];
// Texts used by counter.js (no inline scripts: CSP).
$i18n = [
    'offline' => t('counter.offline'),
    'error' => t('error.generic'),
    'ok' => t('common.ok'),
    'cancel' => t('common.cancel'),
    'other' => t('counter.other'),
    'owner_pin' => t('counter.owner_pin'),
    'new_orders' => t('online.new_orders'),
    'table_alerts' => t('tables.new_alerts'),
    'pay_title' => t('online.pay_title'),
    'pay_cash' => t('billing.pay_cash'),
    'pay_upi' => t('billing.pay_upi'),
    'pay_mixed' => t('billing.pay_mixed'),
    'cash_part' => t('billing.cash_part', ['total' => '{total}']),
    'mixed_invalid' => t('payment.error.mixed'),
    'received_amount' => t('billing.received_amount'),
];
?>
<div class="counter-app" data-i18n="<?= e(json_encode($i18n, JSON_UNESCAPED_UNICODE)) ?>">
  <header class="counter-header">
    <span class="counter-brand">
      <span class="counter-brand__mark" aria-hidden="true">
        <svg viewBox="0 0 40 48" width="20" height="24"><path d="M9 21h22L20 46z" fill="#FFB300"/><path d="M14 21l6 19M20 21v21M26 21l-6 19" stroke="#E09400" stroke-width="1.6" fill="none"/><ellipse cx="20" cy="17" rx="13" ry="10" fill="#F8BBD0"/><ellipse cx="20" cy="9" rx="8.5" ry="7" fill="#FFF8F0"/></svg>
      </span>
      <span class="counter-header__title"><?= e(lang_field(Settings::all(), 'shop_name')) ?></span>
    </span>
    <span class="counter-header__actions">
      <a class="counter-lang" href="<?= e(lang_switch_url($otherLang)) ?>"><?= e(t('lang.switch')) ?></a>
      <?php if (in_array($user['role'], App\Auth::ADMIN_ROLES, true)): ?>
      <a class="counter-lang" href="/admin/"><?= icon('settings', 18) ?><?= e(t('counter.open_admin')) ?></a>
      <?php endif; ?>
      <span class="counter-header__staff">
        <span class="counter-avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($user['name'], 0, 1))) ?></span>
        <?= e($user['name']) ?>
      </span>
      <form method="post" action="/counter/lock.php">
        <?= csrf_field() ?>
        <button type="submit" class="counter-btn counter-lock"><?= icon('lock', 18) ?><?= e(t('counter.lock')) ?></button>
      </form>
    </span>
  </header>
  <nav class="counter-tabs">
    <?php foreach ($tabs as $key => [$href, $label, $tabIcon]): ?>
    <a class="counter-tab<?= $key === $active ? ' is-active' : '' ?>" href="<?= e($href) ?>"<?= $key === $active ? ' aria-current="page"' : '' ?>>
      <?= icon($tabIcon, 22) ?>
      <span><?= e($label) ?></span>
      <?php if ($key === 'online'): ?><span class="badge" data-online-badge hidden></span><?php endif; ?>
      <?php if ($key === 'tables'): ?><span class="badge" data-tables-badge hidden></span><?php endif; ?>
    </a>
    <?php endforeach; ?>
  </nav>
  <button type="button" class="alert-bar" data-alert-bar hidden></button>
  <main class="counter-main<?= $withCart ? '' : ' no-cart' ?>">
