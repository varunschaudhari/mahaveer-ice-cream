<?php

declare(strict_types=1);

// Customer bulk / party order request (public). Body (JSON): order_key, name, mobile, note,
// event_date, pickup_time, lines [{variant_id, qty}] (qty may be decimal). Limit: 3 requests per
// hour per IP and per mobile number.

use App\Csrf;
use App\RateLimiter;
use App\RecentOrders;
use App\Services\BulkService;
use App\Services\PushService;
use App\ValidationException;

require __DIR__ . '/../boot.php';

if (!is_post()) {
    json_error('error.generic', 405);
}
Csrf::check();

$body = json_body();
$mobile = normalize_mobile((string) ($body['mobile'] ?? ''));
$limitKeys = ['bulk_request:ip:' . client_ip()];
if ($mobile !== null) {
    $limitKeys[] = 'bulk_request:mobile:' . $mobile;
}
foreach ($limitKeys as $limitKey) {
    if (RateLimiter::tooMany($limitKey, 3, 60)) {
        json_error('checkout.error.too_many', 429);
    }
}

try {
    $order = (new BulkService())->request([
        'order_key' => $body['order_key'] ?? '',
        'name' => $body['name'] ?? '',
        'mobile' => $body['mobile'] ?? '',
        'note' => $body['note'] ?? '',
        'event_date' => $body['event_date'] ?? '',
        'pickup_time' => $body['pickup_time'] ?? '',
        'lines' => $body['lines'] ?? [],
    ]);
} catch (ValidationException $e) {
    $fields = array_map('error_text', $e->errors);
    if (isset($fields['_']) && str_starts_with((string) (is_array($e->first()) ? $e->first()[0] : $e->first()), 'cart.')) {
        $fields['lines'] = $fields['_']; // cart problems show next to the items
        unset($fields['_']);
    }
    json_response([
        'ok' => false,
        'error' => is_array($e->first()) ? $e->first()[0] : $e->first(),
        'message' => error_text($e->first()),
        'fields' => $fields,
    ], 422);
}

if (!$order['duplicate']) {
    foreach ($limitKeys as $limitKey) {
        RateLimiter::hit($limitKey);
    }
    notify_owners_later(PushService::forOrder($order)); // after the reply; the request is already saved
}
RecentOrders::remember((int) $order['id'], $order['order_key']);

json_response(['ok' => true, 'data' => [
    'id' => (int) $order['id'],
    'status_url' => '/order.php?id=' . (int) $order['id'] . '&k=' . $order['order_key'],
]]);
