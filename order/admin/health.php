<?php

declare(strict_types=1);

// Admin: System health (super-admin only, developer decision M9): crons, backups, today's errors,
// server basics. Read-only. The owner's account does not see it.

use App\Auth;
use App\Services\HealthService;

require __DIR__ . '/../boot.php';

$user = Auth::requireAdmin();
if ($user['role'] !== 'super') {
    http_response_code(403);
    view('error', ['message' => t('health.super_only')]);
    exit;
}

$health = new HealthService();
$now = now();
$checks = $health->checks($now, is_https());
$log = $health->todaysProblems($now);
$labels = [
    'ok' => t('health.status.ok'),
    'warn' => t('health.status.warn'),
    'fail' => t('health.status.fail'),
];
$worst = in_array('fail', array_column($checks, 'status'), true) ? 'fail' : (in_array('warn', array_column($checks, 'status'), true) ? 'warn' : 'ok');

view('admin_header', ['title' => t('admin.nav.health'), 'active' => 'health', 'user' => $user]);
?>
<div class="section-head">
  <h1><?= e(t('admin.nav.health')) ?></h1>
  <span class="health-badge is-<?= e($worst) ?>"><?= e($labels[$worst]) ?></span>
</div>
<p class="muted text-sm"><?= e(t('health.help', ['time' => format_date_time($now)])) ?></p>

<ul class="admin-list card">
  <?php foreach ($checks as $check): ?>
  <li class="admin-row">
    <span class="admin-row__main">
      <strong><?= e(t('health.check.' . $check['key'])) ?></strong><br>
      <span class="text-sm muted"><?= e($check['detail']) ?></span>
    </span>
    <span class="health-badge is-<?= e($check['status']) ?>"><?= e($labels[$check['status']]) ?></span>
  </li>
  <?php endforeach; ?>
</ul>

<h2><?= e(t('health.log_title')) ?></h2>
<?php if ($log['lines'] === []): ?>
<p class="muted"><?= e(t('health.log_empty')) ?></p>
<?php else: ?>
<pre class="card health-log"><?php foreach ($log['lines'] as $line): ?><?= e($line) ?>
<?php endforeach; ?></pre>
<?php endif; ?>
<?php
view('admin_footer');
