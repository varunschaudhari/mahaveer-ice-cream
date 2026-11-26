<?php

declare(strict_types=1);

use App\Db;

require dirname(__DIR__) . '/app/bootstrap.php';

// Point everything at the test database, never the development one.
$testDb = getenv('DB_NAME_TEST') ?: 'mahavir_test';
if (!preg_match('/^[A-Za-z0-9_]+_test$/', $testDb)) {
    fwrite(STDERR, "Refusing to run tests against '{$testDb}': the name must end in _test.\n");
    exit(1);
}
config_store(['DB_NAME' => $testDb] + config_store());
Db::reset();

// Create it if missing (local server; needs CREATE privilege). Tables are rebuilt per test class.
Db::connect(null)->exec(
    "CREATE DATABASE IF NOT EXISTS `{$testDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
);

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Tests\\')) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, 6)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
