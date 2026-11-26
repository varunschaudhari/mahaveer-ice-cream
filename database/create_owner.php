<?php

declare(strict_types=1);

// First-run setup (command line): creates the owner account, an optional super-admin (developer)
// account and staff PINs. Later changes are made in admin → Staff.
//
//   php database/create_owner.php
//
// Over SSH on cPanel passwords are hidden while typing; on Windows they are shown.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Services\StaffService;
use App\ValidationException;

$system = ['id' => null, 'role' => 'super'];
$service = new StaffService();

function ask(string $label): string
{
    echo $label . ': ';
    $line = fgets(STDIN);
    if ($line === false) {
        fwrite(STDERR, "\nInput ended.\n");
        exit(1);
    }
    return trim($line);
}

function askSecret(string $label): string
{
    $hide = PHP_OS_FAMILY !== 'Windows' && stream_isatty(STDIN);
    if ($hide) {
        shell_exec('stty -echo');
    }
    $value = ask($label);
    if ($hide) {
        shell_exec('stty echo');
        echo "\n";
    }
    return $value;
}

function askConfirmed(string $label): string
{
    while (true) {
        $first = askSecret($label);
        if ($first === askSecret($label . ' (again)')) {
            return $first;
        }
        echo "  They do not match, try again.\n";
    }
}

function yes(string $question): bool
{
    return in_array(strtolower(ask($question . ' [y/N]')), ['y', 'yes'], true);
}

/** Keeps asking until the service accepts the input; prints each problem in English. */
function createAccount(StaffService $service, array $actor, callable $collect): void
{
    while (true) {
        try {
            $input = $collect();
            $service->create($input, $actor);
            echo "  Created {$input['name']}.\n";
            return;
        } catch (ValidationException $e) {
            foreach ($e->errors as $field => $error) {
                echo "  {$field}: " . error_text($error) . "\n";
            }
            echo "  Please try again.\n";
        }
    }
}

App\Lang::set('en'); // messages from the services, shown in this terminal

echo "Mahavir Ice Cream – account setup (database: " . config('DB_NAME') . ")\n\n";
if (PHP_OS_FAMILY === 'Windows') {
    echo "Note: passwords and PINs are visible while you type on Windows.\n\n";
}

if ($service->activeOwnerCount() === 0) {
    echo "Owner account (admin login + counter PIN)\n";
    createAccount($service, $system, static fn (): array => [
        'role' => 'owner',
        'name' => ask('  Name'),
        'username' => ask('  Username (lowercase, e.g. mahavir)'),
        'password' => askConfirmed('  Password (min 8 characters)'),
        'pin' => askConfirmed('  4-digit PIN for the counter'),
    ]);
} else {
    echo "An owner account already exists – skipping. Change it in admin → Staff.\n";
}

if (yes("\nCreate a super-admin (developer) account?")) {
    createAccount($service, $system, static fn (): array => [
        'role' => 'super',
        'name' => ask('  Name'),
        'username' => ask('  Username'),
        'password' => askConfirmed('  Password (min 8 characters)'),
        'pin' => askConfirmed('  4-digit PIN (leave empty for none)'),
    ]);
}

while (yes("\nAdd a staff member (counter PIN login)?")) {
    createAccount($service, $system, static fn (): array => [
        'role' => 'staff',
        'name' => ask('  Name'),
        'pin' => askConfirmed('  4-digit PIN'),
    ]);
}

echo "\nDone. Staff log in at /counter/, the owner at /admin/.\n";
