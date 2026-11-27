<?php

declare(strict_types=1);

namespace Tests;

use App\Db;
use App\Migrator;
use App\ServerPackage;
use App\Services\StaffService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Servers without shell access (database/prepare_server.php): the SQL file must build the full
 * database with working logins when imported into an empty database, and config.php must be
 * valid PHP with the values exactly as typed. Not a DatabaseTestCase: it drops and reloads tables.
 */
final class ServerPackageTest extends TestCase
{
    public static function tearDownAfterClass(): void
    {
        // Leave the test database as the other test classes expect it.
        $migrator = new Migrator(Db::pdo(), APP_ROOT . '/database');
        $migrator->dropAllTables();
        $migrator->migrate();
        $migrator->seed();
    }

    public function testSqlFileBuildsTheWholeDatabaseWithWorkingLogins(): void
    {
        $db = Db::pdo();
        $package = new ServerPackage($db);
        $package->prepare();
        $staff = new StaffService($db);
        $system = ['id' => null, 'role' => 'super'];
        $staff->create(['role' => 'owner', 'name' => 'Mahavir Owner', 'username' => 'mahavir', 'password' => "pa'ss\\word-1", 'pin' => '1234'], $system);
        $staff->create(['role' => 'super', 'name' => 'Developer', 'username' => 'dev', 'password' => 'dev-pass-123', 'pin' => ''], $system);
        $staff->create(['role' => 'staff', 'name' => 'रमेश', 'pin' => '2222'], $system);
        $sql = $package->sql();

        self::assertStringNotContainsString("pa'ss", $sql, 'never the plain password');
        self::assertStringContainsString('-- ===== 002_report_indexes.sql =====', $sql);

        // A brand-new, empty server database.
        (new Migrator($db, APP_ROOT . '/database'))->dropAllTables();
        foreach (Migrator::splitSql($sql) as $statement) {
            $db->exec($statement);
        }

        $count = static fn (string $table): int => (int) $db->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
        self::assertSame(32, $count('items'), 'the menu from seed.sql');
        self::assertSame(20, $count('settings'));
        self::assertSame(7, $count('dining_tables'));
        self::assertSame([], (new Migrator($db, APP_ROOT . '/database'))->pending(), 'migrate.php will not try to apply them again');

        $rows = $db->query('SELECT name, role, username, password_hash, pin_hash FROM staff ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        self::assertSame(['owner', 'super', 'staff'], array_column($rows, 'role'));
        self::assertSame('रमेश', $rows[2]['name']);
        self::assertTrue(password_verify("pa'ss\\word-1", $rows[0]['password_hash']), 'the owner can log in');
        self::assertTrue(password_verify('1234', $rows[0]['pin_hash']));
        self::assertTrue(password_verify('2222', $rows[2]['pin_hash']));
        self::assertNull($rows[1]['pin_hash']);
        self::assertSame(3, $count("audit_log WHERE action = 'staff_create'"), 'the setup is in the change log');

        // New accounts made later on the server continue the ids.
        $staff->create(['role' => 'staff', 'name' => 'Sita', 'pin' => '3333'], $system);
        self::assertSame(4, (int) $db->query("SELECT id FROM staff WHERE name = 'Sita'")->fetchColumn());
    }

    public function testConfigFileKeepsEveryValueExactly(): void
    {
        $values = [
            'label' => 'staging',
            'APP_ENV' => 'staging',
            'APP_URL_ORDER' => 'https://mahaveer.foreverstory.in',
            'APP_URL_WEBSITE' => 'https://mahaveer-site.foreverstory.in',
            'DB_HOST' => 'localhost',
            'DB_NAME' => 'cpuser_mahavir_stg',
            'DB_USER' => 'cpuser_mahavir_stg',
            'DB_PASS' => "it's \\ \"tricky\" \$x",
            'VAPID_PUBLIC' => 'BPub',
            'VAPID_PRIVATE' => 'priv',
            'VAPID_SUBJECT' => 'https://mahaveer-site.foreverstory.in',
        ];
        $file = sys_get_temp_dir() . '/mahavir-config-test-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($file, ServerPackage::config($values));
        try {
            $config = require $file;
        } finally {
            unlink($file);
        }

        unset($values['label']);
        self::assertSame($values + ['SESSION_NAME' => 'mahavir_sid'], $config);
    }
}
