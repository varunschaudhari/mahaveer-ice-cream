<?php

declare(strict_types=1);

// Admin: staff accounts — add, edit name/role, reset PIN, set password, unlock, (de)activate.

use App\Auth;
use App\Csrf;
use App\Services\StaffService;
use App\ValidationException;

require __DIR__ . '/../boot.php';

$user = Auth::requireAdmin();
$service = new StaffService();
$actor = ['id' => $user['id'], 'role' => $user['role']];

$errors = [];
$old = [];
$failedAction = null;
$failedId = null;
$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : null;

if (is_post()) {
    Csrf::check();
    $action = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    try {
        switch ($action) {
            case 'create':
                $service->create($_POST, $actor);
                $message = t('staff.created');
                break;
            case 'update':
                $service->update($id, $_POST, $actor);
                $message = t('staff.saved');
                break;
            case 'pin':
                $service->setPin($id, (string) ($_POST['pin'] ?? ''), $actor);
                $message = t('staff.pin_saved');
                break;
            case 'password':
                $service->setPassword($id, (string) ($_POST['password'] ?? ''), $actor);
                $message = t('staff.password_saved');
                break;
            case 'activate':
            case 'deactivate':
                $service->setActive($id, $action === 'activate', $actor);
                $message = $action === 'activate' ? t('staff.activated') : t('staff.deactivated');
                break;
            case 'unlock':
                $service->unlock($id, $actor);
                $message = t('staff.unlocked');
                break;
            default:
                throw ValidationException::one('_', 'error.generic');
        }
        flash('success', $message);
        redirect('/admin/staff.php');
    } catch (ValidationException $e) {
        $errors = $e->errors;
        $old = $_POST;
        $failedAction = $action;
        $failedId = $id;
        if ($action === 'update') {
            $editId = $id;
        }
    }
}

$people = $service->all();
$roles = $user['role'] === 'super' ? StaffService::ROLES : ['staff', 'owner'];

/** Errors for one form on the page: only the form that was submitted shows them. */
$errorsFor = static fn (string $action, int $id = 0): array =>
    $failedAction === $action && $failedId === $id ? $errors : [];

view('admin_header', ['title' => t('admin.nav.staff'), 'active' => 'staff', 'user' => $user]);
?>
<h1><?= e(t('admin.nav.staff')) ?></h1>

<?php foreach ($people as $person):
    $pid = (int) $person['id'];
    $canManage = $service->canManageRole($actor, $person['role']);
    $locked = $person['locked_until'] !== null && $person['locked_until'] > now()->format('Y-m-d H:i:s');
    $isAdminRole = $person['role'] !== 'staff';
