<?php

declare(strict_types=1);

use App\Csrf;
use App\Lang;

// ---------------------------------------------------------------
// Config and environment
// ---------------------------------------------------------------

/**
 * Holds the array returned by app/config.php. Tests replace it to point at mahavir_test.
 * @internal call config() to read values
 */
function config_store(?array $replace = null): array
{
    static $store = [];
    if ($replace !== null) {
        $store = $replace;
    }
    return $store;
}

function config(string $key, mixed $default = null): mixed
{
    return config_store()[$key] ?? $default;
}

function app_env(): string
{
    return (string) config('APP_ENV', 'production');
}

function is_local(): bool
{
    return app_env() === 'local';
}

function now(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata'));
}

// ---------------------------------------------------------------
// Output
// ---------------------------------------------------------------

function e(string|int|float|null $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function t(string $key, array $params = []): string
{
    return Lang::get($key, $params);
}

/** Current language code: 'mr' or 'en'. */
function lang(): string
{
    return Lang::current();
}

/** Picks $row["{$base}_mr"] or $row["{$base}_en"] for the current language, falling back to the other. */
function lang_field(array $row, string $base): string
{
    $other = lang() === 'mr' ? 'en' : 'mr';
    $value = (string) ($row[$base . '_' . lang()] ?? '');
    return $value !== '' ? $value : (string) ($row[$base . '_' . $other] ?? '');
}

/** Table code → number shown to people: 'T4' → '4'. */
function table_number(string $code): string
{
    $number = ltrim(strtoupper($code), 'T');
    return $number === '' ? $code : $number;
}

/** "टेबल 4" / "Table 4", plus "(बाहेर) / (Outside)" for outside tables. */
function table_label(string $code, string $area = 'inside'): string
{
    $label = t('table.label', ['n' => table_number($code)]);
    return $area === 'outside' ? $label . ' ' . t('table.outside') : $label;
}

/**
 * What the customer's status page shows for a takeaway or bulk order: status label, help line,
 * whether the customer may still cancel, and the progress steps.
 *
 * @return array{label: string, help: string, can_cancel: bool, steps: list<array{key: string, label: string}>}
 */
function customer_status(array $order): array
{
    $status = (string) $order['status'];
    $isBulk = $order['order_type'] === 'bulk';

    if ($isBulk) {
        $due = App\Services\BulkService::advanceDue($order);
        $balance = App\Services\BulkService::balance($order);
        $ownReason = (string) ($order['cancel_reason'] ?? '');
        $help = match ($status) {
            'pending_approval' => t('status.help.bulk_pending'),
            'preparing' => $due > 0 ? t('status.help.bulk_advance', ['amount' => money($due)]) : t('status.help.bulk_approved'),
            'ready' => t('status.help.bulk_ready', ['amount' => money($balance)]),
            'completed' => t('status.help.completed'),
            'cancelled' => $ownReason !== '' && $ownReason !== App\Services\OrderService::CUSTOMER_CANCEL_REASON
                ? t('status.help.bulk_rejected', ['reason' => $ownReason])
                : t('status.help.cancelled'),
            default => '',
        };
        $steps = [
            ['key' => 'pending_approval', 'label' => t('status.pending_approval')],
            ['key' => 'preparing', 'label' => t('status.bulk_approved')],
            ['key' => 'ready', 'label' => t('status.ready')],
            ['key' => 'completed', 'label' => t('status.completed')],
        ];
        return [
            'label' => $status === 'preparing' ? t('status.bulk_approved') : order_status_label($status),
            'help' => $help,
            'can_cancel' => $status === 'pending_approval',
            'steps' => $steps,
        ];
    }

    $help = match ($status) {
        'received' => t('status.help.received'),
        'preparing' => t('status.help.preparing'),
        'ready' => t('status.help.ready', ['token' => $order['token_no']]),
        'completed' => t('status.help.completed'),
        'cancelled' => t('status.help.cancelled'),
        'no_show' => t('status.help.no_show'),
        default => '',
    };
    return [
        'label' => order_status_label($status),
        'help' => $help,
        'can_cancel' => $order['order_type'] === 'takeaway' && $status === 'received',
        'steps' => array_map(
            static fn (string $key): array => ['key' => $key, 'label' => order_status_label($key)],
            ['received', 'preparing', 'ready', 'completed']
        ),
    ];
}

/** Order status in words for customers and staff. */
function order_status_label(string $status): string
{
    return match ($status) {
        'pending_approval' => t('status.pending_approval'),
        'received' => t('status.received'),
        'preparing' => t('status.preparing'),
        'ready' => t('status.ready'),
        'served' => t('status.served'),
        'completed' => t('status.completed'),
        'cancelled' => t('status.cancelled'),
        'no_show' => t('status.no_show'),
        default => $status,
    };
}

function role_label(string $role): string
{
    return match ($role) {
        'owner' => t('role.owner'),
        'super' => t('role.super'),
        default => t('role.staff'),
    };
}

/** '1' / true → ' checked' for a checkbox. */
function checked(mixed $value): string
{
    return !empty($value) ? ' checked' : '';
}

/** URL of the current page with ?lang= set, for the language toggle. */
function lang_switch_url(string $lang): string
{
    $query = $_GET;
    $query['lang'] = $lang;
    return current_path() . '?' . http_build_query($query);
}

/**
 * '22:30' → '10:30 PM' (en) or 'रात्री 10:30' (mr). Marathi names the part of the day instead
 * of AM/PM; both come from the lang files (time.format + time.morning/afternoon/evening/night).
 */
function format_time(string $hhmm): string
{
    [$hour, $minute] = array_map('intval', explode(':', $hhmm) + [1 => '0']);
    $part = match (true) {
        $hour < 12 => 'time.morning',
        $hour < 16 => 'time.afternoon',
        $hour < 19 => 'time.evening',
        default => 'time.night',
    };
    $clock = sprintf('%d:%02d', $hour % 12 ?: 12, $minute);
    return t('time.format', ['time' => $clock, 'part' => t($part)]);
}

/** Path of the current request, always a single leading slash (never "//host"). */
function current_path(): string
{
    $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    return '/' . ltrim($path, '/');
}

/** Web path to a file in the current web root's assets/ folder, with a cache-busting version. */
function asset(string $path): string
{
    $path = ltrim($path, '/');
    $file = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\') . '/assets/' . $path;
    $version = is_file($file) ? '?v=' . filemtime($file) : '';
    return '/assets/' . $path . $version;
}

/** Renders app/Views/{$name}.php with $vars as local variables. */
function view(string $name, array $vars = []): void
{
    extract($vars, EXTR_SKIP);
    require APP_ROOT . '/app/Views/' . $name . '.php';
}

/**
 * Inline outline icon (24 × 24 grid, drawn in currentColor), safe to print as is: the markup
 * is fixed here, never built from input. Decorative only (aria-hidden); the text next to it
 * says what it means.
 */
function icon(string $name, int $size = 20): string
{
    static $shapes = [
        'arrow' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
        'box' => '<path d="M3.5 7.5 12 3l8.5 4.5v9L12 21l-8.5-4.5z"/><path d="M3.5 7.5 12 12l8.5-4.5M12 12v9"/>',
        'chart' => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
        'globe' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.6 3.8 5.6 3.8 9s-1.3 6.4-3.8 9c-2.5-2.6-3.8-5.6-3.8-9S9.5 5.6 12 3z"/>',
        'lock' => '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>',
        'receipt' => '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2z"/><path d="M9 8h6M9 12h6M9 16h3"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M4.9 4.9 7 7M17 17l2.1 2.1M2 12h3M19 12h3M4.9 19.1 7 17M17 7l2.1-2.1"/>',
        'table' => '<path d="M3 9h18M5 9v11M19 9v11M7 9l1-4h8l1 4M8 14h8"/>',
        'bag' => '<path d="M6 8h12l-1 12H7L6 8z"/><path d="M9 8V7a3 3 0 0 1 6 0v1"/>',
        'camera' => '<path d="M4 8h3l2-3h6l2 3h3v11H4z"/><circle cx="12" cy="13" r="3.5"/>',
        'chat' => '<path d="M20.5 12a8.5 8.5 0 0 1-12.4 7.6L3.5 21l1.4-4.4A8.5 8.5 0 1 1 20.5 12z"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'directions' => '<path d="M3 6.5l6-3 6 3 6-3v14l-6 3-6-3-6 3z"/><path d="M9 3.5v14M15 6.5v14"/>',
        'gift' => '<path d="M4 11h16v9H4zM3 7h18v4H3zM12 7v13"/><path d="M12 7C10.5 4 7 4 7 5.8S10 7 12 7zM12 7c1.5-3 5-3 5-1.2S14 7 12 7z"/>',
        'phone' => '<path d="M5 4h3.5l1.8 4.5-2.3 1.4a11 11 0 0 0 6.1 6.1l1.4-2.3L20 15.5V19a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2z"/>',
        'pin' => '<path d="M12 21s-7-6.1-7-11.4a7 7 0 0 1 14 0C19 14.9 12 21 12 21z"/><circle cx="12" cy="9.6" r="2.5"/>',
        'star' => '<path d="M12 3.5l2.6 5.3 5.9.9-4.3 4.1 1 5.8-5.2-2.8-5.2 2.8 1-5.8-4.3-4.1 5.9-.9z"/>',
        'cone' => '<circle cx="12" cy="7.5" r="4.5"/><path d="M7 11.5h10L12 21.5zM9.2 15h5.6"/>',
        'home' => '<path d="M3 11l9-7 9 7"/><path d="M5 9.5V20h14V9.5M10 20v-6h4v6"/>',
        'log' => '<path d="M8 4H6a1 1 0 0 0-1 1v15a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V5a1 1 0 0 0-1-1h-2"/><rect x="8" y="2.5" width="8" height="3.5" rx="1"/><path d="M9 11h6M9 15h6"/>',
        'logout' => '<path d="M9 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h3"/><path d="M16 16l4-4-4-4M20 12H10"/>',
        'pulse' => '<path d="M3 12h4l2-6 4 12 2-6h6"/>',
        'qr' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h3v3h-3zM20 14v.01M14 20.5h3M20.5 17.5v3h-1"/>',
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'users' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0M16 4.6a3.5 3.5 0 0 1 0 6.8M18 14a6.5 6.5 0 0 1 3.5 6"/>',
    ];
    if (!isset($shapes[$name])) {
        throw new InvalidArgumentException("Unknown icon: {$name}");
    }
    return '<svg class="icon" viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '" fill="none" stroke="currentColor"'
        . ' stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
        . $shapes[$name] . '</svg>';
}

// ---------------------------------------------------------------
// Money (integer paise; never floats)
// ---------------------------------------------------------------

/**
 * Parses a fixed-point decimal string with at most 2 decimals into an integer ×100.
 * '40' → 4000, '40.5' → 4050, '0.05' → 5, '-5.00' → -500.
 */
function decimal_to_hundredths(string $value): int
{
    $value = trim($value);
    if (!preg_match('/^(-?)(\d{1,12})(?:\.(\d{1,2}))?$/', $value, $m)) {
        throw new InvalidArgumentException("Not a decimal with at most 2 places: '{$value}'");
    }
    $result = (int) $m[2] * 100 + (int) str_pad($m[3] ?? '', 2, '0');
    return $m[1] === '-' ? -$result : $result;
}

/** 4000 → '40.00', 5 → '0.05', -500 → '-5.00'. */
function hundredths_to_decimal(int $value): string
{
    $sign = $value < 0 ? '-' : '';
    $abs = abs($value);
    return $sign . intdiv($abs, 100) . '.' . str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
}

/** DECIMAL column value (PDO returns a string) → integer paise. */
function paise_from_decimal(string $value): int
{
    return decimal_to_hundredths($value);
}

/** Integer paise → string for a DECIMAL(10,2) column. */
function decimal_from_paise(int $paise): string
{
    return hundredths_to_decimal($paise);
}

/** 4000 → '₹40', 4050 → '₹40.50', 12500000 → '₹1,25,000' (Indian digit grouping). */
function money(int $paise): string
{
    $sign = $paise < 0 ? '-' : '';
    $abs = abs($paise);
    $rupees = (string) intdiv($abs, 100);
    $fraction = $abs % 100;

    if (strlen($rupees) > 3) {
        $last3 = substr($rupees, -3);
        $rest = substr($rupees, 0, -3);
        $rest = ltrim(strrev(chunk_split(strrev($rest), 2, ',')), ',');
        $rupees = $rest . ',' . $last3;
    }

    $text = $sign . '₹' . $rupees;
    return $fraction === 0 ? $text : $text . '.' . str_pad((string) $fraction, 2, '0', STR_PAD_LEFT);
}

/**
 * Price typed by the owner in admin → integer paise. Accepts '55', '55.5', '55.50', '₹55',
 * '1,000'. Throws InvalidArgumentException for anything else (negative, 3 decimals, text).
 */
function parse_rupees(string $input): int
{
    $clean = str_replace(['₹', ',', ' '], '', trim($input));
    if ($clean === '' || str_starts_with($clean, '-')) {
        throw new InvalidArgumentException("Not a rupee amount: '{$input}'");
    }
    return decimal_to_hundredths($clean);
}

/** DECIMAL quantity → display: '2.00' → '2', '2.50' → '2.5'. */
function format_qty(string $qty): string
{
    return str_contains($qty, '.') ? rtrim(rtrim($qty, '0'), '.') : $qty;
}

/** Marathi part of an order-line name snapshot: "बदाम शेक (फुल) / Badam Shake (Full)" → "बदाम शेक (फुल)". */
function marathi_name(string $snapshot): string
{
    $pos = mb_strpos($snapshot, ' / ');
    return $pos === false ? $snapshot : mb_substr($snapshot, 0, $pos);
}

/** Paise → value for a form input: 5500 → '55', 5550 → '55.50'. */
function rupees_input(int $paise): string
{
    return $paise % 100 === 0 ? (string) intdiv($paise, 100) : hundredths_to_decimal($paise);
}

// ---------------------------------------------------------------
// Validation
// ---------------------------------------------------------------

/**
 * Indian mobile number as 10 digits starting 6–9 (SPEC §6.3), or null. Accepts spaces, dashes
 * and a leading +91 / 91 / 0: '+91 98765 43210' → '9876543210'.
 */
function normalize_mobile(string $input): ?string
{
    $digits = preg_replace('/\D/', '', $input);
    if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
        $digits = substr($digits, 2);
    } elseif (strlen($digits) === 11 && str_starts_with($digits, '0')) {
        $digits = substr($digits, 1);
    }
    return preg_match('/^[6-9]\d{9}$/', $digits) ? $digits : null;
}

