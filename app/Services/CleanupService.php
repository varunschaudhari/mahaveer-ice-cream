<?php

declare(strict_types=1);

namespace App\Services;

use App\Db;
use DateTimeImmutable;
use PDO;

/**
 * Weekly housekeeping (cron/cleanup.php): old app logs, old rate-limit rows, expired session
 * files, and uploaded photos that no menu item or gallery photo uses any more.
 */
final class CleanupService
{
    public const LOG_DAYS = 90;
    public const RATE_LIMIT_HOURS = 24;          // the longest rate-limit window is 60 minutes
    public const SESSION_SECONDS = SESSION_LIFETIME;
    public const ORPHAN_PHOTO_DAYS = 7;          // a photo being saved right now is never touched

    private readonly PDO $db;
    private readonly string $storage;

    public function __construct(?PDO $db = null, ?string $storage = null)
    {
        $this->db = $db ?? Db::pdo();
        $this->storage = $storage ?? APP_ROOT . '/storage';
    }

    /** @return array{logs: int, rate_limits: int, sessions: int, photos: int} what was deleted */
    public function run(DateTimeImmutable $now): array
    {
        return [
            'logs' => $this->oldLogs($now),
            'rate_limits' => $this->oldRateLimits($now),
            'sessions' => $this->oldSessions($now),
            'photos' => $this->orphanPhotos($now),
        ];
    }

    public function oldLogs(DateTimeImmutable $now): int
    {
        $oldest = $now->setTime(0, 0)->modify('-' . self::LOG_DAYS . ' days')->format('Y-m-d');
        $deleted = 0;
        foreach (glob($this->storage . '/logs/app-*.log') ?: [] as $file) {
            if (preg_match('/^app-(\d{4}-\d{2}-\d{2})\.log$/', basename($file), $m) && $m[1] < $oldest && unlink($file)) {
                $deleted++;
            }
        }
        return $deleted;
    }

    public function oldRateLimits(DateTimeImmutable $now): int
    {
        $stmt = $this->db->prepare('DELETE FROM rate_limits WHERE hit_at < ?');
        $stmt->execute([$now->modify('-' . self::RATE_LIMIT_HOURS . ' hours')->format('Y-m-d H:i:s')]);
        return $stmt->rowCount();
    }

    /** PHP removes old sessions only now and then; on a quiet server they could pile up. */
    public function oldSessions(DateTimeImmutable $now): int
    {
        $deleted = 0;
        foreach (glob($this->storage . '/sessions/sess_*') ?: [] as $file) {
            if (filemtime($file) < $now->getTimestamp() - self::SESSION_SECONDS && unlink($file)) {
                $deleted++;
            }
        }
        return $deleted;
    }

    /** Photos replaced or removed in admin whose file was left behind. */
    public function orphanPhotos(DateTimeImmutable $now): int
    {
        $used = array_flip(array_merge(
            $this->db->query('SELECT photo FROM items WHERE photo IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN),
            $this->db->query('SELECT file_name FROM site_photos')->fetchAll(PDO::FETCH_COLUMN),
        ));
        $deleted = 0;
        foreach (glob($this->storage . '/uploads/*.webp') ?: [] as $file) {
            $name = basename($file);
            if (ImageService::isValidName($name) && !isset($used[$name])
                && filemtime($file) < $now->getTimestamp() - self::ORPHAN_PHOTO_DAYS * 86400 && unlink($file)) {
                $deleted++;
            }
        }
        return $deleted;
    }
}
