<?php

declare(strict_types=1);

// Actions on a saved order from the counter. Body (JSON): action, order_id, …
//   accept / ready          online order status (Online tab)
//   pickup  method, cash_paise   collect payment → bill number → returns the print URL
//   reprint                 logs the reprint, returns the print URL (marked DUPLICATE)
//   cancel  reason[, pin]   reason always; the owner PIN once the order is accepted (SPEC §3)

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
        case 'accept':
            $orders->accept($orderId, $user['id']);
            json_response(['ok' => true, 'data' => ['status' => 'preparing']]);

        case 'ready':
            $orders->markReady($orderId, $user['id']);
            json_response(['ok' => true, 'data' => ['status' => 'ready']]);

        case 'pickup':
            $order = $orders->pickup($orderId, (string) ($body['method'] ?? ''), (int) ($body['cash_paise'] ?? 0), $user['id']);
            json_response(['ok' => true, 'data' => [
                'bill_no' => (int) $order['bill_no'],
                'print_url' => '/counter/print.php?type=bill&id=' . $orderId,
            ]]);

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
            $ownerId = null;
            if ((string) ($body['pin'] ?? '') !== '') {
                $approval = Auth::verifyOwnerPin((string) $body['pin']);
                if (!$approval['ok']) {
                    json_error($approval['error'], 403);
                }
                $ownerId = $approval['owner']['id'];
            }
            $orders->cancel($orderId, $reason, $ownerId, $user['id']);
            json_response(['ok' => true, 'data' => ['cancelled' => true]]);

        default:
            json_error('error.generic', 400);
    }
} catch (ValidationException $e) {
    json_error($e->first());
}
