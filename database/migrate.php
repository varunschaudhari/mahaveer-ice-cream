<?php

declare(strict_types=1);

// Usage (from the repo root):
//   php database/migrate.php            apply new migrations in order
//   php database/migrate.php --seed     also load seed.sql (refused if the menu already has data)
//   php database/migrate.php --fresh    drop all tables, migrate, seed (refused when APP_ENV=production)

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Db;
use App\Migrator;

$args = array_slice($argv, 1);
$unknown = array_diff($args, ['--seed', '--fresh']);
if ($unknown !== []) {
    fwrite(STDERR, 'Unknown option: ' . implode(' ', $unknown) . "\nUsage: php database/migrate.php [--seed] [--fresh]\n");
    exit(1);
}
$fresh = in_array('--fresh', $args, true);
$seed = $fresh || in_array('--seed', $args, true);

if ($fresh && app_env() === 'production') {
    fwrite(STDERR, "Refusing --fresh: APP_ENV is production.\n");
    exit(1);
}

try {
    $migrator = new Migrator(Db::pdo(), __DIR__);
    echo 'Database: ' . config('DB_NAME') . ' (' . app_env() . ")\n";

    if ($fresh) {
        echo 'Dropped ' . $migrator->dropAllTables() . " tables.\n";
    }

    $applied = $migrator->migrate(static fn (string $file) => print "Applied {$file}\n");
    if ($applied === []) {
        echo "No new migrations.\n";
    }

    if ($seed) {
        $migrator->seed();
        echo "Loaded seed.sql.\n";
    }
} catch (Throwable $e) {
    app_log('error', 'migrate.php: ' . $e->getMessage());
    fwrite(STDERR, 'FAILED: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "Done.\n";
