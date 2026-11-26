<?php

declare(strict_types=1);

namespace App;

/** Counts events per key in the `rate_limits` table (e.g. failed logins per IP). */
final class RateLimiter
{
    public static function tooMany(string $key, int $max, int $windowMinutes): bool
    {
        $stmt = Db::pdo()->prepare(
            'SELECT COUNT(*) FROM rate_limits WHERE rl_key = ? AND hit_at > NOW() - INTERVAL ? MINUTE'
        );
        $stmt->execute([$key, $windowMinutes]);
        return (int) $stmt->fetchColumn() >= $max;
    }

    public static function hit(string $key): void
    {
        Db::pdo()->prepare('INSERT INTO rate_limits (rl_key) VALUES (?)')->execute([$key]);
    }
}
