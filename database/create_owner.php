<?php

declare(strict_types=1);

// First-run setup (command line): creates the owner account, an optional super-admin (developer)
// account and staff PINs. Later changes are made in admin → Staff.
//
//   php database/create_owner.php
//
// Over SSH on cPanel passwords are hidden while typing; on Windows they are shown.
// No shell on the server? Use database/prepare_server.php on your PC instead (DEPLOYMENT.md).

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';
require __DIR__ . '/cli_prompts.php';

use App\Services\StaffService;

App\Lang::set('en'); // messages from the services, shown in this terminal

echo "Mahavir Ice Cream – account setup (database: " . config('DB_NAME') . ")\n\n";
if (PHP_OS_FAMILY === 'Windows') {
    echo "Note: passwords and PINs are visible while you type on Windows.\n\n";
}

askAccounts(new StaffService());

echo "\nDone. Staff log in at /counter/, the owner at /admin/.\n";
