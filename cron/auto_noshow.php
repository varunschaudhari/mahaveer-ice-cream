<?php

declare(strict_types=1);

// Every 5 minutes (cPanel cron, DEPLOYMENT.md §3):
//   */5 * * * *  /usr/local/bin/php /home/<user>/mahavir/cron/auto_noshow.php >/dev/null 2>&1
// Takeaway orders not collected noshow_after_mins after their slot ended become no-show; the
// customer's number is blocked at noshow_block_count no-shows (SPEC §3, §14).

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Cron;
use App\Services\OrderService;

Cron::run('auto_noshow', static function (): string {
    $count = (new OrderService())->markNoShows();
    if ($count > 0) {
        app_log('info', "auto_noshow: {$count} order(s) marked no-show");
    }
    return "{$count} order(s) marked no-show";
});
