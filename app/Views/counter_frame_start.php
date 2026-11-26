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
    'billing' => ['/counter/billing.php', t('counter.tab.billing')],
    'online' => ['/counter/online.php', t('counter.tab.online')],
    'tables' => ['/counter/tables.php', t('counter.tab.tables')],
    'stock' => ['/counter/stock.php', t('counter.tab.stock')],
    'today' => ['/counter/today.php', t('counter.tab.today')],
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
    <span class="counter-header__title"><?= e(lang_field(Settings::all(), 'shop_name')) ?></span>
    <span class="cluster">
      <a class="counter-lang" href="<?= e(lang_switch_url($otherLang)) ?>"><?= e(t('lang.switch')) ?></a>
      <?php if (in_array($user['role'], App\Auth::ADMIN_ROLES, true)): ?>
      <a class="counter-lang" href="/admin/"><?= e(t('counter.open_admin')) ?></a>
      <?php endif; ?>
      <span class="counter-header__staff"><?= e($user['name']) ?></span>
      <form method="post" action="/counter/lock.php">
        <?= csrf_field() ?>
        <button type="submit" class="counter-btn"><?= e(t('counter.lock')) ?></button>
      </form>
    </span>
  </header>
  <nav class="counter-tabs">
    <?php foreach ($tabs as $key => [$href, $label]): ?>
    <a class="counter-tab<?= $key === $active ? ' is-active' : '' ?>" href="<?= e($href) ?>"<?= $key === $active ? ' aria-current="page"' : '' ?>>
      <?= e($label) ?>
      <?php if ($key === 'online'): ?><span class="badge" data-online-badge hidden></span><?php endif; ?>
      <?php if ($key === 'tables'): ?><span class="badge" data-tables-badge hidden></span><?php endif; ?>
    </a>
    <?php endforeach; ?>
  </nav>
  <button type="button" class="alert-bar" data-alert-bar hidden></button>
  <main class="counter-main<?= $withCart ? '' : ' no-cart' ?>">