?>
<article class="card stack-sm staff-card<?= $person['is_active'] ? '' : ' is-inactive' ?>" id="staff-<?= $pid ?>">
  <div class="section-head">
    <h2>
      <?= e($person['name']) ?>
      <span class="badge"><?= e(role_label($person['role'])) ?></span>
      <?php if (!$person['is_active']): ?><span class="badge badge-muted"><?= e(t('staff.inactive')) ?></span><?php endif; ?>
      <?php if ($locked): ?><span class="badge badge-danger"><?= e(t('staff.locked_until', ['time' => format_date_time($person['locked_until'])])) ?></span><?php endif; ?>
      <?php if (!$person['has_pin']): ?><span class="badge badge-muted"><?= e(t('staff.no_pin')) ?></span><?php endif; ?>
    </h2>
    <?php if ($person['username']): ?><span class="muted"><?= e(t('admin.username')) ?>: <?= e($person['username']) ?></span><?php endif; ?>
  </div>

  <?php $formErrors = $errorsFor('activate', $pid) + $errorsFor('deactivate', $pid) + $errorsFor('unlock', $pid); ?>
  <?php if (isset($formErrors['_'])): ?><p class="flash flash-error"><?= e(error_text($formErrors['_'])) ?></p><?php endif; ?>

  <?php if ($canManage): ?>
    <?php if ($editId === $pid): $formErrors = $errorsFor('update', $pid); $values = $formErrors ? $old : $person; ?>
    <form method="post" action="/admin/staff.php#staff-<?= $pid ?>" class="stack-sm">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="id" value="<?= $pid ?>">
      <?php if (isset($formErrors['_'])): ?><p class="flash flash-error"><?= e(error_text($formErrors['_'])) ?></p><?php endif; ?>
      <div class="form-grid">
        <div class="field">
          <label for="name-<?= $pid ?>"><?= e(t('staff.name')) ?></label>
          <input class="input" id="name-<?= $pid ?>" name="name" value="<?= e($values['name'] ?? '') ?>" maxlength="100" required>
          <?= field_error($formErrors, 'name') ?>
        </div>
        <div class="field">
          <label for="role-<?= $pid ?>"><?= e(t('staff.role')) ?></label>
          <select class="select" id="role-<?= $pid ?>" name="role">
            <?php foreach ($roles as $role): ?>
            <option value="<?= e($role) ?>"<?= ($values['role'] ?? '') === $role ? ' selected' : '' ?>><?= e(role_label($role)) ?></option>
            <?php endforeach; ?>
          </select>
          <?= field_error($formErrors, 'role') ?>
        </div>
        <?php if (!$person['has_password']): ?>
        <div class="field">
          <label for="username-<?= $pid ?>"><?= e(t('admin.username')) ?></label>
          <input class="input" id="username-<?= $pid ?>" name="username" value="<?= e($old['username'] ?? '') ?>" autocapitalize="none" autocomplete="off">
          <?= field_error($formErrors, 'username') ?>
        </div>
        <div class="field">
          <label for="password-<?= $pid ?>"><?= e(t('admin.password')) ?></label>
          <input class="input" id="password-<?= $pid ?>" name="password" type="password" autocomplete="new-password">
          <?= field_error($formErrors, 'password') ?>
        </div>
        <?php endif; ?>
      </div>
      <?php if (!$person['has_password']): ?><p class="text-sm muted"><?= e(t('staff.login_help')) ?></p><?php endif; ?>
      <div class="cluster">
        <button type="submit" class="btn btn-primary"><?= e(t('common.save')) ?></button>
        <a class="btn btn-secondary" href="/admin/staff.php"><?= e(t('common.cancel')) ?></a>
      </div>
    </form>
    <?php endif; ?>

    <div class="cluster">
      <?php if ($editId !== $pid): ?>
      <a class="btn btn-secondary" href="/admin/staff.php?edit=<?= $pid ?>#staff-<?= $pid ?>"><?= e(t('common.edit')) ?></a>
      <?php endif; ?>

      <form method="post" action="/admin/staff.php#staff-<?= $pid ?>" class="inline-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="pin">
        <input type="hidden" name="id" value="<?= $pid ?>">
        <input class="input input-pin" name="pin" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" autocomplete="off"
               placeholder="<?= e(t('staff.new_pin')) ?>" aria-label="<?= e(t('staff.new_pin')) ?>" required>
        <button type="submit" class="btn btn-secondary"><?= e(t('staff.set_pin')) ?></button>
      </form>

      <?php if ($isAdminRole && $person['has_password']): ?>
      <form method="post" action="/admin/staff.php#staff-<?= $pid ?>" class="inline-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="password">
        <input type="hidden" name="id" value="<?= $pid ?>">
        <input class="input" name="password" type="password" autocomplete="new-password"
               placeholder="<?= e(t('staff.new_password')) ?>" aria-label="<?= e(t('staff.new_password')) ?>" required>
        <button type="submit" class="btn btn-secondary"><?= e(t('staff.set_password')) ?></button>
      </form>
      <?php endif; ?>

      <?php if ($locked || $person['failed_attempts'] > 0): ?>
      <form method="post" action="/admin/staff.php#staff-<?= $pid ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="unlock">
        <input type="hidden" name="id" value="<?= $pid ?>">
        <button type="submit" class="btn btn-success"><?= e(t('staff.unlock')) ?></button>
      </form>
      <?php endif; ?>

      <?php if ($pid !== $user['id']): ?>
      <form method="post" action="/admin/staff.php#staff-<?= $pid ?>"
            <?= $person['is_active'] ? 'data-confirm="' . e(t('staff.confirm_deactivate', ['name' => $person['name']])) . '"' : '' ?>>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="<?= $person['is_active'] ? 'deactivate' : 'activate' ?>">
        <input type="hidden" name="id" value="<?= $pid ?>">
        <button type="submit" class="btn <?= $person['is_active'] ? 'btn-danger' : 'btn-success' ?>">
          <?= e($person['is_active'] ? t('staff.deactivate') : t('staff.activate')) ?>
        </button>
      </form>
      <?php endif; ?>
    </div>
    <?php $formErrors = $errorsFor('pin', $pid) + $errorsFor('password', $pid); ?>
    <?= field_error($formErrors, 'pin') ?><?= field_error($formErrors, 'password') ?>
  <?php endif; ?>
</article>
<?php endforeach; ?>

<?php $formErrors = $errorsFor('create'); $values = $formErrors ? $old : ['name' => '', 'role' => 'staff', 'username' => '']; ?>
<form method="post" action="/admin/staff.php#add-staff" class="card stack" id="add-staff">
  <h2><?= e(t('staff.add')) ?></h2>
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="create">
  <?php if (isset($formErrors['_'])): ?><p class="flash flash-error"><?= e(error_text($formErrors['_'])) ?></p><?php endif; ?>
  <div class="form-grid">
    <div class="field">
      <label for="new-name"><?= e(t('staff.name')) ?></label>
      <input class="input" id="new-name" name="name" value="<?= e($values['name'] ?? '') ?>" maxlength="100" required>
      <?= field_error($formErrors, 'name') ?>
    </div>
    <div class="field">
      <label for="new-role"><?= e(t('staff.role')) ?></label>
      <select class="select" id="new-role" name="role">
        <?php foreach ($roles as $role): ?>
        <option value="<?= e($role) ?>"<?= ($values['role'] ?? 'staff') === $role ? ' selected' : '' ?>><?= e(role_label($role)) ?></option>
        <?php endforeach; ?>
      </select>
      <?= field_error($formErrors, 'role') ?>
    </div>
    <div class="field">
      <label for="new-pin"><?= e(t('staff.pin')) ?></label>
      <input class="input input-pin" id="new-pin" name="pin" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" autocomplete="off">
      <?= field_error($formErrors, 'pin') ?>
    </div>
    <div class="field">
      <label for="new-username"><?= e(t('admin.username')) ?></label>
      <input class="input" id="new-username" name="username" value="<?= e($values['username'] ?? '') ?>" autocapitalize="none" autocomplete="off">
      <?= field_error($formErrors, 'username') ?>
    </div>
    <div class="field">
      <label for="new-password"><?= e(t('admin.password')) ?></label>
      <input class="input" id="new-password" name="password" type="password" autocomplete="new-password">
      <?= field_error($formErrors, 'password') ?>
    </div>
  </div>
  <p class="text-sm muted"><?= e(t('staff.add_help')) ?></p>
  <div><button type="submit" class="btn btn-primary"><?= e(t('staff.add')) ?></button></div>
</form>
<?php
view('admin_footer');
