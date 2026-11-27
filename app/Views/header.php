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
<meta name="theme-color" content="#C2185B">
<?= csrf_meta() ?>
<title><?= e($title) ?></title>
<link rel="icon" href="/assets/img/icon-192.png" sizes="192x192" type="image/png">
<?php foreach ($css as $file): ?>
<link rel="stylesheet" href="<?= e(asset($file)) ?>">
<?php endforeach; ?>
</head>
<body class="shop-body">
<header class="site-header shop-header">
  <div class="container site-header__inner">
    <a class="brand" href="/">
      <span class="brand__mark" aria-hidden="true">
        <svg viewBox="0 0 40 48" width="22" height="26"><path d="M9 21h22L20 46z" fill="#FFB300"/><path d="M14 21l6 19M20 21v21M26 21l-6 19" stroke="#E09400" stroke-width="1.6" fill="none"/><ellipse cx="20" cy="17" rx="13" ry="10" fill="#F8BBD0"/><ellipse cx="20" cy="9" rx="8.5" ry="7" fill="#FFF8F0"/></svg>
      </span>
      <span class="brand__text">
        <span class="brand__name"><?= e(lang_field($shop, 'shop_name')) ?></span>
        <span class="brand__tag"><?= e(t('shop.years')) ?></span>
      </span>
    </a>
    <a class="lang-toggle" href="<?= e(lang_switch_url($otherLang)) ?>" hreflang="<?= e($otherLang) ?>" lang="<?= e($otherLang) ?>"><?= e(t('lang.switch')) ?></a>
  </div>
</header>
<main class="container page">
