<?php

declare(strict_types=1);

// Actions on a saved order from the counter. Body (JSON): action, order_id, …
//   reprint            → logs the reprint, returns the print URL (marked DUPLICATE)
//   cancel  reason,pin → owner PIN + reason, today's bills only
// M3 adds accept / ready / pickup / serve.

use App\Auth;
use App\Csrf;
use App\Services\OrderService;
use App\ValidationException;

require __DIR__ . '/../boot.php';

$user = Auth::requireStaff();
if (!is_post()) {
    json_error('error.generic', 405);
}
Csrf::check();

$body = json_body();
$orderId = (int) ($body['order_id'] ?? 0);
$orders = new OrderService();

try {
    switch ((string) ($body['action'] ?? '')) {
        case 'reprint':
            $orders->logReprint($orderId, $user['id']);
            json_response(['ok' => true, 'data' => [
                'print_url' => '/counter/print.php?type=bill&copy=1&id=' . $orderId,
            ]]);

        case 'cancel':
            $reason = (string) ($body['reason'] ?? '');
            if (!is_valid_name($reason, 200)) {
                json_error('cancel.error.reason');
            }
            $approval = Auth::verifyOwnerPin((string) ($body['pin'] ?? ''));
            if (!$approval['ok']) {
                json_error($approval['error'], 403);
            }
            $orders->cancel($orderId, $reason, $approval['owner']['id'], $user['id']);
            json_response(['ok' => true, 'data' => ['cancelled' => true]]);

        default:
            json_error('error.generic', 400);
    }
} catch (ValidationException $e) {
    json_error($e->first());
}
