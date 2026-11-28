<?php
/**
 * One menu for the owner's phone page and the admin pages (logged-in admin only).
 * Wide screens (≥ 1024 px): a fixed side menu. Phones and tablets: a slim top bar whose ☰ button
 * slides the same menu in from the left (app-nav.js, styles in app-nav.css).
 * Vars: $user (logged-in admin), $active (nav key), $tag (small text under the shop name).
 * @var array $user
 */

use App\Settings;

$active ??= '';
$tag ??= t('admin.title');
$otherLang = lang() === 'mr' ? 'en' : 'mr';
// key => [link, label, icon]; the owner's live orders first (the app opens there), then admin.
$nav = [
    'owner' => ['/owner/', t('owner.orders'), 'bag'],
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
$brand = static function () use ($shopName, $tag): void { ?>
    <a class="admin-brand" href="/admin/">
      <img src="/assets/img/logo-mark.png" width="32" height="36" alt="">
      <span class="admin-brand__text"><span class="admin-brand__name"><?= e($shopName) ?></span><span class="admin-brand__tag"><?= e($tag) ?></span></span>
    </a>
<?php };
?>
<div class="app-topbar">
  <button type="button" class="app-topbar__menu" data-nav-open aria-controls="app-nav" aria-expanded="false" aria-label="<?= e(t('nav.open_menu')) ?>"><?= icon('menu', 24) ?></button>
  <?php $brand(); ?>
</div>
<header class="admin-bar" id="app-nav" data-nav>
  <div class="admin-bar__head">
    <?php $brand(); ?>
    <button type="button" class="admin-bar__close" data-nav-close aria-label="<?= e(t('nav.close_menu')) ?>"><?= icon('close', 22) ?></button>
  </div>
  <nav class="admin-nav" aria-label="<?= e(t('admin.title')) ?>">
    <?php foreach ($nav as $key => [$href, $label, $iconName]): ?>
    <a href="<?= e($href) ?>"<?= $key === $active ? ' aria-current="page"' : '' ?>><?= icon($iconName, 20) ?><span><?= e($label) ?></span></a>
    <?php endforeach; ?>
  </nav>
  <div class="admin-bar__actions">
    <p class="admin-user">
      <span class="admin-user__avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr((string) $user['name'], 0, 1))) ?></span>
      <span class="admin-user__text"><strong><?= e($user['name']) ?></strong><small><?= e(role_label($user['role'])) ?></small></span>
    </p>
    <a class="lang-toggle" href="<?= e(lang_switch_url($otherLang)) ?>" hreflang="<?= e($otherLang) ?>"><?= e(t('lang.switch')) ?></a>
    <form method="post" action="/admin/logout.php">
      <?= csrf_field() ?>
      <button type="submit" class="admin-logout"><?= icon('logout', 20) ?><span><?= e(t('admin.logout')) ?></span></button>
    </form>
  </div>
</header>
<div class="app-scrim" data-nav-close data-nav-scrim hidden></div>