/** Order-line snapshot "मराठी (…) / English (…)" in the current language. */
function line_name(string $snapshot): string
{
    $pos = mb_strpos($snapshot, ' / ');
    if ($pos === false) {
        return $snapshot;
    }
    return lang() === 'en' ? mb_substr($snapshot, $pos + 3) : mb_substr($snapshot, 0, $pos);
}

function is_valid_pin(string $pin): bool
{
    return (bool) preg_match('/^\d{4}$/', $pin);
}

/** A person/item name: 1..$max characters after trimming, no control characters. */
function is_valid_name(string $name, int $max = 60): bool
{
    $name = trim($name);
    return $name !== '' && mb_strlen($name) <= $max && !preg_match('/[\x00-\x1F\x7F]/u', $name);
}

// ---------------------------------------------------------------
// Dates
// ---------------------------------------------------------------

/** '2026-11-26 16:35:00' → '26/11/2026, 4:35 PM' (or 'दुपारी 4:35' style in Marathi). */
function format_date_time(DateTimeInterface|string $value): string
{
    $dt = is_string($value) ? new DateTimeImmutable($value, new DateTimeZone('Asia/Kolkata')) : $value;
    return $dt->format('d/m/Y') . ', ' . format_time($dt->format('H:i'));
}

// ---------------------------------------------------------------
// Requests and responses
// ---------------------------------------------------------------

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/** Client address for rate limits. REMOTE_ADDR only: forwarded headers can be faked. */
function client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? 'cli');
}

