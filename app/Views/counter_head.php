<?php
/**
 * Counter page start (Chrome 109, touch). Vars: $title.
 * @var string $title
 */
?>
<!doctype html>
<html lang="<?= e(lang()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?= csrf_meta() ?>
<title><?= e($title) ?></title>
<link rel="stylesheet" href="<?= e(asset('css/base.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/counter.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/app-nav.css')) ?>">
</head>
<body class="counter">
