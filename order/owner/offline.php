<?php

declare(strict_types=1);

// "No internet" page for the owner's phone. The service worker stores it once and shows it when
// a page under /owner/ cannot load. It cannot know the language then, so it shows both. Plain
// stylesheet URLs (no ?v=) because the service worker caches exactly these.

use App\Lang;

require __DIR__ . '/../boot.php';

$mr = Lang::strings('mr');
$en = Lang::strings('en');
?>
<!doctype html>
<html lang="mr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<meta name="theme-color" content="#C2185B">
<title><?= e($mr['owner.offline_title']) ?> · <?= e($en['owner.offline_title']) ?></title>
<link rel="stylesheet" href="/assets/css/base.css">
<link rel="stylesheet" href="/assets/css/owner.css">
</head>
<body class="owner">
<main class="container page stack owner-offline-page">
  <section class="card stack-sm">
    <h1><?= e($mr['owner.offline_title']) ?></h1>
    <p><?= e($mr['owner.offline_text']) ?></p>
  </section>
  <section class="card stack-sm" lang="en">
    <h1><?= e($en['owner.offline_title']) ?></h1>
    <p><?= e($en['owner.offline_text']) ?></p>
  </section>
  <a class="btn btn-primary btn-block" href="/owner/"><?= e($mr['owner.offline_retry']) ?> · <span lang="en"><?= e($en['owner.offline_retry']) ?></span></a>
</main>
</body>
</html>
