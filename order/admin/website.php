<?php

declare(strict_types=1);

// Admin: website content (SPEC §12, BUILD_PLAN M8) – About text and announcement banner in both
// languages, and the photo gallery (the first visible photo is the home page photo). Best
// sellers on the home page are automatic (top sales of the last 30 days).

use App\Auth;
use App\Csrf;
use App\Services\ImageService;
use App\Services\SiteService;
use App\ValidationException;

require __DIR__ . '/../boot.php';

$user = Auth::requireAdmin();
$site = new SiteService();
$images = new ImageService();
$errors = [];
$input = [];

if (is_post()) {
    Csrf::check();
    $action = (string) ($_POST['action'] ?? '');
    $input = $_POST;
    try {
        switch ($action) {
            case 'about':
            case 'banner':
                $site->saveText(
                    $action,
                    (string) ($_POST[$action . '_mr'] ?? ''),
                    (string) ($_POST[$action . '_en'] ?? ''),
                    $action === 'about' || !empty($_POST['banner_active']),
                    $user['id']
                );
                flash('success', t('site.admin.saved'));
                redirect('/admin/website.php#' . $action);

            case 'add_photo':
                $name = $images->storeUpload($_FILES['photo'] ?? []);
                try {
                    $site->addPhoto($name, (string) ($_POST['caption_mr'] ?? ''), (string) ($_POST['caption_en'] ?? ''), $user['id']);
                } catch (ValidationException $e) {
                    $images->delete($name);
                    throw $e;
                }
                flash('success', t('site.admin.photo_added'));
                redirect('/admin/website.php#gallery');

            case 'save_photo':
                $site->updatePhoto(
                    (int) ($_POST['id'] ?? 0),
                    (string) ($_POST['caption_mr'] ?? ''),
                    (string) ($_POST['caption_en'] ?? ''),
                    (int) ($_POST['sort_order'] ?? 0),
                    !empty($_POST['is_active']),
                    $user['id']
                );
                flash('success', t('site.admin.photo_saved'));
                redirect('/admin/website.php#photo-' . (int) ($_POST['id'] ?? 0));

            case 'delete_photo':
                $site->deletePhoto((int) ($_POST['id'] ?? 0), $user['id']);
                flash('success', t('site.admin.photo_deleted'));
                redirect('/admin/website.php#gallery');
        }
    } catch (ValidationException $e) {
        $errors = $e->errors;
    }
}

$about = $site->text('about');
$banner = $site->text('banner');
$photos = $site->photos(false);
$heroId = $site->heroPhoto()['id'] ?? null;
$siteUrl = rtrim((string) config('APP_URL_WEBSITE', ''), '/');
/** The posted value after a failed save, else the saved one. */
$value = static fn (string $field, string $saved): string => isset($input[$field]) && is_string($input[$field]) ? $input[$field] : $saved;

view('admin_header', ['title' => t('admin.nav.website'), 'active' => 'website', 'user' => $user]);
?>
<div class="section-head">
  <h1><?= e(t('admin.nav.website')) ?></h1>
  <a class="btn btn-secondary" href="<?= e($siteUrl . '/') ?>" target="_blank" rel="noopener"><?= e(t('site.admin.view_site')) ?></a>
</div>
<?php if ($errors !== []): ?><p class="flash flash-error" role="alert"><?= e(t('settings.fix_errors')) ?></p><?php endif; ?>
<p class="muted text-sm"><?= e(t('site.admin.best_sellers_note')) ?></p>

<form method="post" action="/admin/website.php" class="card stack" id="banner">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="banner">
  <h2><?= e(t('site.admin.banner')) ?></h2>
  <p class="muted text-sm"><?= e(t('site.admin.banner_help')) ?></p>
  <div class="form-grid">
    <div class="field<?= isset($errors['banner_mr']) ? ' has-error' : '' ?>">
      <label for="banner-mr"><?= e(t('site.admin.text_mr')) ?></label>
      <input class="input" id="banner-mr" name="banner_mr" maxlength="<?= SiteService::TEXT_KEYS['banner'] ?>" value="<?= e($value('banner_mr', $banner['value_mr'])) ?>">
      <?= field_error($errors, 'banner_mr') ?>
    </div>
    <div class="field<?= isset($errors['banner_en']) ? ' has-error' : '' ?>">
      <label for="banner-en"><?= e(t('site.admin.text_en')) ?></label>
      <input class="input" id="banner-en" name="banner_en" maxlength="<?= SiteService::TEXT_KEYS['banner'] ?>" value="<?= e($value('banner_en', $banner['value_en'])) ?>">
      <?= field_error($errors, 'banner_en') ?>
    </div>
  </div>
  <label class="check"><input type="checkbox" name="banner_active" value="1"<?= $banner['is_active'] ? ' checked' : '' ?>> <?= e(t('site.admin.banner_on')) ?></label>
  <div><button type="submit" class="btn btn-primary"><?= e(t('common.save')) ?></button></div>
</form>

