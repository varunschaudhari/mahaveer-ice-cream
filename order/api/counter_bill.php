<?php

declare(strict_types=1);

// Save a counter bill (Save & Print). Body (JSON): order_key, lines [{variant_id, qty}],
// service_type, payment_method, cash_paise, expected_total, discount_paise.
// A discount must have been approved with an owner PIN first (api/discount_approve.php).

use App\Auth;
use App\Csrf;
use App\Services\MenuService;
use App\Services\OrderService;
use App\ValidationException;

require __DIR__ . '/../boot.php';

$user = Auth::requireStaff();
if (!is_post()) {
    json_error('error.generic', 405);
}
Csrf::check();
(new MenuService())->resetStockIfNewDay();

$body = json_body();
$discount = null;
$discountPaise = (int) ($body['discount_paise'] ?? 0);
if ($discountPaise > 0) {
    $approval = $_SESSION['discount_approval'] ?? null;
    if (!is_array($approval) || $approval['amount'] !== $discountPaise || $approval['expires'] < time()) {
        json_error('discount.error.not_approved');
    }
    $discount = ['amount' => $discountPaise, 'reason' => $approval['reason'], 'owner_id' => $approval['owner_id']];
}

try {
    $order = (new OrderService())->createCounterOrder([
        'order_key' => $body['order_key'] ?? '',
        'lines' => $body['lines'] ?? [],
        'service_type' => $body['service_type'] ?? '',
        'payment_method' => $body['payment_method'] ?? '',
        'cash_paise' => (int) ($body['cash_paise'] ?? 0),
        'expected_total' => isset($body['expected_total']) ? (int) $body['expected_total'] : null,
        'discount' => $discount,
    ], $user['id']);
} catch (ValidationException $e) {
    json_error($e->first());
}

if ($discount !== null) {
    unset($_SESSION['discount_approval']); // one approval, one bill
}

json_response(['ok' => true, 'data' => [
    'id' => (int) $order['id'],
    'bill_no' => (int) $order['bill_no'],
    'token_no' => (int) $order['token_no'],
    'total_paise' => paise_from_decimal((string) $order['total']),
    'duplicate' => $order['duplicate'],
    'print_url' => '/counter/print.php?type=bill&id=' . (int) $order['id'],
]]);
