<?php

declare(strict_types=1);

namespace App;

final class Lang
{
    public const SUPPORTED = ['mr', 'en'];
    public const DEFAULT = 'mr';
    private const COOKIE = 'lang';

    private static string $current = self::DEFAULT;

    /** @var array<string, array<string, string>> */
    private static array $strings = [];

    /**
     * Reads the `lang` cookie. A `?lang=en` / `?lang=mr` link stores the choice in the cookie
     * and redirects to the same page without the parameter, so URLs stay clean.
     */
    public static function initFromRequest(): void
    {
        $cookie = $_COOKIE[self::COOKIE] ?? '';
        if (is_string($cookie) && in_array($cookie, self::SUPPORTED, true)) {
            self::$current = $cookie;
        }

        $requested = $_GET['lang'] ?? null;
        if (!is_string($requested) || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            return;
        }
        if (in_array($requested, self::SUPPORTED, true)) {
            setcookie(self::COOKIE, $requested, [
                'expires' => time() + 365 * 24 * 3600,
                'path' => '/',
                'samesite' => 'Lax',
                'secure' => !is_local(),
                'httponly' => true,
            ]);
        }

        $query = $_GET;
        unset($query['lang']);
        redirect(current_path() . ($query === [] ? '' : '?' . http_build_query($query)));
    }

    public static function set(string $lang): void
    {
        if (!in_array($lang, self::SUPPORTED, true)) {
            throw new \InvalidArgumentException("Unsupported language: {$lang}");
        }
        self::$current = $lang;
    }

    public static function current(): string
    {
        return self::$current;
    }

    /** Looks up $key in the current language, then English, then returns the key itself. */
    public static function get(string $key, array $params = []): string
    {
        $text = self::strings(self::$current)[$key] ?? self::strings('en')[$key] ?? null;
        if ($text === null) {
            if (is_local()) {
                app_log('warning', "Missing translation key: {$key}");
            }
            $text = $key;
        }

        foreach ($params as $name => $value) {
            $text = str_replace('{' . $name . '}', (string) $value, $text);
        }
        return $text;
    }

    /** @return array<string, string> */
    public static function strings(string $lang): array
    {
        return self::$strings[$lang] ??= require APP_ROOT . '/lang/' . $lang . '.php';
    }
}
