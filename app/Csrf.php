<?php

declare(strict_types=1);

namespace App;

final class Csrf
{
    private const SESSION_KEY = '_csrf';

    public static function token(): string
    {
        if (empty($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::SESSION_KEY];
    }

    public static function isValid(?string $token): bool
    {
        $expected = $_SESSION[self::SESSION_KEY] ?? '';
        return is_string($token) && $token !== '' && $expected !== '' && hash_equals($expected, $token);
    }

    /** Token sent with this request: form field `_csrf` or header `X-CSRF-Token`. */
    public static function fromRequest(): ?string
    {
        $token = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        return is_string($token) ? $token : null;
    }

    /** Call at the top of every POST handler. Stops the request with 403 if the token is wrong. */
    public static function check(): void
    {
        if (self::isValid(self::fromRequest())) {
            return;
        }

        app_log('warning', 'CSRF check failed', ['url' => $_SERVER['REQUEST_URI'] ?? null]);
        if (wants_json()) {
            json_error('error.csrf', 403);
        }
        http_response_code(403);
        view('error', ['message' => t('error.csrf')]);
        exit;
    }
}
