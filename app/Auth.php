<?php

declare(strict_types=1);

namespace App;

use DateTimeImmutable;
use PDO;

/**
 * Logins. Staff on the counter tap their name and enter a 4-digit PIN; owner and super-admin
 * use username + password for admin. One user per session.
 *
 * Admin pages always need a password login, even for an owner who is already logged in at the
 * counter with his PIN: a 4-digit PIN is too weak to protect prices and staff accounts.
 */
final class Auth
{
    public const MAX_ATTEMPTS = 5;
    public const LOCK_MINUTES = 15;
    public const COUNTER_IDLE_SECONDS = 12 * 3600;      // counter: a fresh login each morning
    public const ADMIN_IDLE_SECONDS = 30 * 24 * 3600;   // owner's phone stays logged in
    public const IP_MAX_PIN_FAILURES = 20;
    public const IP_MAX_PASSWORD_FAILURES = 10;
    public const IP_WINDOW_MINUTES = 15;
    public const ADMIN_ROLES = ['owner', 'super'];

    private const SESSION_KEY = 'auth';

    // bcrypt hash of a random string. Checking against it when a username does not exist takes
    // as long as a real check, so response times do not reveal which usernames exist.
    private const DUMMY_HASH = '$2y$10$3Q/FfEbx0/Ox7iXOucjLYu1KFmieM/zXawrewPC6BDOtdmD/P7DZW';

    private static ?array $user = null;
    private static bool $loaded = false;

    /** Active staff who have a PIN: the name buttons on the counter login screen. */
    public static function counterStaff(): array
    {
        return Db::pdo()->query(
            "SELECT id, name, role FROM staff
             WHERE is_active = 1 AND pin_hash IS NOT NULL
             ORDER BY FIELD(role, 'staff', 'owner', 'super'), name"
        )->fetchAll();
    }

    // ---------------------------------------------------------------
    // Credential checks (no session changes; used by login and by tests)
    // ---------------------------------------------------------------

    /** @return array{ok: bool, error?: string, attempts_left?: int, locked_until?: string, staff?: array} */
    public static function verifyPin(int $staffId, string $pin): array
    {
        return Db::transaction(static function (PDO $db) use ($staffId, $pin): array {
            $stmt = $db->prepare('SELECT * FROM staff WHERE id = ? AND is_active = 1 AND pin_hash IS NOT NULL FOR UPDATE');
            $stmt->execute([$staffId]);
            $row = $stmt->fetch();
            if (!$row) {
                return ['ok' => false, 'error' => 'auth.unknown_staff'];
            }
            return self::checkAttempt(
                $db,
                $row,
                static fn (): bool => is_valid_pin($pin) && password_verify($pin, $row['pin_hash']),
                'auth.wrong_pin'
            );
        });
    }

    /** @return array{ok: bool, error?: string, attempts_left?: int, locked_until?: string, staff?: array} */
    public static function verifyPassword(string $username, string $password): array
    {
        return Db::transaction(static function (PDO $db) use ($username, $password): array {
            $stmt = $db->prepare(
                "SELECT * FROM staff WHERE username = ? AND is_active = 1
                   AND role IN ('owner', 'super') AND password_hash IS NOT NULL FOR UPDATE"
            );
            $stmt->execute([mb_strtolower(trim($username))]);
            $row = $stmt->fetch();
            if (!$row) {
                password_verify($password, self::DUMMY_HASH);
                return ['ok' => false, 'error' => 'auth.wrong_password'];
            }
            return self::checkAttempt(
                $db,
                $row,
                static fn (): bool => password_verify($password, $row['password_hash']),
                'auth.wrong_password'
            );
        });
    }

    /** Per-account lockout: 5 wrong attempts lock the account for 15 minutes. */
    private static function checkAttempt(PDO $db, array $row, callable $isValid, string $wrongKey): array
    {
        $now = now();
        if ($row['locked_until'] !== null && new DateTimeImmutable($row['locked_until']) > $now) {
            return ['ok' => false, 'error' => 'auth.locked', 'locked_until' => $row['locked_until']];
        }

        if ($isValid()) {
            $db->prepare('UPDATE staff SET failed_attempts = 0, locked_until = NULL WHERE id = ?')->execute([$row['id']]);
            return ['ok' => true, 'staff' => $row];
        }

        // An expired lock starts the count again.
        $attempts = ($row['locked_until'] !== null ? 0 : (int) $row['failed_attempts']) + 1;
        if ($attempts >= self::MAX_ATTEMPTS) {
            $until = $now->modify('+' . self::LOCK_MINUTES . ' minutes')->format('Y-m-d H:i:s');
            $db->prepare('UPDATE staff SET failed_attempts = 0, locked_until = ? WHERE id = ?')->execute([$until, $row['id']]);
            app_log('warning', 'Account locked after failed logins', ['staff_id' => (int) $row['id']]);
            return ['ok' => false, 'error' => 'auth.locked', 'locked_until' => $until];
        }

        $db->prepare('UPDATE staff SET failed_attempts = ?, locked_until = NULL WHERE id = ?')->execute([$attempts, $row['id']]);
        return ['ok' => false, 'error' => $wrongKey, 'attempts_left' => self::MAX_ATTEMPTS - $attempts];
    }

