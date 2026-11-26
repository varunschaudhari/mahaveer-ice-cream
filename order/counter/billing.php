<?php

declare(strict_types=1);

// M1 placeholder: proves the counter login works. The billing screen replaces this in M2.

use App\Auth;
use App\Settings;

require __DIR__ . '/../boot.php';

$user = Auth::requireStaff();

view('counter_head', ['title' => t('counter.title')]);
?>
<header class="counter-header">
  <span class="counter-header__title"><?= e(lang_field(Settings::all(), 'shop_name')) ?></span>
  <span class="cluster">
    <span class="counter-header__staff"><?= e(t('counter.logged_in_as', ['name' => $user['name']])) ?></span>
    <form method="post" action="/counter/lock.php">
      <?= csrf_field() ?>
      <button type="submit" class="counter-btn"><?= e(t('counter.lock')) ?></button>
    </form>
  </span>
</header>
<main class="counter-panel stack">
  <p class="notice"><?= e(t('counter.billing_coming')) ?></p>
  <?php if (in_array($user['role'], Auth::ADMIN_ROLES, true)): ?>
  <p><a class="btn btn-secondary btn-lg" href="/admin/"><?= e(t('counter.open_admin')) ?></a></p>
  <?php endif; ?>
</main>
<?php
view('counter_foot');
