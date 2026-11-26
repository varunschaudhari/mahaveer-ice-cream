<?php

declare(strict_types=1);

// Customer orders at a table (public). Body (JSON): table_token, order_key, name, mobile, note,
// lines [{variant_id, qty}]. Limit: 10 orders per 10 minutes per IP (several rounds per table).

use App\Csrf;
use App\RateLimiter;
use App\Services\MenuService;
use App\Services\OrderService;
use App\Services\PushService;
use App\ValidationException;

require __DIR__ . '/../boot.php';

if (!is_post()) {
    json_error('error.generic', 405);
}
Csrf::check();

$limitKey = 'table_order:ip:' . client_ip();
if (RateLimiter::tooMany($limitKey, 10, 10)) {
    json_error('checkout.error.too_many', 429);
}

(new MenuService())->resetStockIfNewDay();
$body = json_body();
try {
    $order = (new OrderService())->placeTableOrder([
        'table_token' => $body['table_token'] ?? '',
        'order_key' => $body['order_key'] ?? '',
        'name' => $body['name'] ?? '',
        'mobile' => $body['mobile'] ?? '',
        'note' => $body['note'] ?? '',
        'lines' => $body['lines'] ?? [],
    ]);
} catch (ValidationException $e) {
    json_response([
        'ok' => false,
        'error' => is_array($e->first()) ? $e->first()[0] : $e->first(),
        'message' => error_text($e->first()),
        'fields' => array_map('error_text', $e->errors),
    ], 422);
}

if (!$order['duplicate']) {
    RateLimiter::hit($limitKey);
    notify_owners_later(PushService::forOrder($order)); // after the reply; the order is already saved
}
json_response(['ok' => true, 'data' => [
    'id' => (int) $order['id'],
    'token_no' => (int) $order['token_no'],
    'session_id' => (int) $order['table_session_id'],
]]);
