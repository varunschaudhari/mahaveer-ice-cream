<?php

declare(strict_types=1);

// Admin: categories (add / edit) and the list of items in each category.

use App\Auth;
use App\Csrf;
use App\Services\MenuService;
use App\ValidationException;

require __DIR__ . '/../boot.php';

$user = Auth::requireAdmin();
$menu = new MenuService();
$errors = [];
$old = null;
$editId = isset($_GET['category']) ? (int) $_GET['category'] : null;

if (is_post()) {
    Csrf::check();
    $id = ($_POST['id'] ?? '') === '' ? null : (int) $_POST['id'];
    try {
        $menu->saveCategory($id, $_POST, $user['id']);
        flash('success', t('menu.category_saved'));
        redirect('/admin/menu.php');
    } catch (ValidationException $e) {
        $errors = $e->errors;
        $old = $_POST;
        $editId = $id;
    }
}

$categories = $menu->allCategories();
$itemsByCategory = $menu->allItemsByCategory();
$editing = $editId !== null ? $menu->findCategory($editId) : null;
$nextSort = $categories === [] ? 1 : max(array_column($categories, 'sort_order')) + 1;
$form = $old ?? $editing ?? ['name_mr' => '', 'name_en' => '', 'sort_order' => $nextSort, 'is_active' => 1];

/** "हाफ ₹20 · फुल ₹40" from an item's active variants. */
$priceSummary = static function (array $item): string {
    $parts = [];
    foreach ($item['variants'] as $variant) {
        if ($variant['is_active']) {
            $parts[] = lang_field($variant, 'label') . ' ' . money($variant['price_paise']);
        }
    }
    return implode(' · ', $parts);
};

view('admin_header', ['title' => t('admin.nav.menu'), 'active' => 'menu', 'user' => $user]);
?>
<h1><?= e(t('admin.nav.menu')) ?></h1>

<form method="post" action="/admin/menu.php" class="card stack" id="category-form">
  <h2><?= e($editing ? t('menu.edit_category') : t('menu.add_category')) ?></h2>
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= $editing ? (int) $editing['id'] : '' ?>">
  <?php if (isset($errors['_'])): ?><p class="flash flash-error"><?= e(error_text($errors['_'])) ?></p><?php endif; ?>
  <div class="form-grid">
    <div class="field">
      <label for="c-name-mr"><?= e(t('menu.name_mr')) ?></label>
      <input class="input" id="c-name-mr" name="name_mr" value="<?= e($form['name_mr']) ?>" maxlength="100" required>
      <?= field_error($errors, 'name_mr') ?>
    </div>
    <div class="field">
      <label for="c-name-en"><?= e(t('menu.name_en')) ?></label>
      <input class="input" id="c-name-en" name="name_en" value="<?= e($form['name_en']) ?>" maxlength="100" required>
      <?= field_error($errors, 'name_en') ?>
    </div>
    <div class="field">
      <label for="c-sort"><?= e(t('menu.sort_order')) ?></label>
      <input class="input" id="c-sort" name="sort_order" type="number" min="0" max="9999" value="<?= e($form['sort_order']) ?>">
      <?= field_error($errors, 'sort_order') ?>
    </div>
  </div>
  <label class="check"><input type="checkbox" name="is_active" value="1"<?= checked($form['is_active'] ?? 0) ?>> <?= e(t('menu.active')) ?></label>
  <div class="cluster">
    <button type="submit" class="btn btn-primary"><?= e(t('common.save')) ?></button>
    <?php if ($editing): ?><a class="btn btn-secondary" href="/admin/menu.php"><?= e(t('common.cancel')) ?></a><?php endif; ?>
  </div>
</form>

<?php foreach ($categories as $category): ?>
<section class="stack-sm">
  <div class="section-head">
    <h2>
      <?= e($category['name_mr']) ?> <span class="muted">· <?= e($category['name_en']) ?></span>
      <?php if (!$category['is_active']): ?><span class="badge badge-muted"><?= e(t('menu.inactive')) ?></span><?php endif; ?>
    </h2>
    <span class="cluster">
      <a class="btn btn-secondary" href="/admin/menu.php?category=<?= (int) $category['id'] ?>#category-form"><?= e(t('common.edit')) ?></a>
      <a class="btn btn-primary" href="/admin/items.php?category_id=<?= (int) $category['id'] ?>"><?= e(t('menu.add_item')) ?></a>
    </span>
  </div>
  <ul class="admin-list card">
    <?php foreach ($itemsByCategory[(int) $category['id']] ?? [] as $item): ?>
    <li>
      <a class="admin-row" href="/admin/items.php?id=<?= (int) $item['id'] ?>">
        <span class="admin-row__main">
          <strong><?= e($item['name_mr']) ?></strong> <span class="muted">· <?= e($item['name_en']) ?></span><br>
          <span class="text-sm"><?= e($priceSummary($item)) ?></span>
        </span>
        <span class="cluster">
          <?php if (!$item['is_active']): ?><span class="badge badge-muted"><?= e(t('menu.inactive')) ?></span><?php endif; ?>
          <?php if (!$item['online_enabled']): ?><span class="badge badge-muted"><?= e(t('menu.online_off')) ?></span><?php endif; ?>
          <?php if (!$item['is_available']): ?><span class="badge badge-danger"><?= e(t('menu.sold_out')) ?></span><?php endif; ?>
        </span>
      </a>
    </li>
    <?php endforeach; ?>
    <?php if (empty($itemsByCategory[(int) $category['id']])): ?>
    <li class="muted admin-row"><?= e(t('menu.no_items')) ?></li>
    <?php endif; ?>
  </ul>
</section>
<?php endforeach; ?>
<?php
view('admin_footer');
