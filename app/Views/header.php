<?php
/**
 * Customer page start (order site: takeaway, table, status, bulk). Vars: $title (string),
 * optional $css (list of paths under assets/).
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
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#FBF8F3">
<?= csrf_meta() ?>
<title><?= e($title) ?></title>
<link rel="icon" href="/assets/img/favicon.png" type="image/png">
<link rel="icon" href="/assets/img/icon-192.png" sizes="192x192" type="image/png">
<?php foreach ($css as $file): ?>
<link rel="stylesheet" href="<?= e(asset($file)) ?>">
<?php endforeach; ?>
</head>
<body class="shop-body">
<header class="site-header shop-header">
  <div class="container site-header__inner">
    <a class="brand" href="/">
      <img class="brand__mark" src="/assets/img/logo-mark.png" width="35" height="40" alt="">
      <span class="brand__text">
        <span class="brand__name"><?= e(lang_field($shop, 'shop_name')) ?></span>
        <span class="brand__tag"><?= e(t('shop.years')) ?></span>
      </span>
    </a>
    <a class="lang-toggle" href="<?= e(lang_switch_url($otherLang)) ?>" hreflang="<?= e($otherLang) ?>" lang="<?= e($otherLang) ?>"><?= e(t('lang.switch')) ?></a>
  </div>
</header>
<main class="container page">
