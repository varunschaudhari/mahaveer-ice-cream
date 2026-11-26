<?php

declare(strict_types=1);

// Admin: add or edit one item with its photo and variants (items.php?id=5 or ?category_id=2).

use App\Auth;
use App\Csrf;
use App\Services\ImageService;
use App\Services\MenuService;
use App\ValidationException;

require __DIR__ . '/../boot.php';

$user = Auth::requireAdmin();
$menu = new MenuService();
$images = new ImageService();

$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
$item = $id !== null ? $menu->findItem($id) : null;
if ($id !== null && $item === null) {
    http_response_code(404);
    view('error', ['message' => t('error.not_found')]);
    exit;
}

$errors = [];
$old = null;
if (is_post()) {
    Csrf::check();
    $newPhoto = null;
    try {
        if ((int) ($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $newPhoto = $images->storeUpload($_FILES['photo']);
        }
        $variants = is_array($_POST['variants'] ?? null) ? $_POST['variants'] : [];
        $result = $menu->saveItem($item ? (int) $item['id'] : null, $_POST, $variants, $user['id'], $newPhoto, !empty($_POST['remove_photo']));
        $images->delete($result['old_photo']);
        flash('success', t('menu.item_saved'));
        redirect('/admin/items.php?id=' . $result['id']);
    } catch (ValidationException $e) {
        $images->delete($newPhoto);
        $errors = $e->errors;
        $old = $_POST;
    } catch (Throwable $e) {
        $images->delete($newPhoto);
        throw $e;
    }
}

$categories = $menu->allCategories();
$form = $old ?? $item ?? [
    'category_id' => (int) ($_GET['category_id'] ?? 0),
    'name_mr' => '',
    'name_en' => '',
    'sort_order' => 0,
    'is_active' => 1,
    'online_enabled' => 1,
];

// Variant rows: existing ones (with submitted values after a failed save) + blank rows to add new.
$rows = [];
foreach ($item['variants'] ?? [] as $variant) {
    $key = (string) $variant['id'];
    $rows[$key] = $old['variants'][$key] ?? [
        'label_mr' => $variant['label_mr'],
        'label_en' => $variant['label_en'],
        'price' => rupees_input($variant['price_paise']),
        'sort_order' => $variant['sort_order'],
        'is_active' => $variant['is_active'],
    ];
}
foreach ($item === null ? ['new1', 'new2'] : ['new1'] as $key) {
    $rows[$key] = $old['variants'][$key] ?? ['label_mr' => '', 'label_en' => '', 'price' => '', 'sort_order' => count($rows) + 1, 'is_active' => 1];
}

$title = $item ? $item['name_mr'] : t('menu.add_item');
view('admin_header', ['title' => $title, 'active' => 'menu', 'user' => $user]);
?>
<p><a href="/admin/menu.php">← <?= e(t('admin.nav.menu')) ?></a></p>
<h1><?= e($title) ?></h1>

<form method="post" action="/admin/items.php<?= $item ? '?id=' . (int) $item['id'] : '' ?>" enctype="multipart/form-data" class="stack">
  <?= csrf_field() ?>
  <?php if (isset($errors['_'])): ?><p class="flash flash-error" role="alert"><?= e(error_text($errors['_'])) ?></p><?php endif; ?>

  <div class="card stack">
    <div class="form-grid">
      <div class="field">
        <label for="i-category"><?= e(t('menu.category')) ?></label>
        <select class="select" id="i-category" name="category_id">
          <?php foreach ($categories as $category): ?>
          <option value="<?= (int) $category['id'] ?>"<?= (int) $category['id'] === (int) $form['category_id'] ? ' selected' : '' ?>>
            <?= e($category['name_mr'] . ' · ' . $category['name_en']) ?>
          </option>
          <?php endforeach; ?>
        </select>
        <?= field_error($errors, 'category_id') ?>
      </div>
      <div class="field">
        <label for="i-name-mr"><?= e(t('menu.name_mr')) ?></label>
        <input class="input" id="i-name-mr" name="name_mr" value="<?= e($form['name_mr']) ?>" maxlength="100" required>
        <?= field_error($errors, 'name_mr') ?>
      </div>
      <div class="field">
        <label for="i-name-en"><?= e(t('menu.name_en')) ?></label>
        <input class="input" id="i-name-en" name="name_en" value="<?= e($form['name_en']) ?>" maxlength="100" required>
        <?= field_error($errors, 'name_en') ?>
      </div>
      <div class="field">
        <label for="i-sort"><?= e(t('menu.sort_order')) ?></label>
        <input class="input" id="i-sort" name="sort_order" type="number" min="0" max="9999" value="<?= e($form['sort_order']) ?>">
        <?= field_error($errors, 'sort_order') ?>
      </div>
    </div>
    <label class="check"><input type="checkbox" name="is_active" value="1"<?= checked($form['is_active'] ?? 0) ?>> <?= e(t('menu.active')) ?></label>
    <label class="check"><input type="checkbox" name="online_enabled" value="1"<?= checked($form['online_enabled'] ?? 0) ?>> <?= e(t('menu.online_enabled')) ?></label>
    <p class="text-sm muted"><?= e(t('menu.active_help')) ?></p>
  </div>

  <div class="card stack">
    <h2><?= e(t('menu.photo')) ?></h2>
    <?php if (!empty($item['photo'])): ?>
    <img class="thumb" src="/media.php?f=<?= e($item['photo']) ?>" alt="" width="120" height="120">
    <label class="check"><input type="checkbox" name="remove_photo" value="1"> <?= e(t('menu.remove_photo')) ?></label>
    <?php endif; ?>
    <div class="field">
      <label for="i-photo"><?= e(t('menu.upload_photo')) ?></label>
      <input id="i-photo" name="photo" type="file" accept="image/jpeg,image/png,image/webp">
      <p class="text-sm muted"><?= e(t('menu.photo_help')) ?></p>
      <?= field_error($errors, 'photo') ?>
    </div>
  </div>

  <div class="card stack">
    <h2><?= e(t('menu.variants')) ?></h2>
    <div class="table-scroll">
      <table class="variant-table">
        <thead>
          <tr>
            <th><?= e(t('menu.label_mr')) ?></th>
            <th><?= e(t('menu.label_en')) ?></th>
            <th><?= e(t('menu.price')) ?></th>
            <th><?= e(t('menu.sort_order')) ?></th>
            <th><?= e(t('menu.active')) ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $key => $row): $p = "variants.{$key}."; $n = 'variants[' . e($key) . ']'; ?>
          <tr<?= str_starts_with($key, 'new') ? ' class="is-new"' : '' ?>>
            <td>
              <input class="input" name="<?= $n ?>[label_mr]" value="<?= e($row['label_mr'] ?? '') ?>" maxlength="50"
                     placeholder="<?= str_starts_with($key, 'new') ? e(t('menu.new_variant_mr')) : '' ?>" aria-label="<?= e(t('menu.label_mr')) ?>">
              <?= field_error($errors, $p . 'label_mr') ?>
            </td>
            <td>
              <input class="input" name="<?= $n ?>[label_en]" value="<?= e($row['label_en'] ?? '') ?>" maxlength="50"
                     placeholder="<?= str_starts_with($key, 'new') ? e(t('menu.new_variant_en')) : '' ?>" aria-label="<?= e(t('menu.label_en')) ?>">
              <?= field_error($errors, $p . 'label_en') ?>
            </td>
            <td>
              <input class="input input-price" name="<?= $n ?>[price]" value="<?= e($row['price'] ?? '') ?>" inputmode="decimal" aria-label="<?= e(t('menu.price')) ?>">
              <?= field_error($errors, $p . 'price') ?>
            </td>
            <td>
              <input class="input input-sort" name="<?= $n ?>[sort_order]" type="number" min="0" max="9999" value="<?= e($row['sort_order'] ?? 0) ?>" aria-label="<?= e(t('menu.sort_order')) ?>">
              <?= field_error($errors, $p . 'sort_order') ?>
            </td>
            <td class="text-center">
              <input type="checkbox" name="<?= $n ?>[is_active]" value="1"<?= checked($row['is_active'] ?? 0) ?> aria-label="<?= e(t('menu.active')) ?>">
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="text-sm muted"><?= e(t('menu.variants_help')) ?></p>
  </div>

  <div class="cluster">
    <button type="submit" class="btn btn-primary btn-lg"><?= e(t('common.save')) ?></button>
    <a class="btn btn-secondary btn-lg" href="/admin/menu.php"><?= e(t('common.cancel')) ?></a>
  </div>
</form>
<?php
view('admin_footer');
