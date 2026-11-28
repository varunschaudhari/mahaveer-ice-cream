<?php
/**
 * Counter app frame (after login): header with staff name + Lock, tabs. Close with
 * counter_frame_end. Vars: $user, $active (tab key), optional $withCart (billing layout).
 * @var array $user
 * @var string $active
 */

use App\Settings;

$withCart ??= false;
$isAdmin = in_array($user['role'], App\Auth::ADMIN_ROLES, true);
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
    'print_sent' => t('counter.print_sent'),
];
?>
<?php if ($isAdmin): ?>
<?php /* Owner / admin on a phone: the same menu as on the admin pages, as a drawer (app-nav.css). */ ?>
<div class="has-nav nav-drawer"><?php view('app_nav', ['user' => $user, 'active' => 'counter', 'tag' => t('counter.title'), 'topbar' => false]); ?></div>
<?php endif; ?>
<div class="counter-app" data-i18n="<?= e(json_encode($i18n, JSON_UNESCAPED_UNICODE)) ?>">
  <header class="counter-header">
    <?php if ($isAdmin): ?>
    <button type="button" class="counter-menu-btn" data-nav-open aria-controls="app-nav" aria-expanded="false" aria-label="<?= e(t('nav.open_menu')) ?>"><?= icon('menu', 24) ?></button>
    <?php endif; ?>
    <span class="counter-brand">
      <span class="counter-brand__mark" aria-hidden="true"><img src="/assets/img/logo-mark.png" width="23" height="26" alt=""></span>
      <span class="counter-header__title"><?= e(lang_field(Settings::all(), 'shop_name')) ?></span>
    </span>
    <span class="counter-header__actions">
      <a class="counter-lang" href="<?= e(lang_switch_url($otherLang)) ?>"><?= e(t('lang.switch')) ?></a>
      <?php if ($isAdmin): ?>
      <a class="counter-lang" href="/admin/" aria-label="<?= e(t('counter.open_admin')) ?>"><?= icon('settings', 18) ?><span class="counter-lang__text"><?= e(t('counter.open_admin')) ?></span><span class="counter-lang__short"><?= e(t('owner.admin_link')) ?></span></a>
      <?php endif; ?>
      <span class="counter-header__staff">
        <span class="counter-avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($user['name'], 0, 1))) ?></span>
        <span class="counter-header__name"><?= e($user['name']) ?></span>
      </span>
      <?php /* Lock ends the login: on an owner's phone it is left out (Log out is in the ☰ menu). */ ?>
      <form method="post" action="/counter/lock.php" class="counter-lock-form<?= $isAdmin ? ' is-admin' : '' ?>">
        <?= csrf_field() ?>
        <button type="submit" class="counter-btn counter-lock" aria-label="<?= e(t('counter.lock')) ?>"><?= icon('lock', 18) ?><span class="counter-lock__text"><?= e(t('counter.lock')) ?></span></button>
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
