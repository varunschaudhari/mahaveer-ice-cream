<?php
/**
 * Friendly error page. Standalone (no database) because it is shown when things are broken.
 * Optional vars: $message (string shown to the user), $details (exception text, local only).
 */
$message ??= null;
$details ??= null;
?>
<!doctype html>
<html lang="<?= e(lang()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(t('error.title')) ?></title>
<link rel="stylesheet" href="<?= e(asset('css/base.css')) ?>">
</head>
<body>
<main class="container page stack">
  <h1><?= e(t('error.title')) ?></h1>
  <p><?= e($message ?? t('error.generic')) ?></p>
  <p><a class="btn btn-primary" href="/"><?= e(t('common.home')) ?></a></p>
  <?php if ($details !== null): ?>
  <pre class="error-details"><?= e($details) ?></pre>
  <?php endif; ?>
</main>
</body>
</html>
