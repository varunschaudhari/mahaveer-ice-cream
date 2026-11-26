<?php

declare(strict_types=1);

// Gallery (SPEC §13): photos the owner uploads in admin → Website content. Lazy-loaded with
// width / height set so the page does not jump while they load.

use App\Services\SiteService;

require __DIR__ . '/boot.php';

$photos = array_values(array_filter((new SiteService())->photos(), static fn (array $p): bool => $p['width'] !== null));

view('site_header', ['page' => 'gallery', 'path' => '/gallery.php']);
?>
<h1><?= e(t('site.nav.gallery')) ?></h1>
<?php if ($photos === []): ?>
<?php /* TODO: owner content – shop, family and ice cream photos. */ ?>
<p class="muted"><?= e(t('site.gallery_empty')) ?></p>
<?php else: ?>
<ul class="gallery">
  <?php foreach ($photos as $i => $photo): $caption = lang_field($photo, 'caption'); ?>
  <li>
    <figure>
      <img src="/media.php?f=<?= e(rawurlencode($photo['file_name'])) ?>" width="<?= (int) $photo['width'] ?>" height="<?= (int) $photo['height'] ?>"
           alt="<?= e($caption !== '' ? $caption : t('site.photo_alt', ['n' => $i + 1])) ?>"<?= $i === 0 ? ' fetchpriority="high"' : ' loading="lazy"' ?>>
      <?php if ($caption !== ''): ?><figcaption><?= e($caption) ?></figcaption><?php endif; ?>
    </figure>
  </li>
  <?php endforeach; ?>
</ul>
<?php endif; ?>
<?php
view('site_footer');
