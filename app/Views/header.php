<?php
/**
 * Page start. Vars: $title (string), optional $css (list of paths under assets/).
 * @var string $title
 */

use App\Settings;

$css ??= ['css/base.css'];
$shop = Settings::all();
$otherLang = lang() === 'mr' ? 'en' : 'mr';
?>
<!doctype html>
<html lang="<?= e(lang()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?= csrf_meta() ?>
<title><?= e($title) ?></title>
<?php foreach ($css as $file): ?>
<link rel="stylesheet" href="<?= e(asset($file)) ?>">
<?php endforeach; ?>
</head>
<body>
<header class="site-header">
  <div class="container site-header__inner">
    <a class="brand" href="/"><?= e(lang_field($shop, 'shop_name')) ?></a>
    <a class="lang-toggle" href="<?= e(lang_switch_url($otherLang)) ?>" hreflang="<?= e($otherLang) ?>"><?= e(t('lang.switch')) ?></a>
  </div>
</header>
<main class="container page">
