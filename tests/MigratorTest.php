<?php

declare(strict_types=1);

namespace Tests;

use App\Migrator;
use PHPUnit\Framework\TestCase;

/** The SQL splitter used by migrate.php (pure, no database). */
final class MigratorTest extends TestCase
{
    public function testSplitsOnSemicolons(): void
    {
        self::assertSame(
            ['CREATE TABLE a (id INT)', 'INSERT INTO a VALUES (1)'],
            Migrator::splitSql("CREATE TABLE a (id INT);\nINSERT INTO a VALUES (1);\n")
        );
    }

    public function testKeepsSemicolonsInsideQuotes(): void
    {
        self::assertSame(
            ["INSERT INTO t VALUES ('a;b', \"c;d\")", 'SELECT `we;ird` FROM t'],
            Migrator::splitSql("INSERT INTO t VALUES ('a;b', \"c;d\"); SELECT `we;ird` FROM t;")
        );
    }

    public function testHandlesEscapedQuotes(): void
    {
        self::assertSame(
            ["INSERT INTO t VALUES ('it''s; fine')", "INSERT INTO t VALUES ('a\\'b;c')"],
            Migrator::splitSql("INSERT INTO t VALUES ('it''s; fine');\nINSERT INTO t VALUES ('a\\'b;c');")
        );
    }

    public function testDropsComments(): void
    {
        $sql = "-- header; with a semicolon\n"
            . "CREATE TABLE a (\n  id INT -- inline; comment\n);\n"
            . "/* block; comment */\n"
            . "# hash; comment\n"
            . 'SELECT 1';
        $statements = Migrator::splitSql($sql);

        self::assertCount(2, $statements);
        self::assertStringNotContainsString('comment', $statements[0]);
        self::assertSame('SELECT 1', $statements[1]);
    }

    public function testDoubleDashWithoutSpaceIsNotAComment(): void
    {
        self::assertSame(['SELECT 5--1'], Migrator::splitSql('SELECT 5--1;'));
    }

    public function testSchemaAndSeedFilesSplitIntoExpectedStatements(): void
    {
        $schema = Migrator::splitSql(file_get_contents(APP_ROOT . '/database/migrations/001_schema.sql'));
        self::assertCount(20, $schema); // SET NAMES + 19 CREATE TABLE
        self::assertCount(19, array_filter($schema, static fn ($s) => str_starts_with($s, 'CREATE TABLE')));

        $seed = Migrator::splitSql(file_get_contents(APP_ROOT . '/database/seed.sql'));
        self::assertCount(8, $seed); // SET NAMES + 7 INSERT
    }
}
