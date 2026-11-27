<?php
/**
 * Public website page start (SPEC §13). Vars:
 *   $page        nav key: home | menu | about | bulk | gallery | location | contact
 *   $path        this page's path for the canonical URL ('/' or '/menu.php')
 *   $jsonLd      optional array (schema.org data, printed as JSON-LD)
 *   $ogImage     optional absolute image URL for link previews
 * Title and description come from site.title.<page> / site.desc.<page>.
 * @var string $page
 * @var string $path
 */

use App\Services\SiteService;
use App\Settings;

$shop = Settings::all();
$siteUrl = rtrim((string) config('APP_URL_WEBSITE', ''), '/');
$orderUrl = rtrim((string) config('APP_URL_ORDER', ''), '/');
$otherLang = lang() === 'mr' ? 'en' : 'mr';
$ogImage ??= $siteUrl . '/assets/img/icon-512.png';
$jsonLd ??= null;
$banner = (new SiteService())->banner();
$title = t('site.title.' . $page);
$description = t('site.desc.' . $page);
?>
<!doctype html>
<html lang="<?= e(lang()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($title) ?></title>
<meta name="description" content="<?= e($description) ?>">
<?php if (app_env() !== 'production'): ?>
<meta name="robots" content="noindex">
<?php endif; ?>
<link rel="canonical" href="<?= e($siteUrl . $path) ?>">
<meta name="theme-color" content="#C2185B">
<link rel="icon" href="/assets/img/icon-192.png" sizes="192x192" type="image/png">
<link rel="apple-touch-icon" href="/assets/img/icon-192.png">
<meta property="og:type" content="website">
<meta property="og:site_name" content="<?= e(lang_field($shop, 'shop_name')) ?>">
<meta property="og:title" content="<?= e($title) ?>">
<meta property="og:description" content="<?= e($description) ?>">
<meta property="og:url" content="<?= e($siteUrl . $path) ?>">
<meta property="og:image" content="<?= e($ogImage) ?>">
<meta property="og:locale" content="<?= lang() === 'mr' ? 'mr_IN' : 'en_IN' ?>">
<link rel="stylesheet" href="<?= e(asset('css/base.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/site.css')) ?>">
<?php if ($jsonLd !== null): ?>
<script type="application/ld+json"><?= json_encode($jsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php endif; ?>
</head>
<body class="site">
<a class="skip-link" href="#main"><?= e(t('site.skip')) ?></a>
<header class="site-header">
  <div class="container site-header__inner">
    <?php /* TODO: owner content – logo file; the cone mark + shop name stand in until then. */ ?>
    <a class="brand" href="/">
      <span class="brand__mark" aria-hidden="true">
        <svg viewBox="0 0 40 48" width="24" height="29"><path d="M9 21h22L20 46z" fill="#FFB300"/><path d="M14 21l6 19M20 21v21M26 21l-6 19" stroke="#E09400" stroke-width="1.6" fill="none"/><ellipse cx="20" cy="17" rx="13" ry="10" fill="#F8BBD0"/><ellipse cx="20" cy="9" rx="8.5" ry="7" fill="#FFF8F0"/></svg>
      </span>
      <span class="brand__text">
        <span class="brand__name"><?= e(lang_field($shop, 'shop_name')) ?></span>
        <span class="brand__tag"><?= e(t('shop.years')) ?></span>
      </span>
    </a>
    <nav class="site-nav" aria-label="<?= e(t('site.nav_label')) ?>">
      <?php foreach (SiteService::PAGES as $key => $href): ?>
      <a href="<?= e($href) ?>"<?= $key === $page ? ' aria-current="page"' : '' ?>><?= e(t('site.nav.' . $key)) ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="site-header__actions">
      <a class="lang-toggle" href="<?= e(lang_switch_url($otherLang)) ?>" hreflang="<?= e($otherLang) ?>" lang="<?= e($otherLang) ?>"><?= e(t('lang.switch')) ?></a>
      <a class="btn btn-primary site-header__order" href="<?= e($orderUrl . '/') ?>"><?= icon('bag', 18) ?><?= e(t('site.order_online')) ?></a>
    </div>
  </div>
</header>
<?php if ($banner !== null): ?>
<p class="site-banner" role="status"><span class="container"><?= e(lang_field($banner, 'value')) ?></span></p>
<?php endif; ?>
<main id="main" class="container page">
