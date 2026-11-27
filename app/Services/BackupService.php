<?php

declare(strict_types=1);

namespace App\Services;

use App\Db;
use DateTimeImmutable;
use PDO;
use RuntimeException;

/**
 * Nightly database backup (ARCHITECTURE §13): storage/backups/mahavir-YYYY-MM-DD-HHMM.sql.gz,
 * the last 30 days kept. Uses mysqldump when the host allows running it (the password goes in a
 * temporary options file, never on the command line); otherwise a PHP dump of every table.
 * Both restore with: gunzip -c FILE | mysql DBNAME (or phpMyAdmin → Import).
 */
final class BackupService
{
    public const KEEP_DAYS = 30;
    private const NAME = '/^mahavir-(\d{4}-\d{2}-\d{2})-\d{4}\.sql\.gz$/';
    private const ROWS_PER_INSERT = 200;

    private readonly PDO $db;
    private readonly string $dir;

    public function __construct(?PDO $db = null, ?string $dir = null)
    {
        $this->db = $db ?? Db::pdo();
        $this->dir = $dir ?? APP_ROOT . '/storage/backups';
    }

    /**
     * Makes today's backup, then deletes backups older than KEEP_DAYS (only after a good one).
     * $method: 'auto' (mysqldump if possible), 'php' (always the PHP dump).
     *
     * @return array{file: string, bytes: int, method: string, deleted: int}
     */
    public function run(DateTimeImmutable $now, string $method = 'auto'): array
    {
        if (!is_dir($this->dir) && !mkdir($this->dir, 0770, true) && !is_dir($this->dir)) {
            throw new RuntimeException("Cannot create {$this->dir}");
        }
        $file = $this->dir . '/mahavir-' . $now->format('Y-m-d-Hi') . '.sql.gz';
        $partial = $file . '.part';

        $dumper = $method === 'auto' ? $this->mysqldumpBinary() : null;
        try {
            if ($dumper !== null) {
                $this->dumpWithMysqldump($dumper, $partial);
                $used = 'mysqldump';
            } else {
                $gz = gzopen($partial, 'wb6');
                if ($gz === false) {
                    throw new RuntimeException("Cannot write {$partial}");
                }
                try {
                    $this->dumpWithPhp(static function (string $chunk) use ($gz): void {
                        gzwrite($gz, $chunk);
                    });
                } finally {
                    gzclose($gz);
                }
                $used = 'php';
            }
            if (!rename($partial, $file)) {
                throw new RuntimeException("Cannot rename {$partial}");
            }
        } catch (\Throwable $e) {
            @unlink($partial);
            throw $e;
        }

        return ['file' => basename($file), 'bytes' => (int) filesize($file), 'method' => $used, 'deleted' => $this->prune($now)];
    }

    /** @return list<array{name: string, bytes: int, date: string, mtime: int}> newest first */
    public function list(): array
    {
        $out = [];
        foreach (glob($this->dir . '/mahavir-*.sql.gz') ?: [] as $path) {
            if (preg_match(self::NAME, basename($path), $m)) {
                $out[] = ['name' => basename($path), 'bytes' => (int) filesize($path), 'date' => $m[1], 'mtime' => (int) filemtime($path)];
            }
        }
        usort($out, static fn (array $a, array $b): int => strcmp($b['name'], $a['name']));
        return $out;
    }

    /** Deletes backups whose date in the file name is more than KEEP_DAYS days before $now. */
    public function prune(DateTimeImmutable $now): int
    {
        $oldest = $now->setTime(0, 0)->modify('-' . self::KEEP_DAYS . ' days')->format('Y-m-d');
        $deleted = 0;
        foreach ($this->list() as $backup) {
            if ($backup['date'] < $oldest && unlink($this->dir . '/' . $backup['name'])) {
                $deleted++;
            }
        }
        return $deleted;
    }

