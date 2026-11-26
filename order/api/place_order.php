<?php

declare(strict_types=1);

// Customer places a takeaway order (public). Body (JSON): order_key, name, mobile, slot, note,
// lines [{variant_id, qty}]. Limits: 5 orders per 10 minutes per IP and per mobile number.

use App\Csrf;
use App\RateLimiter;
use App\RecentOrders;
use App\Services\MenuService;
use App\Services\OrderService;
use App\ValidationException;

require __DIR__ . '/../boot.php';

if (!is_post()) {
    json_error('error.generic', 405);
}
Csrf::check();

$body = json_body();
$mobile = normalize_mobile((string) ($body['mobile'] ?? ''));
$limitKeys = ['place_order:ip:' . client_ip()];
if ($mobile !== null) {
    $limitKeys[] = 'place_order:mobile:' . $mobile;
}
foreach ($limitKeys as $limitKey) {
    if (RateLimiter::tooMany($limitKey, 5, 10)) {
        json_error('checkout.error.too_many', 429);
    }
}

(new MenuService())->resetStockIfNewDay();
try {
    $order = (new OrderService())->placeTakeaway([
        'order_key' => $body['order_key'] ?? '',
        'name' => $body['name'] ?? '',
        'mobile' => $body['mobile'] ?? '',
        'slot' => $body['slot'] ?? '',
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
    foreach ($limitKeys as $limitKey) {
        RateLimiter::hit($limitKey);
    }
}
RecentOrders::remember((int) $order['id'], $order['order_key']);

json_response(['ok' => true, 'data' => [
    'id' => (int) $order['id'],
    'token_no' => (int) $order['token_no'],
    'status_url' => '/order.php?id=' . (int) $order['id'] . '&k=' . $order['order_key'],
]]);
