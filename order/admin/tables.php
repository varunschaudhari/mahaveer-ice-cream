<?php

declare(strict_types=1);

// Admin: dining tables T1–T7 (SPEC §12) — active on/off, regenerate the QR code (the old
// sticker stops working at once), print the A4 sticker sheet.

use App\Auth;
use App\Csrf;
use App\Services\TableService;

require __DIR__ . '/../boot.php';

$user = Auth::requireAdmin();
$tables = new TableService();

if (is_post()) {
    Csrf::check();
    $id = (int) ($_POST['id'] ?? 0);
    switch ((string) ($_POST['action'] ?? '')) {
        case 'regenerate':
            $tables->regenerateToken($id, $user['id']);
            flash('success', t('tables.admin.regenerated'));
            break;
        case 'activate':
        case 'deactivate':
            $tables->setActive($id, $_POST['action'] === 'activate', $user['id']);
            flash('success', t('tables.admin.saved'));
            break;
    }
    redirect('/admin/tables.php');
}

$base = rtrim((string) config('APP_URL_ORDER', ''), '/');

view('admin_header', ['title' => t('admin.nav.tables'), 'active' => 'tables', 'user' => $user]);
?>
<div class="section-head">
  <h1><?= e(t('admin.nav.tables')) ?></h1>
  <a class="btn btn-primary" href="/admin/table_stickers.php"><?= e(t('tables.admin.print_stickers')) ?></a>
</div>
<p class="muted"><?= e(t('tables.admin.help')) ?></p>

<ul class="admin-list card">
  <?php foreach ($tables->tables() as $table): $id = (int) $table['id']; ?>
  <li class="admin-row">
    <span class="admin-row__main">
      <strong><?= e(table_label($table['code'], $table['area'])) ?></strong>
      <?php if (!$table['is_active']): ?><span class="badge badge-muted"><?= e(t('menu.inactive')) ?></span><?php endif; ?><br>
      <a class="text-sm" href="<?= e($base . '/t.php?t=' . $table['qr_token']) ?>"><?= e(t('tables.admin.open_page')) ?></a>
    </span>
    <span class="cluster">
      <form method="post" action="/admin/tables.php" data-confirm="<?= e(t('tables.admin.confirm_regenerate', ['table' => $table['code']])) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="regenerate">
        <input type="hidden" name="id" value="<?= $id ?>">
        <button type="submit" class="btn btn-secondary"><?= e(t('tables.admin.regenerate')) ?></button>
      </form>
      <form method="post" action="/admin/tables.php">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="<?= $table['is_active'] ? 'deactivate' : 'activate' ?>">
        <input type="hidden" name="id" value="<?= $id ?>">
        <button type="submit" class="btn <?= $table['is_active'] ? 'btn-secondary' : 'btn-success' ?>">
          <?= e($table['is_active'] ? t('staff.deactivate') : t('staff.activate')) ?>
        </button>
      </form>
    </span>
  </li>
  <?php endforeach; ?>
</ul>
<?php
view('admin_footer');
