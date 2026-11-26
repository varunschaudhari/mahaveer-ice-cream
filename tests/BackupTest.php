<?php

declare(strict_types=1);

namespace Tests;

use App\Db;
use App\Migrator;
use App\Services\BackupService;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Nightly backup (BUILD_PLAN M9, ARCHITECTURE §13). The PHP dump (used when the host does not
 * allow mysqldump) is restored into the test database and must give back every row exactly.
 * Not a DatabaseTestCase: dropping and restoring tables cannot run inside a transaction.
 */
final class BackupTest extends TestCase
{
    private string $dir;

    public static function setUpBeforeClass(): void
    {
        $migrator = new Migrator(Db::pdo(), APP_ROOT . '/database');
        $migrator->dropAllTables();
        $migrator->migrate();
        $migrator->seed();
    }

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/mahavir-backup-test-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
    }

    /** @return array<string, string> table => checksum of all its rows */
    private static function checksums(PDO $db): array
    {
        $out = [];
        $tables = $db->query("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            $out[$table] = (string) $db->query("CHECKSUM TABLE `{$table}`")->fetch(PDO::FETCH_NUM)[1];
        }
        return $out;
    }

    public function testPhpDumpRestoresEveryRowExactly(): void
    {
        $db = Db::pdo();
        // Text that breaks naive dumps: quotes, backslashes, semicolons, line breaks, Marathi, NULLs.
        $db->prepare('INSERT INTO customers (mobile, name, order_count, last_order_at) VALUES (?, ?, 3, NULL), (?, ?, 1, ?)')
            ->execute(['9876500001', "O'Brien \"x\" \\ back; slash\nnew line", '9876500002', 'सुनीता पाटील', '2026-11-20 18:00:00']);
        $db->prepare("UPDATE site_content SET value_mr = ? WHERE content_key = 'about'")->execute(["पहिला; दुसरा\n\n-- not a comment\n/* nor this */ 'ok'"]);
        $before = self::checksums($db);

        $result = (new BackupService($db, $this->dir))->run(new DateTimeImmutable('2026-11-26 23:30'), 'php');
        self::assertSame('mahavir-2026-11-26-2330.sql.gz', $result['file']);
        self::assertSame('php', $result['method']);
        $sql = gzdecode((string) file_get_contents($this->dir . '/' . $result['file']));
        self::assertStringContainsString('SET NAMES utf8mb4', $sql);

        (new Migrator($db, APP_ROOT . '/database'))->dropAllTables();
        self::assertSame([], self::checksums($db));
        foreach (Migrator::splitSql($sql) as $statement) {
            $db->exec($statement);
        }

        self::assertSame($before, self::checksums($db), 'every table back, row for row');
        self::assertSame("O'Brien \"x\" \\ back; slash\nnew line", $db->query("SELECT name FROM customers WHERE mobile = '9876500001'")->fetchColumn());
        self::assertNull($db->query("SELECT last_order_at FROM customers WHERE mobile = '9876500001'")->fetchColumn());
        self::assertSame([], (new Migrator($db, APP_ROOT . '/database'))->pending(), 'the migrations table comes back too');
    }

    public function testOldBackupsAreDeletedAfterThirtyDays(): void
    {
        foreach (['mahavir-2026-10-20-2330.sql.gz', 'mahavir-2026-10-27-2330.sql.gz', 'mahavir-2026-11-25-2330.sql.gz', 'notes.txt'] as $name) {
            file_put_contents($this->dir . '/' . $name, 'x');
        }
        $backups = new BackupService(Db::pdo(), $this->dir);

        self::assertSame(1, $backups->prune(new DateTimeImmutable('2026-11-26 23:30')), 'only the one older than 30 days');
        self::assertSame(['mahavir-2026-11-25-2330.sql.gz', 'mahavir-2026-10-27-2330.sql.gz'], array_column($backups->list(), 'name'));
        self::assertFileExists($this->dir . '/notes.txt', 'other files are never touched');
    }

    public function testFirstBackupOnANewServerCreatesTheFolder(): void
    {
        $folder = $this->dir . '/backups';
        $result = (new BackupService(Db::pdo(), $folder))->run(new DateTimeImmutable('2026-11-26 23:30'), 'php');

        self::assertFileExists($folder . '/' . $result['file']);
        self::assertSame([], glob($folder . '/*.part') ?: [], 'the half-written .part file became the backup');
        self::assertGreaterThan(1000, $result['bytes']);
        array_map('unlink', glob($folder . '/*') ?: []);
        rmdir($folder);
    }
}
