<?php

declare(strict_types=1);

// Actions on a saved order from the counter. Body (JSON): action, order_id, …
//   accept / ready / serve  online + table order status (accept on a table order → KOT print URL)
//   pickup  method, cash_paise   collect payment → bill number → returns the print URL
//   reprint                 logs the reprint, returns the print URL (marked DUPLICATE)
//   confirm_payment         pay-first: the UPI money arrived → Preparing; notifies the owner
//   payment_missing         pay-first: no money found → cancelled (no owner PIN: nothing was paid)
//   cancel  reason[, pin]   reason always; the owner PIN once the order is accepted (SPEC §3)

use App\Auth;
use App\Csrf;
use App\Services\OrderService;
use App\Services\PushService;
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
            $isTable = ($orders->find($orderId)['order_type'] ?? '') === 'table';
            json_response(['ok' => true, 'data' => [
                'status' => 'preparing',
                // Table orders print a kitchen ticket on Accept (SPEC §10); takeaway does not.
                'print_url' => $isTable ? '/counter/print.php?type=kot&id=' . $orderId : null,
            ]]);

        case 'serve':
            $orders->markServed($orderId, $user['id']);
            json_response(['ok' => true, 'data' => ['status' => 'served']]);

        case 'ready':
            $orders->markReady($orderId, $user['id']);
            json_response(['ok' => true, 'data' => ['status' => 'ready']]);

        case 'pickup':
            $order = $orders->pickup($orderId, (string) ($body['method'] ?? ''), (int) ($body['cash_paise'] ?? 0), $user['id']);
            json_response(['ok' => true, 'data' => [
                'bill_no' => (int) $order['bill_no'],
                'print_url' => '/counter/print.php?type=bill&id=' . $orderId,
            ]]);

        case 'confirm_payment':
            $order = $orders->confirmPayment($orderId, $user['id']);
            notify_owners_later(PushService::forOrder($order));
            json_response(['ok' => true, 'data' => ['status' => 'preparing']]);

        case 'payment_missing':
            $orders->paymentMissing($orderId, $user['id']);
            json_response(['ok' => true, 'data' => ['cancelled' => true]]);

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
