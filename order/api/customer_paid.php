<?php

declare(strict_types=1);

// Customer taps "I have paid" on the status page of a pay-first takeaway order (SPEC §6). The
// counter then checks its soundbox / UPI app and confirms. Body (JSON): id, k.

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
    (new OrderService())->customerSaysPaid((int) ($body['id'] ?? 0), (string) ($body['k'] ?? ''));
} catch (ValidationException $e) {
    json_error($e->first());
}
json_response(['ok' => true, 'data' => []]);
