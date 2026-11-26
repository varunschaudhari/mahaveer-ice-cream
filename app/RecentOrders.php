<?php

declare(strict_types=1);

namespace App;

/**
 * "My recent orders" on the customer's phone (developer decision 2026-11-26): a cookie holds the
 * id + secret key of the last few orders placed from this browser — never the mobile number,
 * so nobody can see someone else's orders by typing their number.
 */
final class RecentOrders
{
    private const COOKIE = 'mahavir_recent';
    private const MAX = 5;

    /** @return list<array{id: int, key: string}> newest first */
    public static function read(): array
    {
        $raw = $_COOKIE[self::COOKIE] ?? '';
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $orders = [];
        foreach (explode(',', $raw) as $pair) {
            if (preg_match('/^(\d{1,10})\.([a-f0-9]{32})$/', $pair, $m)) {
                $orders[] = ['id' => (int) $m[1], 'key' => $m[2]];
            }
        }
        return array_slice($orders, 0, self::MAX);
    }

    public static function remember(int $id, string $key): void
    {
        $orders = array_filter(self::read(), static fn (array $o): bool => $o['id'] !== $id);
        array_unshift($orders, ['id' => $id, 'key' => $key]);
        $value = implode(',', array_map(static fn (array $o): string => $o['id'] . '.' . $o['key'], array_slice($orders, 0, self::MAX)));

        setcookie(self::COOKIE, $value, [
            'expires' => time() + 90 * 86400,
            'path' => '/',
            'secure' => !is_local(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[self::COOKIE] = $value;
    }
}
