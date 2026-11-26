<?php

declare(strict_types=1);

// Admin login (username + password, owner / super-admin) and dashboard.

use App\Auth;
use App\Csrf;

require __DIR__ . '/../boot.php';

$user = Auth::user();
$error = null;
$username = '';

if (is_post() && !Auth::isAdmin($user)) {
    Csrf::check();
    $username = trim((string) ($_POST['username'] ?? ''));
    $result = Auth::loginWithPassword($username, (string) ($_POST['password'] ?? ''));
    if ($result['ok']) {
        redirect('/admin/');
    }
    $error = match ($result['error']) {
        'auth.locked' => t('auth.locked', ['time' => format_time(substr($result['locked_until'], 11, 5))]),
        'auth.too_many' => t('auth.too_many'),
        default => t('auth.wrong_password'),
    };
}

if (!Auth::isAdmin($user)) {
    view('admin_header', ['title' => t('admin.login_title')]);
    ?>
    <h1><?= e(t('admin.login_title')) ?></h1>
    <?php if ($user !== null): ?>
    <p class="notice"><?= e(t('admin.password_needed', ['name' => $user['name']])) ?></p>
    <?php endif; ?>
    <form method="post" action="/admin/" class="card stack login-form">
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
      <button type="submit" class="btn btn-primary btn-block"><?= e(t('admin.login')) ?></button>
    </form>
    <?php
    view('admin_footer');
    exit;
}

view('admin_header', ['title' => t('admin.dashboard'), 'user' => $user]);
?>
<h1><?= e(t('admin.welcome', ['name' => $user['name']])) ?></h1>
<p class="muted"><?= e(role_label($user['role'])) ?></p>
<nav class="tiles">
  <a class="tile" href="/admin/menu.php"><?= e(t('admin.nav.menu')) ?></a>
  <a class="tile" href="/admin/settings.php"><?= e(t('admin.nav.settings')) ?></a>
  <a class="tile" href="/admin/staff.php"><?= e(t('admin.nav.staff')) ?></a>
  <a class="tile" href="/admin/audit.php"><?= e(t('admin.nav.audit')) ?></a>
  <a class="tile" href="/counter/"><?= e(t('admin.nav.counter')) ?></a>
</nav>
<?php
view('admin_footer');