<form method="post" action="/admin/website.php" class="card stack" id="about">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="about">
  <h2><?= e(t('site.admin.about')) ?></h2>
  <p class="muted text-sm"><?= e(t('site.admin.about_help')) ?></p>
  <div class="field<?= isset($errors['about_mr']) ? ' has-error' : '' ?>">
    <label for="about-mr"><?= e(t('site.admin.text_mr')) ?></label>
    <textarea class="textarea" id="about-mr" name="about_mr" rows="8" maxlength="<?= SiteService::TEXT_KEYS['about'] ?>"><?= e($value('about_mr', $about['value_mr'])) ?></textarea>
    <?= field_error($errors, 'about_mr') ?>
  </div>
  <div class="field<?= isset($errors['about_en']) ? ' has-error' : '' ?>">
    <label for="about-en"><?= e(t('site.admin.text_en')) ?></label>
    <textarea class="textarea" id="about-en" name="about_en" rows="8" maxlength="<?= SiteService::TEXT_KEYS['about'] ?>"><?= e($value('about_en', $about['value_en'])) ?></textarea>
    <?= field_error($errors, 'about_en') ?>
  </div>
  <div><button type="submit" class="btn btn-primary"><?= e(t('common.save')) ?></button></div>
</form>

<section class="stack" id="gallery">
  <h2><?= e(t('site.admin.gallery')) ?></h2>
  <p class="muted text-sm"><?= e(t('site.admin.gallery_help')) ?></p>

  <form method="post" action="/admin/website.php" enctype="multipart/form-data" class="card stack">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add_photo">
    <div class="form-grid">
      <div class="field<?= isset($errors['photo']) ? ' has-error' : '' ?>">
        <label for="g-photo"><?= e(t('site.admin.photo')) ?></label>
        <input class="input" id="g-photo" name="photo" type="file" accept="image/jpeg,image/png,image/webp" required>
        <?= field_error($errors, 'photo') ?>
      </div>
      <div class="field<?= isset($errors['caption_mr']) ? ' has-error' : '' ?>">
        <label for="g-caption-mr"><?= e(t('site.admin.caption_mr')) ?></label>
        <input class="input" id="g-caption-mr" name="caption_mr" maxlength="150">
        <?= field_error($errors, 'caption_mr') ?>
      </div>
      <div class="field<?= isset($errors['caption_en']) ? ' has-error' : '' ?>">
        <label for="g-caption-en"><?= e(t('site.admin.caption_en')) ?></label>
        <input class="input" id="g-caption-en" name="caption_en" maxlength="150">
        <?= field_error($errors, 'caption_en') ?>
      </div>
    </div>
    <div><button type="submit" class="btn btn-primary"><?= e(t('site.admin.add_photo')) ?></button></div>
  </form>

  <?php if ($photos === []): ?>
  <p class="muted"><?= e(t('site.admin.no_photos')) ?></p>
  <?php endif; ?>
  <?php foreach ($photos as $photo): ?>
  <div class="card gallery-row" id="photo-<?= (int) $photo['id'] ?>">
    <img class="thumb" src="/media.php?f=<?= e(rawurlencode($photo['file_name'])) ?>" alt="" loading="lazy" width="120" height="120">
    <form method="post" action="/admin/website.php" class="stack-sm gallery-row__form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_photo">
      <input type="hidden" name="id" value="<?= (int) $photo['id'] ?>">
      <?php if ($photo['id'] === $heroId): ?><span class="badge"><?= e(t('site.admin.home_photo')) ?></span><?php endif; ?>
      <div class="form-grid">
        <div class="field">
          <label for="p<?= (int) $photo['id'] ?>-mr"><?= e(t('site.admin.caption_mr')) ?></label>
          <input class="input" id="p<?= (int) $photo['id'] ?>-mr" name="caption_mr" maxlength="150" value="<?= e((string) $photo['caption_mr']) ?>">
        </div>
        <div class="field">
          <label for="p<?= (int) $photo['id'] ?>-en"><?= e(t('site.admin.caption_en')) ?></label>
          <input class="input" id="p<?= (int) $photo['id'] ?>-en" name="caption_en" maxlength="150" value="<?= e((string) $photo['caption_en']) ?>">
        </div>
        <div class="field">
          <label for="p<?= (int) $photo['id'] ?>-sort"><?= e(t('site.admin.sort')) ?></label>
          <input class="input input-sort" id="p<?= (int) $photo['id'] ?>-sort" name="sort_order" type="number" min="0" max="9999" value="<?= (int) $photo['sort_order'] ?>">
        </div>
      </div>
      <label class="check"><input type="checkbox" name="is_active" value="1"<?= $photo['is_active'] ? ' checked' : '' ?>> <?= e(t('site.admin.visible')) ?></label>
      <div class="cluster">
        <button type="submit" class="btn btn-primary"><?= e(t('common.save')) ?></button>
      </div>
    </form>
    <form method="post" action="/admin/website.php" data-confirm="<?= e(t('site.admin.delete_confirm')) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="delete_photo">
      <input type="hidden" name="id" value="<?= (int) $photo['id'] ?>">
      <button type="submit" class="btn btn-outline-danger"><?= e(t('site.admin.delete')) ?></button>
    </form>
  </div>
  <?php endforeach; ?>
</section>
<?php
view('admin_footer');
