<?php

declare(strict_types=1);

namespace Tests;

use App\Db;
use App\Migrator;
use App\Settings;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Base for tests that need the database. The schema and seed data are rebuilt once per test
 * class, and every test runs inside a transaction that is rolled back afterwards.
 */
abstract class DatabaseTestCase extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $migrator = new Migrator(Db::pdo(), APP_ROOT . '/database');
        $migrator->dropAllTables();
        $migrator->migrate();
        $migrator->seed();
    }

    protected function setUp(): void
    {
        Settings::clearCache();
        Db::pdo()->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (Db::pdo()->inTransaction()) {
            Db::pdo()->rollBack();
        }
        Settings::clearCache();
    }

    protected function db(): PDO
    {
        return Db::pdo();
    }
}