/** One-time message shown on the next page (after a POST → redirect). */
function flash(string $type, string $message): void
{
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

/** @return list<array{type: string, message: string}> and clears them */
function flash_messages(): array
{
    $messages = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return is_array($messages) ? $messages : [];
}

/** Inline error under a form field; $errors maps field → lang key, or [lang key, params]. */
function field_error(array $errors, string $field): string
{
    return isset($errors[$field]) ? '<p class="field-error">' . e(error_text($errors[$field])) . '</p>' : '';
}

/** Translates one ValidationException entry: 'key' or ['key', ['min' => 1]]. */
function error_text(string|array $error): string
{
    return is_array($error) ? t($error[0], $error[1] ?? []) : t($error);
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(Csrf::token()) . '">';
}

/** For JavaScript: fetch() calls send this value in the X-CSRF-Token header. */
function csrf_meta(): string
{
    return '<meta name="csrf-token" content="' . e(Csrf::token()) . '">';
}

function redirect(string $url, int $status = 302): never
{
    header('Location: ' . $url, true, $status);
    exit;
}

/** Sends a JSON body. Shape by convention: ['ok' => true, 'data' => …] or ['ok' => false, 'error' => 'message_key']. */
function json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

/**
 * Runs $task after the reply has been sent where the host allows it (PHP-FPM / LiteSpeed), so a
 * slow push service never delays the customer's page; elsewhere it runs at the end of the
 * request. Errors are logged, never shown.
 */
function after_response(callable $task): void
{
    register_shutdown_function(static function () use ($task): void {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        }
        try {
            $task();
        } catch (Throwable $e) {
            app_log('error', 'After-response task failed: ' . $e->getMessage());
        }
    });
}

