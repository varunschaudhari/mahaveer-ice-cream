<?php
/**
 * Admin page start. Vars: $title, optional $active (nav key), optional $user (logged-in admin).
 * Admin is used on the owner's phone and on the counter PC, so it must work in Chrome 109 too.
 * Logged in: a side menu on wide screens, a top bar with scrolling menu chips on phones.
 * @var string $title
 */

use App\Settings;

$active ??= '';
$user ??= null;
$otherLang = lang() === 'mr' ? 'en' : 'mr';
// key => [link, label, icon]; most used first.
$nav = [
    'home' => ['/admin/', t('admin.dashboard'), 'home'],
    'reports' => ['/admin/reports.php', t('admin.nav.reports'), 'chart'],
    'bulk' => ['/admin/bulk.php', t('admin.nav.bulk'), 'gift'],
    'menu' => ['/admin/menu.php', t('admin.nav.menu'), 'cone'],
    'tables' => ['/admin/tables.php', t('admin.nav.tables'), 'table'],
    'customers' => ['/admin/customers.php', t('admin.nav.customers'), 'user'],
    'website' => ['/admin/website.php', t('admin.nav.website'), 'globe'],
    'qr' => ['/admin/qr.php', t('admin.nav.qr'), 'qr'],
    'staff' => ['/admin/staff.php', t('admin.nav.staff'), 'users'],
    'settings' => ['/admin/settings.php', t('admin.nav.settings'), 'settings'],
    'audit' => ['/admin/audit.php', t('admin.nav.audit'), 'log'],
];
if (($user['role'] ?? '') === 'super') {
    $nav['health'] = ['/admin/health.php', t('admin.nav.health'), 'pulse']; // developer only
}
$shopName = lang_field(Settings::all(), 'shop_name');
?>
<!doctype html>
<html lang="<?= e(lang()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<meta name="theme-color" content="#FBF8F3">
<title><?= e($title) ?> – <?= e(t('admin.title')) ?></title>
<link rel="icon" href="/assets/img/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(asset('css/base.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/admin.css')) ?>">
</head>
<body class="admin<?= $user !== null ? ' has-nav' : '' ?>">
<header class="admin-bar">
  <a class="admin-brand" href="/admin/">
    <img src="/assets/img/logo-mark.svg" width="36" height="36" alt="">
    <span class="admin-brand__text"><span class="admin-brand__name"><?= e($shopName) ?></span><span class="admin-brand__tag"><?= e(t('admin.title')) ?></span></span>
  </a>
  <?php if ($user !== null): ?>
  <nav class="admin-nav" aria-label="<?= e(t('admin.title')) ?>">
    <?php foreach ($nav as $key => [$href, $label, $iconName]): ?>
    <a href="<?= e($href) ?>"<?= $key === $active ? ' aria-current="page"' : '' ?>><?= icon($iconName, 20) ?><span><?= e($label) ?></span></a>
    <?php endforeach; ?>
  </nav>
  <?php endif; ?>
  <div class="admin-bar__actions">
    <?php if ($user !== null): ?>
    <p class="admin-user">
      <span class="admin-user__avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr((string) $user['name'], 0, 1))) ?></span>
      <span class="admin-user__text"><strong><?= e($user['name']) ?></strong><small><?= e(role_label($user['role'])) ?></small></span>
    </p>
    <?php endif; ?>
    <a class="lang-toggle" href="<?= e(lang_switch_url($otherLang)) ?>" hreflang="<?= e($otherLang) ?>"><?= e(t('lang.switch')) ?></a>
    <?php if ($user !== null): ?>
    <form method="post" action="/admin/logout.php">
      <?= csrf_field() ?>
      <button type="submit" class="admin-logout"><?= icon('logout', 20) ?><span class="admin-logout__text"><?= e(t('admin.logout')) ?></span></button>
    </form>
    <?php endif; ?>
  </div>
</header>
<main class="container page stack">
<?php foreach (flash_messages() as $message): ?>
  <p class="flash flash-<?= e($message['type']) ?>" role="status"><?= e($message['message']) ?></p>
<?php endforeach; ?>
