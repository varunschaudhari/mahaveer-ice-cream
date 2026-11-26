<?php

declare(strict_types=1);

namespace App;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Applies database/migrations/*.sql in file-name order and records each in schema_migrations.
 * Used by database/migrate.php and by the test suite to build mahavir_test.
 */
final class Migrator
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $databaseDir,
    ) {
    }

    /** @return list<string> file names applied by this call */
    public function migrate(?callable $onApply = null): array
    {
        $this->ensureMigrationsTable();
        $applied = [];

        foreach ($this->pending() as $file) {
            // MySQL commits DDL implicitly, so a failed file cannot be rolled back: we stop at
            // the first error and leave it unrecorded. Locally, fix it and run --fresh.
            $this->runFile($this->databaseDir . '/migrations/' . $file);
            $this->pdo->prepare('INSERT INTO schema_migrations (filename) VALUES (?)')->execute([$file]);
            $applied[] = $file;
            if ($onApply !== null) {
                $onApply($file);
            }
        }
        return $applied;
    }

    /** @return list<string> */
    public function pending(): array
    {
        $this->ensureMigrationsTable();
        $done = $this->pdo->query('SELECT filename FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);

        $files = array_map('basename', glob($this->databaseDir . '/migrations/*.sql') ?: []);
        sort($files, SORT_STRING);
        return array_values(array_diff($files, $done));
    }

    /** Loads seed.sql in one transaction. Refuses if the menu already has data. */
    public function seed(): void
    {
        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn();
        if ($count > 0) {
            throw new RuntimeException('Seed refused: categories already has data. Use --fresh on a local database.');
        }

        $this->pdo->beginTransaction();
        try {
            $this->runFile($this->databaseDir . '/seed.sql');
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Drops every table in the current database. Used by --fresh instead of DROP DATABASE,
     * because cPanel database users are not allowed to create databases.
     *
     * @return int number of tables dropped
     */
    public function dropAllTables(): int
    {
        $tables = $this->pdo->query(
            "SELECT table_name AS t FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'"
        )->fetchAll(PDO::FETCH_COLUMN);

        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ($tables as $table) {
                $this->pdo->exec('DROP TABLE `' . str_replace('`', '``', $table) . '`');
            }
        } finally {
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
        return count($tables);
    }

    private function ensureMigrationsTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
               filename   VARCHAR(190) NOT NULL PRIMARY KEY,
               applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    private function runFile(string $path): void
    {
        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new RuntimeException("Cannot read {$path}");
        }

        foreach (self::splitSql($sql) as $i => $statement) {
            try {
                $this->pdo->exec($statement);
            } catch (Throwable $e) {
                $preview = mb_substr(preg_replace('/\s+/', ' ', $statement), 0, 120);
                throw new RuntimeException(
                    basename($path) . ' statement #' . ($i + 1) . " failed: {$e->getMessage()}\n  SQL: {$preview}",
                    0,
                    $e
                );
            }
        }
    }

    /**
     * Splits a SQL script into statements on `;`, ignoring semicolons inside quotes and comments.
     * Comments are dropped. Enough for our hand-written migration files (no DELIMITER blocks).
     *
     * @return list<string>
     */
    public static function splitSql(string $sql): array
    {
        $statements = [];
        $current = '';
        $length = strlen($sql);
        $quote = null;

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $sql[$i + 1] ?? '';

            if ($quote !== null) {
                $current .= $char;
                if ($char === '\\' && $quote !== '`') {
                    $current .= $next;
                    $i++;
                } elseif ($char === $quote) {
                    if ($next === $quote) { // doubled quote = escaped quote
                        $current .= $next;
                        $i++;
                    } else {
                        $quote = null;
                    }
                }
                continue;
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                $current .= $char;
            } elseif (($char === '-' && $next === '-' && preg_match('/\s/', $sql[$i + 2] ?? ' ')) || $char === '#') {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $length : $end;
                $current .= "\n";
            } elseif ($char === '/' && $next === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $length : $end + 1;
                $current .= ' ';
            } elseif ($char === ';') {
                if (trim($current) !== '') {
                    $statements[] = trim($current);
                }
                $current = '';
            } else {
                $current .= $char;
            }
        }

        if (trim($current) !== '') {
            $statements[] = trim($current);
        }
        return $statements;
    }
}
