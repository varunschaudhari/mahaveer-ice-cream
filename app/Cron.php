<?php

declare(strict_types=1);

namespace App;

use Throwable;

/**
 * Runs a cron job (cron/*.php): one at a time (a lock file, so a slow backup is never started
 * twice), logs failures, and records the last run in storage/cron/<name>.json for admin →
 * System health. Exit code 1 on failure so cPanel's cron mail (if set up) reports it.
 */
final class Cron
{
    private static ?string $dir = null;

    public static function dir(): string
    {
        return self::$dir ?? APP_ROOT . '/storage/cron';
    }

    /** Tests: keep lock and last-run files somewhere else (null = back to storage/cron). */
    public static function useDir(?string $dir): void
    {
        self::$dir = $dir;
    }

    /** @param callable(): string $job returns a one-line result for the log and the health page */
    public static function run(string $name, callable $job): never
    {
        exit(self::runJob($name, $job));
    }

    /** As run(), without exiting (tests). @return int exit code: 0 ok or already running, 1 failed */
    public static function runJob(string $name, callable $job): int
    {
        if (!preg_match('/^[a-z_]+$/', $name)) {
            throw new \InvalidArgumentException("Bad cron name: {$name}");
        }
        $dir = self::dir();
        if (!is_dir($dir)) {
            mkdir($dir, 0770, true);
        }

        $lock = fopen($dir . '/' . $name . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            app_log('warning', "cron {$name}: still running from the last start, skipped");
            return 0;
        }

        $started = microtime(true);
        try {
            $message = $job();
            self::record($name, true, $message, $started);
            echo $message, "\n";
            return 0;
        } catch (Throwable $e) {
            app_log('error', "cron {$name} failed: " . $e->getMessage());
            self::record($name, false, $e->getMessage(), $started);
            fwrite(STDERR, $e->getMessage() . "\n");
            return 1;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array{at: string, ok: bool, message: string, seconds: float}|null */
    public static function lastRun(string $name): ?array
    {
        $file = self::dir() . '/' . $name . '.json';
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        return is_array($data) && isset($data['at'], $data['ok']) ? $data : null;
    }

    private static function record(string $name, bool $ok, string $message, float $started): void
    {
        file_put_contents(self::dir() . '/' . $name . '.json', json_encode([
            'at' => now()->format('Y-m-d H:i:s'),
            'ok' => $ok,
            'message' => mb_substr($message, 0, 300),
            'seconds' => round(microtime(true) - $started, 1),
        ], JSON_UNESCAPED_UNICODE), LOCK_EX);
    }
}
