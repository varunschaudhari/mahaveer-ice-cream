<?php

declare(strict_types=1);

namespace Tests;

use App\Cron;
use App\Services\CleanupService;
use App\Services\HealthService;
use DateTimeImmutable;
use RuntimeException;

/** Cron runner, weekly cleanup and the System health checks (BUILD_PLAN M9). */
final class CronAndCleanupTest extends DatabaseTestCase
{
    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir() . '/mahavir-storage-test-' . bin2hex(random_bytes(4));
        foreach (['logs', 'sessions', 'uploads', 'backups', 'cron'] as $dir) {
            mkdir($this->storage . '/' . $dir, 0777, true);
        }
        Cron::useDir($this->storage . '/cron');
    }

    protected function tearDown(): void
    {
        Cron::useDir(null);
        foreach (glob($this->storage . '/*/*') ?: [] as $file) {
            unlink($file);
        }
        foreach (glob($this->storage . '/*') ?: [] as $dir) {
            rmdir($dir);
        }
        rmdir($this->storage);
        parent::tearDown();
    }

    private function file(string $path, int $ageSeconds = 0): string
    {
        $full = $this->storage . '/' . $path;
        file_put_contents($full, 'x');
        touch($full, time() - $ageSeconds);
        return $full;
    }

    // ---------------------------------------------------------------
    // Cron runner
    // ---------------------------------------------------------------

    public function testCronRecordsItsLastRun(): void
    {
        ob_start();
        $exit = Cron::runJob('test_job', static fn (): string => '3 things done');
        ob_end_clean();

        self::assertSame(0, $exit);
        $last = Cron::lastRun('test_job');
        self::assertTrue($last['ok']);
        self::assertSame('3 things done', $last['message']);
    }

    public function testAFailingCronIsRecordedAndExitsWithOne(): void
    {
        $exit = @Cron::runJob('test_job', static function (): string {
            throw new RuntimeException('disk full');
        });

        self::assertSame(1, $exit);
        self::assertFalse(Cron::lastRun('test_job')['ok']);
        self::assertSame('disk full', Cron::lastRun('test_job')['message']);
    }

    public function testACronStillRunningIsNotStartedTwice(): void
    {
        $lock = fopen($this->storage . '/cron/test_job.lock', 'c');
        flock($lock, LOCK_EX);
        $ran = false;
        try {
            $exit = Cron::runJob('test_job', static function () use (&$ran): string {
                $ran = true;
                return '';
            });
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        self::assertSame(0, $exit);
        self::assertFalse($ran);
        self::assertNull(Cron::lastRun('test_job'));
    }

    // ---------------------------------------------------------------
    // Weekly cleanup
    // ---------------------------------------------------------------

    public function testCleanupDeletesOnlyWhatIsOld(): void
    {
        // File ages come from the real clock (touch), so "now" must be the real time too.
        $now = new DateTimeImmutable();
        $oldLog = $this->file('logs/app-' . $now->modify('-91 days')->format('Y-m-d') . '.log');
        $keptLog = $this->file('logs/app-' . $now->modify('-89 days')->format('Y-m-d') . '.log');
        $oldSession = $this->file('sessions/sess_old', 32 * 86400);
        $liveSession = $this->file('sessions/sess_live', 3 * 86400);

        $used = str_repeat('a', 32) . '.webp';
        $orphanOld = $this->file('uploads/' . str_repeat('b', 32) . '.webp', 8 * 86400);
        $orphanNew = $this->file('uploads/' . str_repeat('c', 32) . '.webp', 3600);
        $usedFile = $this->file('uploads/' . $used, 30 * 86400);
        $stranger = $this->file('uploads/readme.webp', 30 * 86400);
        $this->db()->exec("UPDATE items SET photo = '{$used}' WHERE id = 1");

        $this->db()->prepare("INSERT INTO rate_limits (rl_key, hit_at) VALUES ('a', ?), ('b', ?)")->execute([
            $now->modify('-2 days')->format('Y-m-d H:i:s'),
            $now->modify('-30 minutes')->format('Y-m-d H:i:s'),
        ]);

        $deleted = (new CleanupService($this->db(), $this->storage))->run($now);

        self::assertSame(['logs' => 1, 'rate_limits' => 1, 'sessions' => 1, 'photos' => 1, 'print_jobs' => 0], $deleted);
        self::assertFileDoesNotExist($oldLog);
        self::assertFileExists($keptLog, '90 days are kept');
        self::assertFileDoesNotExist($oldSession);
        self::assertFileExists($liveSession);
        self::assertFileDoesNotExist($orphanOld);
        self::assertFileExists($orphanNew, 'a photo being saved right now is never touched');
        self::assertFileExists($usedFile, 'a menu photo in use stays');
        self::assertFileExists($stranger, 'only names ImageService makes');
        self::assertSame(['b'], $this->db()->query('SELECT rl_key FROM rate_limits')->fetchAll(\PDO::FETCH_COLUMN));
    }

    // ---------------------------------------------------------------
    // System health
    // ---------------------------------------------------------------

    private function statusOf(array $checks, string $key): string
    {
        return array_column($checks, 'status', 'key')[$key];
    }

    public function testHealthChecks(): void
    {
        $now = new DateTimeImmutable();
        $health = new HealthService($this->db(), $this->storage);

        $checks = $health->checks($now, false);
        self::assertSame(HealthService::CHECK_KEYS, array_column($checks, 'key'));
        self::assertSame('fail', $this->statusOf($checks, 'cron_backup'), 'never ran: the cron is not set up');
        self::assertSame('fail', $this->statusOf($checks, 'backup_file'));
        self::assertSame('ok', $this->statusOf($checks, 'errors_today'));
        self::assertSame('ok', $this->statusOf($checks, 'migrations'));
        self::assertSame('ok', $this->statusOf($checks, 'storage'));

        $write = function (string $name, int $ageSeconds, bool $ok) use ($now): void {
            file_put_contents($this->storage . "/cron/{$name}.json", json_encode([
                'at' => $now->modify("-{$ageSeconds} seconds")->format('Y-m-d H:i:s'), 'ok' => $ok, 'message' => 'm', 'seconds' => 1,
            ]));
        };
        $write('auto_noshow', 60, true);
        $write('backup', 30 * 3600, true);
        $write('cleanup', 3600, false);
        $this->file('backups/mahavir-' . $now->format('Y-m-d-Hi') . '.sql.gz');
        file_put_contents($this->storage . '/logs/app-' . $now->format('Y-m-d') . '.log',
            "[x] WARNING: Login failed\n[x] INFO: backup done\n[x] ERROR: TypeError in items.php\n");

        $checks = $health->checks($now, false);
        self::assertSame('ok', $this->statusOf($checks, 'cron_auto_noshow'));
        self::assertSame('warn', $this->statusOf($checks, 'cron_backup'), 'late: more than 26 hours ago');
        self::assertSame('fail', $this->statusOf($checks, 'cron_cleanup'), 'the last run failed');
        self::assertSame('ok', $this->statusOf($checks, 'backup_file'));
        self::assertSame('fail', $this->statusOf($checks, 'errors_today'));

        $log = $health->todaysProblems($now);
        self::assertSame([1, 1], [$log['errors'], $log['warnings']]);
        self::assertSame(['[x] WARNING: Login failed', '[x] ERROR: TypeError in items.php'], $log['lines']);
    }
}
