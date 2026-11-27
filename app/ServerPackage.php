<?php

declare(strict_types=1);

namespace App;

use App\Services\BackupService;
use PDO;

/**
 * Setting up a server without shell access (database/prepare_server.php, run on the developer's
 * PC): the accounts are created in a temporary local database with the usual checks, then
 * written out as one SQL file for phpMyAdmin → Import, plus the server's config.php.
 *
 * The SQL file holds the migration files and seed.sql exactly as in the repo (portable between
 * MySQL 8 and MariaDB, unlike a dump of the local server's table definitions), the
 * schema_migrations rows, and the staff + audit_log rows of the build database (password and
 * PIN hashes only, never the plain values).
 */
final class ServerPackage
{
    private const DATA_TABLES = ['staff', 'audit_log'];

    public function __construct(private readonly PDO $db)
    {
    }

    /** Empties the build database and loads the schema and the menu, ready for the accounts. */
    public function prepare(): void
    {
        $migrator = new Migrator($this->db, APP_ROOT . '/database');
        $migrator->dropAllTables();
        $migrator->migrate();
        $migrator->seed();
    }

    /** The SQL to import into a new, empty server database. */
    public function sql(): string
    {
        $dir = APP_ROOT . '/database';
        $migrations = (new Migrator($this->db, $dir))->files();

        $out = "-- Mahavir Ice Cream – database for a new server, made " . now()->format('Y-m-d H:i') . "\n"
            . "-- Import into an EMPTY database (phpMyAdmin → Import). Holds password hashes, not passwords.\n\n"
            . "SET NAMES utf8mb4;\nSET time_zone = '+05:30';\n\n";
        foreach ($migrations as $file) {
            $out .= "-- ===== {$file} =====\n" . rtrim((string) file_get_contents("{$dir}/migrations/{$file}")) . "\n\n";
        }
        $out .= "-- ===== seed.sql =====\n" . rtrim((string) file_get_contents("{$dir}/seed.sql")) . "\n\n";

        $out .= "-- ===== applied migrations =====\n" . Migrator::MIGRATIONS_TABLE_SQL . ";\n"
            . 'INSERT INTO schema_migrations (filename) VALUES '
            . implode(', ', array_map(fn (string $f): string => '(' . $this->db->quote($f) . ')', $migrations)) . ";\n\n";

        $out .= "-- ===== accounts =====\n";
        $backup = new BackupService($this->db);
        foreach (self::DATA_TABLES as $table) {
            $backup->dumpRows($table, static function (string $chunk) use (&$out): void {
                $out .= $chunk;
            });
        }
        return $out;
    }

    /**
     * The server's app/config.php.
     *
     * @param array{label: string, APP_ENV: string, APP_URL_ORDER: string, APP_URL_WEBSITE: string, DB_HOST: string,
     *              DB_NAME: string, DB_USER: string, DB_PASS: string, VAPID_PUBLIC: string, VAPID_PRIVATE: string,
     *              VAPID_SUBJECT: string} $values
     */
    public static function config(array $values): string
    {
        $line = static fn (string $key): string => sprintf("    %-17s => %s,\n", var_export($key, true), var_export((string) $values[$key], true));
        return "<?php\n\ndeclare(strict_types=1);\n\n"
            . "// Server configuration \"{$values['label']}\", made by database/prepare_server.php on " . now()->format('Y-m-d') . ".\n"
            . "// Upload as app/config.php on that server only; never commit it. Keep production's VAPID keys forever.\n\n"
            . "return [\n"
            . $line('APP_ENV') . $line('APP_URL_ORDER') . $line('APP_URL_WEBSITE') . "\n"
            . $line('DB_HOST') . $line('DB_NAME') . $line('DB_USER') . $line('DB_PASS') . "\n"
            . "    // Web Push (M6): this server's own key pair.\n"
            . $line('VAPID_PUBLIC') . $line('VAPID_PRIVATE') . $line('VAPID_SUBJECT') . "\n"
            . "    'SESSION_NAME'      => 'mahavir_sid',\n"
            . "];\n";
    }
}
