<?php
/**
 * Admin page start. Vars: $title, optional $active (nav key), optional $user (logged-in admin).
 * Admin is used on the owner's phone and on the counter PC, so it must work in Chrome 109 too.
 * Logged in: the shared owner + admin menu (app_nav.php): a side menu on wide screens, a top bar
 * with ☰ that slides the menu in on phones. Logged out (login page): a plain top bar.
 * @var string $title
 */

use App\Settings;

$active ??= '';
$user ??= null;
$otherLang = lang() === 'mr' ? 'en' : 'mr';
?>
<!doctype html>
<html lang="<?= e(lang()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<meta name="theme-color" content="#FBF8F3">
<title><?= e($title) ?> – <?= e(t('admin.title')) ?></title>
<link rel="icon" href="/assets/img/favicon.png" type="image/png">
<link rel="stylesheet" href="<?= e(asset('css/base.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/admin.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/app-nav.css')) ?>">
</head>
<body class="admin<?= $user !== null ? ' has-nav' : '' ?>">
<?php if ($user !== null): ?>
<?php view('app_nav', ['user' => $user, 'active' => $active, 'tag' => t('admin.title')]); ?>
<?php else: ?>
<header class="admin-bar">
  <a class="admin-brand" href="/admin/">
    <img src="/assets/img/logo-mark.png" width="32" height="36" alt="">
    <span class="admin-brand__text"><span class="admin-brand__name"><?= e(lang_field(Settings::all(), 'shop_name')) ?></span><span class="admin-brand__tag"><?= e(t('admin.title')) ?></span></span>
  </a>
  <div class="admin-bar__actions">
    <a class="lang-toggle" href="<?= e(lang_switch_url($otherLang)) ?>" hreflang="<?= e($otherLang) ?>"><?= e(t('lang.switch')) ?></a>
  </div>
</header>
<?php endif; ?>
<main class="container page stack">
<?php foreach (flash_messages() as $message): ?>
  <p class="flash flash-<?= e($message['type']) ?>" role="status"><?= e($message['message']) ?></p>
<?php endforeach; ?>