    // ---------------------------------------------------------------
    // Login / logout (session)
    // ---------------------------------------------------------------

    public static function loginWithPin(int $staffId, string $pin): array
    {
        return self::login(
            'pin_fail:' . client_ip(),
            self::IP_MAX_PIN_FAILURES,
            static fn (): array => self::verifyPin($staffId, $pin),
            'pin',
            ['staff_id' => $staffId]
        );
    }

    public static function loginWithPassword(string $username, string $password): array
    {
        return self::login(
            'pw_fail:' . client_ip(),
            self::IP_MAX_PASSWORD_FAILURES,
            static fn (): array => self::verifyPassword($username, $password),
            'password',
            ['username' => mb_substr($username, 0, 50)]
        );
    }

    private static function login(string $ipKey, int $ipMax, callable $verify, string $method, array $logContext): array
    {
        // Per-IP limit on top of the per-account lock: slows down guessing across many names.
        if (RateLimiter::tooMany($ipKey, $ipMax, self::IP_WINDOW_MINUTES)) {
            return ['ok' => false, 'error' => 'auth.too_many'];
        }

        $result = $verify();
        if (!$result['ok']) {
            RateLimiter::hit($ipKey);
            app_log('warning', "Login failed ({$method})", $logContext + ['ip' => client_ip(), 'reason' => $result['error']]);
            return $result;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION[self::SESSION_KEY] = [
            'staff_id' => (int) $result['staff']['id'],
            'method' => $method,
            'last_seen' => time(),
        ];
        self::forget();
        return $result;
    }

    public static function logout(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        self::forget();
    }

    /** Clears the per-request cache of the current user. */
    public static function forget(): void
    {
        self::$user = null;
        self::$loaded = false;
    }

    // ---------------------------------------------------------------
    // Current user and access checks
    // ---------------------------------------------------------------

    /**
     * The logged-in user, re-read from the database on every request so a deactivated account is
     * logged out at once. Null when not logged in or idle for too long.
     *
     * @return array{id: int, name: string, role: string, username: ?string, method: string}|null
     */
    public static function user(): ?array
    {
        if (self::$loaded) {
            return self::$user;
        }
        self::$loaded = true;

        $auth = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_array($auth) || !isset($auth['staff_id'], $auth['method'], $auth['last_seen'])) {
            return self::$user = null;
        }

        $idleLimit = $auth['method'] === 'password' ? self::ADMIN_IDLE_SECONDS : self::COUNTER_IDLE_SECONDS;
        if (time() - (int) $auth['last_seen'] > $idleLimit) {
            unset($_SESSION[self::SESSION_KEY]);
            return self::$user = null;
        }

        $stmt = Db::pdo()->prepare('SELECT id, name, role, username FROM staff WHERE id = ? AND is_active = 1');
        $stmt->execute([(int) $auth['staff_id']]);
        $row = $stmt->fetch();
        if (!$row) {
            unset($_SESSION[self::SESSION_KEY]);
            return self::$user = null;
        }

        $_SESSION[self::SESSION_KEY]['last_seen'] = time();
        self::refreshCookie();

        $row['id'] = (int) $row['id'];
        $row['method'] = $auth['method'];
        return self::$user = $row;
    }

    public static function isAdmin(?array $user): bool
    {
        return $user !== null
            && in_array($user['role'], self::ADMIN_ROLES, true)
            && $user['method'] === 'password';
    }

    /** Counter pages: any logged-in user (staff, owner, super). */
    public static function requireStaff(): array
    {
        return self::user() ?? self::deny('/counter/');
    }

    /** Admin pages: owner or super-admin who logged in with a password. */
    public static function requireAdmin(): array
    {
        $user = self::user();
        return self::isAdmin($user) ? $user : self::deny('/admin/');
    }

    private static function deny(string $loginUrl): never
    {
        if (wants_json()) {
            json_response(['ok' => false, 'error' => 'auth.required'], 401);
        }
        redirect($loginUrl);
    }

    /** Keeps the session cookie alive while the user is active (it is set once at session start). */
    private static function refreshCookie(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE || headers_sent()) {
            return;
        }
        $params = session_get_cookie_params();
        setcookie(session_name(), (string) session_id(), [
            'expires' => time() + SESSION_LIFETIME,
            'path' => $params['path'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'],
        ]);
    }
}
