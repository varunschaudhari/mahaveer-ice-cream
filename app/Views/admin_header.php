<?php
/**
 * Admin page start. Vars: $title, optional $active (nav key), optional $user (logged-in admin).
 * Admin is used on the owner's phone and on the counter PC, so it must work in Chrome 109 too.
 * @var string $title
 */

use App\Settings;

$active ??= '';
$user ??= null;
$otherLang = lang() === 'mr' ? 'en' : 'mr';
$nav = [
    'menu' => ['/admin/menu.php', t('admin.nav.menu')],
    'settings' => ['/admin/settings.php', t('admin.nav.settings')],
    'staff' => ['/admin/staff.php', t('admin.nav.staff')],
    'audit' => ['/admin/audit.php', t('admin.nav.audit')],
    'bulk' => ['/admin/bulk.php', t('admin.nav.bulk')],
    'tables' => ['/admin/tables.php', t('admin.nav.tables')],
    'reports' => ['/admin/reports.php', t('admin.nav.reports')],
    'customers' => ['/admin/customers.php', t('admin.nav.customers')],
    'website' => ['/admin/website.php', t('admin.nav.website')],
    'qr' => ['/admin/qr.php', t('admin.nav.qr')],
];
?>
<!doctype html>
<html lang="<?= e(lang()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= e($title) ?> – <?= e(t('admin.title')) ?></title>
<link rel="stylesheet" href="<?= e(asset('css/base.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/admin.css')) ?>">
</head>
<body class="admin">
<header class="admin-bar">
  <div class="container admin-bar__inner">
    <a class="brand" href="/admin/"><?= e(lang_field(Settings::all(), 'shop_name')) ?> · <?= e(t('admin.title')) ?></a>
    <div class="cluster">
      <a class="lang-toggle" href="<?= e(lang_switch_url($otherLang)) ?>" hreflang="<?= e($otherLang) ?>"><?= e(t('lang.switch')) ?></a>
      <?php if ($user !== null): ?>
      <form method="post" action="/admin/logout.php">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-on-dark"><?= e(t('admin.logout')) ?></button>
      </form>
      <?php endif; ?>
    </div>
  </div>
  <?php if ($user !== null): ?>
  <nav class="container admin-nav" aria-label="<?= e(t('admin.title')) ?>">
    <?php foreach ($nav as $key => [$href, $label]): ?>
    <a href="<?= e($href) ?>"<?= $key === $active ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
  </nav>
  <?php endif; ?>
</header>
<main class="container page stack">
<?php foreach (flash_messages() as $message): ?>
  <p class="flash flash-<?= e($message['type']) ?>" role="status"><?= e($message['message']) ?></p>
<?php endforeach; ?>
