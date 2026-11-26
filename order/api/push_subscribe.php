<?php

declare(strict_types=1);

// Web Push on the owner's phone (ARCHITECTURE §9), owner / super-admin only. Body (JSON): action, …
//   subscribe    subscription (PushSubscription.toJSON())  saves it; an existing mute is kept
//   unsubscribe  endpoint                                  forgets this device
//   mute         endpoint, muted (bool)                    mute switch for this device
//   status       endpoint                                  {subscribed, muted}
//   test         endpoint                                  sends a test notification to this device

use App\Auth;
use App\Csrf;
use App\Services\PushService;
use App\ValidationException;

require __DIR__ . '/../boot.php';

$user = Auth::requireAdmin();
if (!is_post()) {
    json_error('error.generic', 405);
}
Csrf::check();

$body = json_body();
$push = new PushService();
$endpoint = (string) ($body['endpoint'] ?? '');

/** Reply with this device's state. */
$state = static function () use ($push, $user, &$endpoint): never {
    $status = $push->status($user['id'], $endpoint);
    json_response(['ok' => true, 'data' => ['subscribed' => $status !== null, 'muted' => $status['muted'] ?? false]]);
};

try {
    switch ((string) ($body['action'] ?? '')) {
        case 'subscribe':
            $subscription = is_array($body['subscription'] ?? null) ? $body['subscription'] : [];
            $push->subscribe($user['id'], $subscription);
            $endpoint = (string) $subscription['endpoint'];
            $state();

        case 'unsubscribe':
            $push->unsubscribe($user['id'], $endpoint);
            $state();

        case 'mute':
            $push->setMuted($user['id'], $endpoint, (bool) ($body['muted'] ?? false));
            $state();

        case 'status':
            $state();

        case 'test':
            if (!$push->notifyDevice($user['id'], $endpoint, PushService::forTest())) {
                json_error('owner.error.test_failed', 502);
            }
            json_response(['ok' => true, 'data' => ['message' => t('owner.push.test_sent')]]);

        default:
            json_error('error.generic', 400);
    }
} catch (ValidationException $e) {
    json_error($e->first());
}
