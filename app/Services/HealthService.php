<?php

declare(strict_types=1);

namespace App\Services;

use App\Cron;
use App\Db;
use App\Migrator;
use DateTimeImmutable;
use PDO;

/**
 * admin → System health (super-admin only, developer decision M9): are the crons running, is
 * there a recent backup, errors in today's log, and the server basics. Each check is
 * ok / warn / fail with a short detail; nothing here changes anything.
 */
final class HealthService
{
    /** cron name => seconds after which its last run counts as late */
    public const CRONS = ['auto_noshow' => 15 * 60, 'backup' => 26 * 3600, 'cleanup' => 8 * 86400];
    /** Every check, in display order (labels: health.check.<key>). */
    public const CHECK_KEYS = [
        'cron_auto_noshow', 'cron_backup', 'cron_cleanup', 'backup_file', 'errors_today', 'migrations',
        'php', 'database', 'environment', 'https', 'display_errors', 'push', 'disk', 'storage',
    ];
    private const LOW_DISK_BYTES = 500 * 1024 * 1024;

    private readonly PDO $db;
    private readonly string $storage;

    public function __construct(?PDO $db = null, ?string $storage = null)
    {
        $this->db = $db ?? Db::pdo();
        $this->storage = $storage ?? APP_ROOT . '/storage';
    }

    /** @return list<array{key: string, status: string, detail: string}> status: ok | warn | fail */
    public function checks(DateTimeImmutable $now, bool $https): array
    {
        $checks = [];
        $add = static function (string $key, string $status, string $detail) use (&$checks): void {
            $checks[] = ['key' => $key, 'status' => $status, 'detail' => $detail];
        };
        $production = app_env() === 'production';

        foreach (self::CRONS as $name => $maxAge) {
            $last = Cron::lastRun($name);
            if ($last === null) {
                $add('cron_' . $name, 'fail', t('health.never_ran'));
                continue;
            }
            $age = $now->getTimestamp() - strtotime($last['at']);
            $status = !$last['ok'] ? 'fail' : ($age > $maxAge ? 'warn' : 'ok');
            $add('cron_' . $name, $status, format_date_time($last['at']) . ' · ' . $last['message']);
        }

        $backups = (new BackupService($this->db, $this->storage . '/backups'))->list();
        if ($backups === []) {
            $add('backup_file', 'fail', t('health.no_backup'));
        } else {
            $age = $now->getTimestamp() - $backups[0]['mtime'];
            $add('backup_file', $age > self::CRONS['backup'] ? 'warn' : 'ok', t('health.backup_detail', [
                'name' => $backups[0]['name'],
                'size' => number_format($backups[0]['bytes'] / 1024, 1),
                'count' => count($backups),
            ]));
        }

        $log = $this->todaysProblems($now);
        $add('errors_today', $log['errors'] > 0 ? 'fail' : ($log['warnings'] > 0 ? 'warn' : 'ok'),
            t('health.log_detail', ['errors' => $log['errors'], 'warnings' => $log['warnings']]));

        $pending = (new Migrator($this->db, APP_ROOT . '/database'))->pending();
        $add('migrations', $pending === [] ? 'ok' : 'fail', $pending === [] ? t('health.up_to_date') : implode(', ', $pending));

        $add('php', version_compare(PHP_VERSION, '8.2.0', '>=') ? 'ok' : 'fail', 'PHP ' . PHP_VERSION);
        $add('database', 'ok', (string) $this->db->query('SELECT VERSION()')->fetchColumn());
        $add('environment', $production ? 'ok' : 'warn', (string) app_env());
        $add('https', $https ? 'ok' : ($production ? 'fail' : 'warn'), $https ? 'HTTPS' : 'HTTP');
        $add('display_errors', in_array(strtolower((string) ini_get('display_errors')), ['', '0', 'off', 'stderr'], true) ? 'ok' : 'fail', (string) ini_get('display_errors'));
        $add('push', PushService::isConfigured() ? 'ok' : 'warn', t('health.push_detail', [
            'n' => (int) $this->db->query('SELECT COUNT(*) FROM push_subscriptions')->fetchColumn(),
        ]));

        $free = @disk_free_space($this->storage);
        $add('disk', $free === false ? 'warn' : ($free < self::LOW_DISK_BYTES ? 'warn' : 'ok'),
            $free === false ? '?' : t('health.disk_detail', ['mb' => number_format($free / 1048576)]));

        $notWritable = array_values(array_filter(['logs', 'uploads', 'backups', 'sessions', 'cron'],
            fn (string $d): bool => is_dir($this->storage . '/' . $d) && !is_writable($this->storage . '/' . $d)));
        $add('storage', $notWritable === [] ? 'ok' : 'fail', $notWritable === [] ? t('health.writable') : implode(', ', $notWritable));

        return $checks;
    }

    /**
     * Today's log: how many errors and warnings, and the latest problem lines (newest last).
     *
     * @return array{errors: int, warnings: int, lines: list<string>}
     */
    public function todaysProblems(DateTimeImmutable $now, int $lines = 15): array
    {
        $file = $this->storage . '/logs/app-' . $now->format('Y-m-d') . '.log';
        $result = ['errors' => 0, 'warnings' => 0, 'lines' => []];
        if (!is_file($file)) {
            return $result;
        }
        $handle = fopen($file, 'r');
        while ($handle !== false && ($line = fgets($handle)) !== false) {
            if (preg_match('/^\[[^\]]+\] (ERROR|CRITICAL):/', $line)) {
                $result['errors']++;
            } elseif (preg_match('/^\[[^\]]+\] WARNING:/', $line)) {
                $result['warnings']++;
            } else {
                continue;
            }
            $result['lines'][] = mb_substr(rtrim($line), 0, 300);
            if (count($result['lines']) > $lines) {
                array_shift($result['lines']);
            }
        }
        if ($handle !== false) {
            fclose($handle);
        }
        return $result;
    }
}
