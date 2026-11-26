<?php

declare(strict_types=1);

// Admin: mobile numbers blocked from online ordering after no-shows, with Unblock.
// (The full customers page with search and order history comes in M7.)

use App\Auth;
use App\Csrf;
use App\Services\CustomerService;

require __DIR__ . '/../boot.php';

$user = Auth::requireAdmin();
$customers = new CustomerService();

if (is_post()) {
    Csrf::check();
    $customers->unblock((int) ($_POST['id'] ?? 0), $user['id']);
    flash('success', t('blocked.unblocked'));
    redirect('/admin/blocked.php');
}

$blocked = $customers->blocked();

view('admin_header', ['title' => t('admin.nav.blocked'), 'active' => 'blocked', 'user' => $user]);
?>
<h1><?= e(t('admin.nav.blocked')) ?></h1>
<p class="muted"><?= e(t('blocked.help', ['n' => App\Settings::int('noshow_block_count', 2)])) ?></p>

<?php if ($blocked === []): ?>
<p class="card"><?= e(t('blocked.none')) ?></p>
<?php else: ?>
<ul class="admin-list card">
  <?php foreach ($blocked as $customer): ?>
  <li class="admin-row">
    <span class="admin-row__main">
      <strong><?= e($customer['name']) ?></strong>
      <a href="tel:+91<?= e($customer['mobile']) ?>"><?= e($customer['mobile']) ?></a><br>
      <span class="text-sm muted">
        <?= e(t('blocked.counts', ['orders' => $customer['order_count'], 'no_shows' => $customer['no_show_count']])) ?>
        <?php if ($customer['last_order_at']): ?> · <?= e(format_date_time($customer['last_order_at'])) ?><?php endif; ?>
      </span>
    </span>
    <form method="post" action="/admin/blocked.php" data-confirm="<?= e(t('blocked.confirm', ['name' => $customer['name']])) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int) $customer['id'] ?>">
      <button type="submit" class="btn btn-success"><?= e(t('blocked.unblock')) ?></button>
    </form>
  </li>
  <?php endforeach; ?>
</ul>
<?php endif; ?>
<?php
view('admin_footer');