    /**
     * Plain SQL that recreates every table with its rows (the fallback when mysqldump cannot run).
     * Reads inside one consistent snapshot when no transaction is open yet.
     *
     * @param callable(string): void $write receives the SQL in chunks
     */
    public function dumpWithPhp(callable $write): void
    {
        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        }
        try {
            $write("-- Mahavir Ice Cream backup (PHP dump) " . now()->format('Y-m-d H:i:s') . "\n"
                . "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nSET time_zone = '+05:30';\n\n");

            $tables = $this->db->query(
                "SELECT table_name FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' ORDER BY table_name"
            )->fetchAll(PDO::FETCH_COLUMN);

            foreach ($tables as $table) {
                $quoted = '`' . str_replace('`', '``', $table) . '`';
                $create = $this->db->query('SHOW CREATE TABLE ' . $quoted)->fetch(PDO::FETCH_NUM)[1];
                $write("DROP TABLE IF EXISTS {$quoted};\n{$create};\n");
                $this->dumpRows($table, $write);
                $write("\n");
            }
            $write("SET FOREIGN_KEY_CHECKS = 1;\n");
        } finally {
            $this->db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
            if ($ownTransaction) {
                $this->db->exec('COMMIT');
            }
        }
    }

    /**
     * The rows of one table as INSERT statements (200 rows each), all columns, in stored order.
     * Also used by ServerPackage to carry the setup accounts to a new server.
     *
     * @param callable(string): void $write
     */
    public function dumpRows(string $table, callable $write): void
    {
        $quoted = '`' . str_replace('`', '``', $table) . '`';
        // Unbuffered: rows stream from the server, so a big table never sits in memory at once.
        $this->db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        try {
            $stmt = $this->db->query('SELECT * FROM ' . $quoted);
            $columns = null;
            $batch = [];
            while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
                $columns ??= implode(', ', array_map(static fn (string $c): string => '`' . str_replace('`', '``', $c) . '`', array_keys($row)));
                $batch[] = '(' . implode(', ', array_map(fn (mixed $v): string => $v === null ? 'NULL' : $this->db->quote((string) $v), $row)) . ')';
                if (count($batch) === self::ROWS_PER_INSERT) {
                    $write("INSERT INTO {$quoted} ({$columns}) VALUES\n" . implode(",\n", $batch) . ";\n");
                    $batch = [];
                }
            }
            $stmt->closeCursor();
        } finally {
            $this->db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
        }
        if ($batch !== []) {
            $write("INSERT INTO {$quoted} ({$columns}) VALUES\n" . implode(",\n", $batch) . ";\n");
        }
    }

    /** Path of mysqldump if this host lets PHP run it, else null (then the PHP dump is used). */
    private function mysqldumpBinary(): ?string
    {
        if (!function_exists('proc_open') || in_array('proc_open', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true)) {
            return null;
        }
        $candidates = array_filter([(string) config('MYSQLDUMP_PATH', ''), '/usr/bin/mysqldump', '/usr/local/bin/mysqldump']);
        foreach ($candidates as $path) {
            if (@is_file($path) && @is_executable($path)) {
                return $path;
            }
        }
        return null;
    }

    private function dumpWithMysqldump(string $binary, string $target): void
    {
        // Credentials in a private temporary options file: a password on the command line would
        // be visible to other accounts on a shared server.
        $options = tempnam(sys_get_temp_dir(), 'mhv');
        if ($options === false) {
            throw new RuntimeException('Cannot create a temporary file for mysqldump');
        }
        chmod($options, 0600);
        file_put_contents($options, sprintf(
            "[client]\nuser=\"%s\"\npassword=\"%s\"\nhost=\"%s\"\n",
            addcslashes((string) config('DB_USER'), "\"\\"),
            addcslashes((string) config('DB_PASS'), "\"\\"),
            addcslashes((string) config('DB_HOST', '127.0.0.1'), "\"\\")
        ));

        $errorFile = $options . '.err';
        $gz = gzopen($target, 'wb6');
        try {
            if ($gz === false) {
                throw new RuntimeException("Cannot write {$target}");
            }
            // Errors go to a file, not a second pipe: a full, unread pipe could stall mysqldump.
            $process = proc_open(
                [$binary, '--defaults-extra-file=' . $options, '--single-transaction', '--quick', '--no-tablespaces',
                    '--default-character-set=utf8mb4', '--skip-lock-tables', (string) config('DB_NAME')],
                [1 => ['pipe', 'w'], 2 => ['file', $errorFile, 'w']],
                $pipes
            );
            if (!is_resource($process)) {
                throw new RuntimeException('Cannot start mysqldump');
            }
            while (!feof($pipes[1])) {
                $chunk = fread($pipes[1], 65536);
                if ($chunk !== false && $chunk !== '') {
                    gzwrite($gz, $chunk);
                }
            }
            fclose($pipes[1]);
            $exit = proc_close($process);
            $errors = trim((string) @file_get_contents($errorFile));
            if ($exit !== 0) {
                throw new RuntimeException('mysqldump failed (' . $exit . '): ' . mb_substr($errors, 0, 300));
            }
        } finally {
            if ($gz !== false) {
                gzclose($gz);
            }
            @unlink($options);
            @unlink($errorFile);
        }
    }
}
