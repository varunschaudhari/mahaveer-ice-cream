<?php

declare(strict_types=1);

namespace App\Services;

use App\Db;
use App\Lang;
use App\ValidationException;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use PDO;
use Throwable;

/**
 * Web Push to the owner's phone (SPEC §11, ARCHITECTURE §9) with minishlink/web-push and VAPID keys
 * from config.php. One push_subscriptions row per device; each device can be muted.
 * Messages carry Marathi and English; the phone's service worker shows the language the owner
 * page uses on that phone (developer decision 2026-11-26).
 */
final class PushService
{
    private readonly PDO $db;

    /** @var (callable(array $subscription, string $payload): array{ok: bool, expired: bool, reason: string})|null */
    private $sender;

    /** $sender replaces the real push service (tests). */
    public function __construct(?PDO $db = null, ?callable $sender = null)
    {
        $this->db = $db ?? Db::pdo();
        $this->sender = $sender;
    }

    public static function isConfigured(): bool
    {
        return (string) config('VAPID_PUBLIC', '') !== '' && (string) config('VAPID_PRIVATE', '') !== '';
    }

    // ---------------------------------------------------------------
    // Subscriptions (one per phone / browser)
    // ---------------------------------------------------------------

    /** Saves the browser's PushSubscription (as from subscription.toJSON()); keeps an existing mute. */
    public function subscribe(int $staffId, array $subscription): void
    {
        $endpoint = (string) ($subscription['endpoint'] ?? '');
        $p256dh = (string) ($subscription['keys']['p256dh'] ?? '');
        $auth = (string) ($subscription['keys']['auth'] ?? '');
        if (!str_starts_with($endpoint, 'https://') || strlen($endpoint) > 500
            || !preg_match('/^[A-Za-z0-9_-]{20,255}$/', $p256dh) || !preg_match('/^[A-Za-z0-9_-]{8,255}$/', $auth)) {
            throw ValidationException::one('_', 'owner.error.subscription');
        }
        $this->db->prepare(
            'INSERT INTO push_subscriptions (staff_id, endpoint, p256dh, auth_key) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE staff_id = ?, p256dh = ?, auth_key = ?'
        )->execute([$staffId, $endpoint, $p256dh, $auth, $staffId, $p256dh, $auth]);
    }

    public function unsubscribe(int $staffId, string $endpoint): void
    {
        $this->db->prepare('DELETE FROM push_subscriptions WHERE endpoint = ? AND staff_id = ?')->execute([$endpoint, $staffId]);
    }

    /** @return bool false when this device is not subscribed */
    public function setMuted(int $staffId, string $endpoint, bool $muted): bool
    {
        $stmt = $this->db->prepare('UPDATE push_subscriptions SET is_muted = ? WHERE endpoint = ? AND staff_id = ?');
        $stmt->execute([$muted ? 1 : 0, $endpoint, $staffId]);
        return $this->status($staffId, $endpoint) !== null;
    }

    /** @return array{muted: bool}|null null when this device is not subscribed */
    public function status(int $staffId, string $endpoint): ?array
    {
        $stmt = $this->db->prepare('SELECT is_muted FROM push_subscriptions WHERE endpoint = ? AND staff_id = ?');
        $stmt->execute([$endpoint, $staffId]);
        $muted = $stmt->fetchColumn();
        return $muted === false ? null : ['muted' => (bool) $muted];
    }

    // ---------------------------------------------------------------
    // Sending
    // ---------------------------------------------------------------

    /**
     * Sends to every unmuted device of active owners / super-admins (never staff). Never throws:
     * a push problem must not break the order that triggered it, so problems are logged. Devices
     * the push service reports as gone (404 / 410) are deleted.
     *
     * @param array{title: array, body: array, url: string, tag: string} $message
     * @return int devices that accepted the message
     */
    public function notifyOwners(array $message): int
    {
        try {
            return $this->deliver($this->db->query(
                "SELECT p.* FROM push_subscriptions p JOIN staff s ON s.id = p.staff_id
                 WHERE p.is_muted = 0 AND s.is_active = 1 AND s.role IN ('owner', 'super')"
            )->fetchAll(), $message);
        } catch (Throwable $e) {
            app_log('error', 'Push failed: ' . $e->getMessage());
            return 0;
        }
    }

    /** "Send a test" on the owner page: only this device of this owner, even when muted. */
    public function notifyDevice(int $staffId, string $endpoint, array $message): bool
    {
        try {
            $stmt = $this->db->prepare('SELECT * FROM push_subscriptions WHERE staff_id = ? AND endpoint = ?');
            $stmt->execute([$staffId, $endpoint]);
            return $this->deliver($stmt->fetchAll(), $message) === 1;
        } catch (Throwable $e) {
            app_log('error', 'Push failed: ' . $e->getMessage());
            return false;
        }
    }

