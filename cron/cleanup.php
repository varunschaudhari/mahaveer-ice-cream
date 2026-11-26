<?php

declare(strict_types=1);

// Weekly housekeeping (cPanel cron, DEPLOYMENT.md §3):
//   0 4 * * 0  /usr/local/bin/php /home/<user>/mahavir/cron/cleanup.php >/dev/null 2>&1
// Old logs (90 days), rate-limit rows (1 day), expired sessions, unused photo files (CleanupService).

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Cron;
use App\Services\CleanupService;

Cron::run('cleanup', static function (): string {
    $deleted = (new CleanupService())->run(now());
    $message = sprintf('deleted: %d log file(s), %d rate-limit row(s), %d session(s), %d unused photo(s)',
        $deleted['logs'], $deleted['rate_limits'], $deleted['sessions'], $deleted['photos']);
    app_log('info', 'cleanup: ' . $message);
    return $message;
});
