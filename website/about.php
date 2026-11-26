<?php

declare(strict_types=1);

// About (SPEC §13): the family story the owner writes in admin → Website content.

use App\Services\SiteService;

require __DIR__ . '/boot.php';

$about = (new SiteService())->text('about');
$text = lang_field($about, 'value');
// Blank lines start a new paragraph; single line breaks stay inside it.
$paragraphs = array_values(array_filter(array_map('trim', preg_split('/\n\s*\n/', $text) ?: []), static fn (string $p): bool => $p !== ''));

view('site_header', ['page' => 'about', 'path' => '/about.php']);
?>
<h1><?= e(t('site.nav.about')) ?></h1>
<p class="tagline"><?= e(t('shop.tagline')) ?> · <?= e(t('shop.years')) ?></p>
<?php if ($paragraphs === []): ?>
<p class="muted"><?= e(t('site.about_placeholder')) ?></p>
<?php endif; ?>
<div class="prose">
  <?php foreach ($paragraphs as $paragraph): ?>
  <p><?= nl2br(e($paragraph), false) ?></p>
  <?php endforeach; ?>
</div>
<?php
view('site_footer');
