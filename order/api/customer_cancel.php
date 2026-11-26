<?php

declare(strict_types=1);

// Customer cancels their own order before the shop takes it on: a takeaway order still Received,
// or a bulk request still Pending approval. Body (JSON): id, k.

use App\Csrf;
use App\Services\OrderService;
use App\ValidationException;

require __DIR__ . '/../boot.php';

if (!is_post()) {
    json_error('error.generic', 405);
}
Csrf::check();

$body = json_body();
try {
    (new OrderService())->cancelByCustomer((int) ($body['id'] ?? 0), (string) ($body['k'] ?? ''));
} catch (ValidationException $e) {
    json_error($e->first());
}
json_response(['ok' => true, 'data' => ['status' => 'cancelled', 'label' => order_status_label('cancelled')]]);
