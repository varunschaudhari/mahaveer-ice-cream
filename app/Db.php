<?php

declare(strict_types=1);

namespace App;

use PDO;
use Throwable;

final class Db
{
    /**
     * MySQL 8's default mode, set explicitly so local MariaDB and the cPanel server reject the
     * same bad data and the same loose GROUP BY queries.
     */
    private const SQL_MODE = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,'
        . 'ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        return self::$pdo ??= self::connect((string) config('DB_NAME'));
    }

    /** A new connection; $dbName null connects to the server without selecting a database. */
    public static function connect(?string $dbName): PDO
    {
        $dsn = 'mysql:host=' . config('DB_HOST', '127.0.0.1') . ';charset=utf8mb4';
        if ($dbName !== null) {
            $dsn .= ';dbname=' . $dbName;
        }

        $pdo = new PDO($dsn, (string) config('DB_USER'), (string) config('DB_PASS'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("SET time_zone = '+05:30'");
        $pdo->exec("SET SESSION sql_mode = '" . self::SQL_MODE . "'");

        return $pdo;
    }

    /**
     * Runs $fn inside a transaction and returns its result. Rolls back and rethrows on any error.
     * If a transaction is already open, $fn joins it.
     *
     * @template T
     * @param callable(PDO): T $fn
     * @return T
     */
    public static function transaction(callable $fn): mixed
    {
        $pdo = self::pdo();
        if ($pdo->inTransaction()) {
            return $fn($pdo);
        }

        $pdo->beginTransaction();
        try {
            $result = $fn($pdo);
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Drops the shared connection (tests, or after switching DB_NAME). */
    public static function reset(): void
    {
        self::$pdo = null;
    }
}
