<?php

declare(strict_types=1);

// Prepares a NEW server that has no shell access (run on your PC, DEPLOYMENT.md "No shell access").
//
//   Git Bash:  OPENSSL_CONF='C:\xampp\php\extras\ssl\openssl.cnf' /c/xampp/php/php.exe database/prepare_server.php
//
// Asks for the server's URLs and database login, makes its own Web Push key pair, and asks for the
// owner / super-admin / staff accounts (checked exactly like create_owner.php) in a temporary local
// database. Writes two files to storage/deploy/<name>/ (gitignored):
//   config.php    → upload as app/config.php on that server
//   database.sql  → phpMyAdmin → that server's EMPTY database → Import
// Both contain secrets (database password, push private key): delete them from your PC once uploaded.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';
require __DIR__ . '/cli_prompts.php';

use App\Db;
use App\ServerPackage;
use App\Services\StaffService;
use Minishlink\WebPush\VAPID;

App\Lang::set('en');

echo "Mahavir Ice Cream – prepare a server without shell access\n";
echo "Your local MySQL (XAMPP) must be running; a temporary database is made and removed again.\n";
if (PHP_OS_FAMILY === 'Windows') {
    echo "Note: passwords and PINs are visible while you type on Windows.\n";
}
echo "\n";

// Push keys first: on Windows they need OPENSSL_CONF, so fail before any questions.
try {
    $keys = VAPID::createVapidKeys();
} catch (Throwable $e) {
    fwrite(STDERR, 'Could not create the push keys: ' . $e->getMessage() . "\n"
        . "Start the command with OPENSSL_CONF set, e.g. in Git Bash:\n"
        . "  OPENSSL_CONF='C:\\xampp\\php\\extras\\ssl\\openssl.cnf' /c/xampp/php/php.exe database/prepare_server.php\n");
    exit(1);
}

$askUrl = static function (string $label): string {
    while (true) {
        $url = rtrim(ask($label), '/');
        if (str_starts_with($url, 'https://') && filter_var($url, FILTER_VALIDATE_URL)) {
            return $url;
        }
        echo "  Must start with https://\n";
    }
};
$askRequired = static function (string $label, string $default = ''): string {
    while (true) {
        $value = ask($label . ($default !== '' ? " [{$default}]" : '')) ?: $default;
        if ($value !== '') {
            return $value;
        }
        echo "  Required.\n";
    }
};

echo "Server\n";
$label = preg_replace('/[^a-z0-9-]/', '-', strtolower($askRequired('  Short name for the files', 'staging')));
do {
    $env = $askRequired('  APP_ENV (staging or production)', 'staging');
} while (!in_array($env, ['staging', 'production'], true));
$orderUrl = $askUrl('  Order site URL (e.g. https://mahaveer.foreverstory.in)');
$siteUrl = $askUrl('  Website URL (e.g. https://mahaveer-site.foreverstory.in)');

echo "\nThat server's database (cPanel → MySQL Databases)\n";
$dbHost = $askRequired('  Host', 'localhost');
$dbName = $askRequired('  Database name (cPanel shows it as <cpaneluser>_<name>)');
$dbUser = $askRequired('  User');
$dbPass = askConfirmed('  Password');

// Build in a temporary local database, with the app pointed at it so every service uses it.
$buildName = config('DB_NAME') . '_server_build';
$root = Db::connect(null);
$root->exec("DROP DATABASE IF EXISTS `{$buildName}`");
$root->exec("CREATE DATABASE `{$buildName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$localName = config('DB_NAME');
config_store(['DB_NAME' => $buildName] + config_store());
Db::reset();

try {
    $package = new ServerPackage(Db::pdo());
    $package->prepare();
    echo "\n";
    askAccounts(new StaffService());
    $sql = $package->sql();
    unset($package);
} finally {
    Db::reset();
    config_store(['DB_NAME' => $localName] + config_store());
    $root->exec("DROP DATABASE IF EXISTS `{$buildName}`");
}

$dir = APP_ROOT . '/storage/deploy/' . $label;
if (!is_dir($dir)) {
    mkdir($dir, 0700, true);
}
file_put_contents($dir . '/config.php', ServerPackage::config([
    'label' => $label,
    'APP_ENV' => $env,
    'APP_URL_ORDER' => $orderUrl,
    'APP_URL_WEBSITE' => $siteUrl,
    'DB_HOST' => $dbHost,
    'DB_NAME' => $dbName,
    'DB_USER' => $dbUser,
    'DB_PASS' => $dbPass,
    'VAPID_PUBLIC' => $keys['publicKey'],
    'VAPID_PRIVATE' => $keys['privateKey'],
    'VAPID_SUBJECT' => $siteUrl,
]));
file_put_contents($dir . '/database.sql', $sql);

$shown = str_replace('\\', '/', $dir);
echo <<<TXT

Done. Two files in {$shown}/

  1. database.sql – cPanel → phpMyAdmin → select {$dbName} (it must be EMPTY) → Import → choose the file → Go.
  2. config.php   – after the first deploy, File Manager → mahavir/app/ → Upload, so it becomes mahavir/app/config.php.

They contain the database password and the push private key: delete them from this PC once uploaded.

TXT;
