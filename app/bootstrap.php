<?php

declare(strict_types=1);

use App\Lang;

define('APP_ROOT', dirname(__DIR__));

require APP_ROOT . '/app/helpers.php';

date_default_timezone_set('Asia/Kolkata');
mb_internal_encoding('UTF-8');
error_reporting(E_ALL);
ini_set('display_errors', '0');

$configFile = APP_ROOT . '/app/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    exit("Missing app/config.php. Copy app/config.example.php to app/config.php and fill it in.\n");
}
config_store(require $configFile);

require APP_ROOT . '/vendor/autoload.php';

// App\Foo → app/Foo.php, App\Services\Bar → app/Services/Bar.php
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $file = APP_ROOT . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

// ---------------------------------------------------------------
// Error handling: log everything, show details only locally
// ---------------------------------------------------------------

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false; // silenced with @
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(static function (Throwable $e): void {
    app_log('error', get_class($e) . ': ' . $e->getMessage(), [
        'at' => $e->getFile() . ':' . $e->getLine(),
        'url' => $_SERVER['REQUEST_URI'] ?? null,
    ]);
    render_fatal_error($e);
});

register_shutdown_function(static function (): void {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        app_log('critical', $error['message'], ['at' => $error['file'] . ':' . $error['line']]);
        render_fatal_error(null);
    }
});

function render_fatal_error(?Throwable $e): void
{
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, ($e ? get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() : 'Fatal error') . "\n");
        exit(1);
    }

    if (!headers_sent()) {
        http_response_code(500);
    }
    if (wants_json()) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['ok' => false, 'error' => 'server_error']);
        return;
    }

    try {
        view('error', ['details' => is_local() && $e ? (string) $e : null]);
    } catch (Throwable) {
        echo 'Error / त्रुटी';
    }
}

// ---------------------------------------------------------------
// Web requests: session, security headers, language
// ---------------------------------------------------------------

if (PHP_SAPI !== 'cli') {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name((string) config('SESSION_NAME', 'mahavir_sid'));
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => !is_local(),
    ]);
    session_start();

    send_security_headers();
    Lang::initFromRequest();
}