    /** @return int devices that accepted the message */
    private function deliver(array $subscriptions, array $message): int
    {
        if ($subscriptions === []) {
            return 0;
        }

        $payload = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $results = $this->sender !== null
            ? array_map(fn (array $s): array => ($this->sender)($s, $payload), $subscriptions)
            : $this->sendWithWebPush($subscriptions, $payload);

        $sent = 0;
        foreach ($subscriptions as $i => $subscription) {
            $result = $results[$i];
            if ($result['ok']) {
                $sent++;
            } elseif ($result['expired']) {
                $this->db->prepare('DELETE FROM push_subscriptions WHERE id = ?')->execute([$subscription['id']]);
            } else {
                app_log('warning', 'Push not delivered', ['reason' => mb_substr($result['reason'], 0, 200)]);
            }
        }
        return $sent;
    }

    /** @return list<array{ok: bool, expired: bool, reason: string}> in the order of $subscriptions */
    private function sendWithWebPush(array $subscriptions, string $payload): array
    {
        if (!self::isConfigured()) {
            app_log('warning', 'Push not sent: VAPID keys are missing in app/config.php (run database/vapid_keys.php)');
            return array_fill(0, count($subscriptions), ['ok' => false, 'expired' => false, 'reason' => 'not configured']);
        }

        $webPush = new WebPush([
            'VAPID' => [
                'subject' => (string) config('VAPID_SUBJECT'),
                'publicKey' => (string) config('VAPID_PUBLIC'),
                'privateKey' => (string) config('VAPID_PRIVATE'),
            ],
        ], ['TTL' => 900, 'urgency' => 'high'], 10);

        foreach ($subscriptions as $subscription) {
            $webPush->queueNotification(Subscription::create([
                'endpoint' => $subscription['endpoint'],
                'publicKey' => $subscription['p256dh'],
                'authToken' => $subscription['auth_key'],
                'contentEncoding' => 'aes128gcm',
            ]), $payload);
        }

        $byEndpoint = [];
        foreach ($webPush->flush() as $report) {
            $byEndpoint[$report->getEndpoint()] = [
                'ok' => $report->isSuccess(),
                'expired' => $report->isSubscriptionExpired(),
                'reason' => $report->getReason(),
            ];
        }
        return array_map(
            static fn (array $s): array => $byEndpoint[$s['endpoint']] ?? ['ok' => false, 'expired' => false, 'reason' => 'no report'],
            $subscriptions
        );
    }

    // ---------------------------------------------------------------
    // Messages (SPEC §11: new takeaway, table and bulk orders; ARCHITECTURE §9: Request bill)
    // ---------------------------------------------------------------

    /** A new takeaway, table or bulk order (OrderService::find). */
    public static function forOrder(array $order): array
    {
        $id = (int) $order['id'];
        $total = money(paise_from_decimal((string) $order['total']));
        $items = static fn (): string => implode(', ', array_map(
            static fn (array $l): string => format_qty((string) $l['qty']) . '× ' . line_name($l['item_name']),
            array_slice($order['items'], 0, 3)
        )) . (count($order['items']) > 3 ? ' …' : '');

        [$title, $body] = match ($order['order_type']) {
            'table' => [
                self::each(static fn (): string => t('push.table_title', ['table' => table_number((string) $order['table_code'])])),
                self::each(static fn (): string => $items() . ' · ' . $total),
            ],
            'bulk' => [
                self::each(static fn (): string => t('push.bulk_title', ['name' => $order['customer_name']])),
                self::each(static fn (): string => t('push.bulk_body', [
                    'date' => date('d/m', strtotime($order['pickup_at'])),
                    'time' => format_time(substr($order['pickup_at'], 11, 5)),
                    'total' => $total,
                ])),
            ],
            default => [
                self::each(static fn (): string => t('push.takeaway_title', ['token' => $order['token_no']])),
                self::each(static fn (): string => t('push.takeaway_body', [
                    'time' => format_time(substr((string) $order['pickup_at'], 11, 5)),
                    'total' => $total,
                    'items' => $items(),
                ])),
            ],
        };

        return ['title' => $title, 'body' => $body, 'url' => '/owner/?order=' . $id, 'tag' => 'order-' . $id];
    }

    /** The owner page's "Send a test notification" button. */
    public static function forTest(): array
    {
        return [
            'title' => self::each(static fn (): string => t('push.test_title')),
            'body' => self::each(static fn (): string => t('push.test_body')),
            'url' => '/owner/',
            'tag' => 'test',
        ];
    }

    /** A table asked for its bill. */
    public static function forBillRequest(string $tableCode): array
    {
        $number = table_number($tableCode);
        return [
            'title' => self::each(static fn (): string => t('push.bill_title', ['table' => $number])),
            'body' => self::each(static fn (): string => t('push.bill_body')),
            'url' => '/owner/',
            'tag' => 'bill-' . $tableCode,
        ];
    }

    /** Runs $text once in Marathi and once in English. @return array{mr: string, en: string} */
    private static function each(callable $text): array
    {
        $current = Lang::current();
        $out = [];
        try {
            foreach (Lang::SUPPORTED as $lang) {
                Lang::set($lang);
                $out[$lang] = $text();
            }
        } finally {
            Lang::set($current);
        }
        return $out;
    }
}
