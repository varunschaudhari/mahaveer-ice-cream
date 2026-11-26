<?php

declare(strict_types=1);

// Nightly database backup (cPanel cron, DEPLOYMENT.md §3):
//   30 23 * * *  /usr/local/bin/php /home/<user>/mahavir/cron/backup.php >/dev/null 2>&1
// storage/backups/mahavir-YYYY-MM-DD-HHMM.sql.gz, the last 30 days kept (BackupService).

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Cron;
use App\Services\BackupService;

Cron::run('backup', static function (): string {
    $result = (new BackupService())->run(now());
    $message = sprintf('%s, %s KB (%s), %d old backup(s) deleted', $result['file'], number_format($result['bytes'] / 1024, 1), $result['method'], $result['deleted']);
    app_log('info', 'backup: ' . $message);
    return $message;
});
