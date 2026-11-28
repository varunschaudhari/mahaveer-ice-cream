<?php

declare(strict_types=1);

namespace Tests;

use App\Migrator;
use PDO;

/** Migrations + seed build the expected database, and connections use the agreed session settings. */
final class DatabaseSetupTest extends DatabaseTestCase
{
    public function testAllSchemaTablesExist(): void
    {
        $tables = $this->db()->query(
            "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()"
        )->fetchAll(PDO::FETCH_COLUMN);
        sort($tables, SORT_STRING);

        self::assertSame([
            'audit_log', 'categories', 'customers', 'daily_tokens', 'dining_tables', 'item_variants',
            'items', 'order_items', 'orders', 'payments', 'print_jobs', 'push_subscriptions', 'rate_limits',
            'schema_migrations', 'sequences', 'settings', 'site_content', 'site_photos', 'staff',
            'table_alerts', 'table_sessions',
        ], $tables);
    }

    public function testMigrationIsRecordedAndNothingIsPending(): void
    {
        $migrator = new Migrator($this->db(), APP_ROOT . '/database');
        self::assertSame([], $migrator->pending());
        self::assertSame(
            ['001_schema.sql', '002_report_indexes.sql', '003_print_jobs.sql'],
            $this->db()->query('SELECT filename FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN)
        );
    }

    public function testSeedCounts(): void
    {
        $count = fn (string $table): int => (int) $this->db()->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();

        self::assertSame(5, $count('categories'));
        self::assertSame(32, $count('items'));
        self::assertSame(56, $count('item_variants'));
        self::assertSame(20, $count('settings'));
        self::assertSame(7, $count('dining_tables'));
        self::assertSame(0, $count('staff'), 'accounts are created by create_owner.php, never seeded');
    }

    public function testBillSequenceStartsAtZero(): void
    {
        self::assertSame(0, (int) $this->db()->query("SELECT last_value FROM sequences WHERE name = 'bill_no'")->fetchColumn());
    }

    public function testTableQrTokensAreUnique32CharHex(): void
    {
        $tokens = $this->db()->query('SELECT qr_token FROM dining_tables')->fetchAll(PDO::FETCH_COLUMN);
        self::assertCount(7, array_unique($tokens));
        foreach ($tokens as $token) {
            self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $token);
        }
    }

    public function testMarathiTextRoundTrips(): void
    {
        self::assertSame(
            'केशरी ड्रायफ्रुट श्रीखंड',
            $this->db()->query('SELECT name_mr FROM items WHERE id = 32')->fetchColumn()
        );
    }

    public function testSessionUsesIndianTimeZoneAndStrictMode(): void
    {
        $row = $this->db()->query('SELECT @@session.time_zone AS tz, @@session.sql_mode AS mode')->fetch();
        self::assertSame('+05:30', $row['tz']);
        self::assertStringContainsString('STRICT_TRANS_TABLES', $row['mode']);
        self::assertStringContainsString('ONLY_FULL_GROUP_BY', $row['mode']);
    }

    public function testDecimalColumnsComeBackAsStrings(): void
    {
        // Money helpers rely on PDO returning DECIMAL as a string, never a float.
        $price = $this->db()->query('SELECT price FROM item_variants WHERE item_id = 1 ORDER BY sort_order LIMIT 1')->fetchColumn();
        self::assertSame('15.00', $price);
    }
}
