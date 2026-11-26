<?php

declare(strict_types=1);

// Counter Bulk tab actions (staff). Body (JSON): action, order_id, …
//   payment   method ('cash' | 'upi'), amount_paise    money received before pickup (advance)
//   ready                                              preparing → ready
//   complete  method, cash_paise                       collect the balance → bill number → print URL

use App\Auth;
use App\Csrf;
use App\Services\BulkService;
use App\ValidationException;

require __DIR__ . '/../boot.php';

$user = Auth::requireStaff();
if (!is_post()) {
    json_error('error.generic', 405);
}
Csrf::check();

$body = json_body();
$orderId = (int) ($body['order_id'] ?? 0);
$bulk = new BulkService();

try {
    switch ((string) ($body['action'] ?? '')) {
        case 'payment':
            $order = $bulk->recordPayment($orderId, (string) ($body['method'] ?? ''), (int) ($body['amount_paise'] ?? 0), $user['id']);
            json_response(['ok' => true, 'data' => ['balance_paise' => BulkService::balance($order)]]);

        case 'ready':
            $bulk->markReady($orderId, $user['id']);
            json_response(['ok' => true, 'data' => ['status' => 'ready']]);

        case 'complete':
            $order = $bulk->complete($orderId, (string) ($body['method'] ?? ''), (int) ($body['cash_paise'] ?? 0), $user['id']);
            json_response(['ok' => true, 'data' => [
                'bill_no' => (int) $order['bill_no'],
                'print_url' => '/counter/print.php?type=bill&id=' . $orderId,
            ]]);

        default:
            json_error('error.generic', 400);
    }
} catch (ValidationException $e) {
    json_error($e->first());
}