/** Notifies the owner's phones after the reply is sent (ARCHITECTURE §9: after commit, never fatal). */
function notify_owners_later(array $message): void
{
    after_response(static fn () => (new App\Services\PushService())->notifyOwners($message));
}

/** Decoded JSON request body (API POSTs); [] when missing or invalid. */
function json_body(): array
{
    $data = json_decode((string) file_get_contents('php://input'), true);
    return is_array($data) ? $data : [];
}

/** JSON error for the counter screen: the lang key plus the translated message to show. */
function json_error(string|array $error, int $status = 422): never
{
    json_response([
        'ok' => false,
        'error' => is_array($error) ? $error[0] : $error,
        'message' => error_text($error),
    ], $status);
}

/** True for API calls, where errors must come back as JSON instead of an HTML page. */
function wants_json(): bool
{
    return str_contains(current_path(), '/api/')
        || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

/**
 * Security headers for every HTML/JSON response. Pages that embed a frame (the Google Map on
 * the website location page) call this again with the extra frame origins; header() replaces.
 */
function send_security_headers(array $frameSrc = []): void
{
    header_remove('X-Powered-By'); // do not advertise the PHP version
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('X-Frame-Options: SAMEORIGIN');
    // Nothing here uses the camera, microphone, location or payment APIs (notifications are not
    // governed by this header, so the owner's phone keeps working).
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
    // Production is HTTPS only (cPanel "Force HTTPS"): browsers remember that for a year. Not on
    // staging / local, so a test server without a certificate never gets locked out.
    if (app_env() === 'production' && is_https()) {
        header('Strict-Transport-Security: max-age=31536000');
    }

    $frames = $frameSrc === [] ? "'none'" : implode(' ', $frameSrc);
    header(
        "Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; "
        . "script-src 'self'; font-src 'self'; connect-src 'self'; frame-src {$frames}; "
        . "base-uri 'self'; form-action 'self'; frame-ancestors 'self'"
    );
}

// ---------------------------------------------------------------
// Logging
// ---------------------------------------------------------------

/** Appends a line to storage/logs/app-YYYY-MM-DD.log. Never throws: logging must not break a request. */
function app_log(string $level, string $message, array $context = []): void
{
    try {
        $dir = APP_ROOT . '/storage/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $line = sprintf(
            "[%s] %s: %s%s\n",
            now()->format('Y-m-d H:i:s'),
            strtoupper($level),
            $message,
            $context === [] ? '' : ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
        @file_put_contents($dir . '/app-' . now()->format('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
    } catch (Throwable) {
        // nothing sensible left to do
    }
}
