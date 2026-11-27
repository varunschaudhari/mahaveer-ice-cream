<?php

declare(strict_types=1);

// Admin login (username + password, owner / super-admin) and dashboard.
// ?next=/owner/ returns to the owner's phone page after login (the only allowed target).

use App\Auth;
use App\Csrf;
use App\Services\BulkService;

require __DIR__ . '/../boot.php';

$user = Auth::user();
$error = null;
$username = '';
$next = ($_GET['next'] ?? '') === '/owner/' ? '/owner/' : '/admin/';

if (is_post() && !Auth::isAdmin($user)) {
    Csrf::check();
    $username = trim((string) ($_POST['username'] ?? ''));
    $result = Auth::loginWithPassword($username, (string) ($_POST['password'] ?? ''));
    if ($result['ok']) {
        redirect($next);
    }
    $error = match ($result['error']) {
        'auth.locked' => t('auth.locked', ['time' => format_time(substr($result['locked_until'], 11, 5))]),
        'auth.too_many' => t('auth.too_many'),
        default => t('auth.wrong_password'),
    };
}

if (Auth::isAdmin($user) && $next === '/owner/') {
    redirect('/owner/');
}

if (!Auth::isAdmin($user)) {
    view('admin_header', ['title' => t('admin.login_title')]);
    ?>
    <div class="login-wrap">
      <div class="login-head">
        <img src="/assets/img/logo.png" width="195" height="150" alt="">
        <h1><?= e(t('admin.login_title')) ?></h1>
      </div>
      <?php if ($user !== null): ?>
      <p class="notice"><?= e(t('admin.password_needed', ['name' => $user['name']])) ?></p>
      <?php endif; ?>
      <form method="post" action="<?= e($next === '/owner/' ? '/admin/?next=%2Fowner%2F' : '/admin/') ?>" class="card stack login-form">
        <?= csrf_field() ?>
        <?php if ($error !== null): ?>
        <p class="flash flash-error" role="alert"><?= e($error) ?></p>
        <?php endif; ?>
        <div class="field">
          <label for="username"><?= e(t('admin.username')) ?></label>
          <input class="input" id="username" name="username" value="<?= e($username) ?>"
                 autocomplete="username" autocapitalize="none" spellcheck="false" required>
        </div>
        <div class="field">
          <label for="password"><?= e(t('admin.password')) ?></label>
          <input class="input" id="password" name="password" type="password" autocomplete="current-password" required>
        </div>
        <button type="submit" class="btn btn-primary btn-block btn-lg"><?= e(t('admin.login')) ?></button>
      </form>
    </div>
    <?php
    view('admin_footer');
    exit;
}

$pendingBulk = count(array_filter((new BulkService())->open(), static fn (array $o): bool => $o['status'] === 'pending_approval'));
// [link, label key, description key, icon]; the first two are the everyday screens.
$tiles = [
    ['/owner/', 'admin.nav.owner', 'admin.tile.owner', 'phone'],
    ['/counter/', 'admin.nav.counter', 'admin.tile.counter', 'receipt'],
    ['/admin/reports.php', 'admin.nav.reports', 'admin.tile.reports', 'chart'],
    ['/admin/bulk.php', 'admin.nav.bulk', 'admin.tile.bulk', 'gift'],
    ['/admin/menu.php', 'admin.nav.menu', 'admin.tile.menu', 'cone'],
    ['/admin/tables.php', 'admin.nav.tables', 'admin.tile.tables', 'table'],
    ['/admin/customers.php', 'admin.nav.customers', 'admin.tile.customers', 'user'],
    ['/admin/website.php', 'admin.nav.website', 'admin.tile.website', 'globe'],
    ['/admin/qr.php', 'admin.nav.qr', 'admin.tile.qr', 'qr'],
    ['/admin/staff.php', 'admin.nav.staff', 'admin.tile.staff', 'users'],
    ['/admin/settings.php', 'admin.nav.settings', 'admin.tile.settings', 'settings'],
    ['/admin/audit.php', 'admin.nav.audit', 'admin.tile.audit', 'log'],
];

view('admin_header', ['title' => t('admin.dashboard'), 'active' => 'home', 'user' => $user]);
?>
<div class="admin-hello">
  <p class="eyebrow"><?= e(role_label($user['role'])) ?> · <?= e(now()->format('d/m/Y')) ?></p>
  <h1><?= e(t('admin.welcome', ['name' => $user['name']])) ?></h1>
</div>
<nav class="dash" aria-label="<?= e(t('admin.dashboard')) ?>">
  <?php foreach ([array_slice($tiles, 0, 2), array_slice($tiles, 2)] as $group => $groupTiles): ?>
  <div class="tiles<?= $group === 0 ? ' tiles--feature' : '' ?>">
    <?php foreach ($groupTiles as $i => [$href, $labelKey, $descKey, $iconName]): ?>
    <a class="tile tone-<?= $i % 6 ?>" href="<?= e($href) ?>">
      <span class="tile__icon"><?= icon($iconName, 24) ?></span>
      <span class="tile__text">
        <strong><?= e(t($labelKey)) ?><?php if ($href === '/admin/bulk.php' && $pendingBulk > 0): ?> <span class="badge badge-danger"><?= $pendingBulk ?></span><?php endif; ?></strong>
        <span><?= e(t($descKey)) ?></span>
      </span>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endforeach; ?>
</nav>
<?php
view('admin_footer');
