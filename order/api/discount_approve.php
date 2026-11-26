<?php

declare(strict_types=1);

// Owner approves a discount at the counter with his PIN. Body (JSON): amount_paise, reason, pin.
// The approval is kept in the session for 10 minutes and used once by api/counter_bill.php.

use App\Auth;
use App\Csrf;

require __DIR__ . '/../boot.php';

Auth::requireStaff();
if (!is_post()) {
    json_error('error.generic', 405);
}
Csrf::check();

$body = json_body();
$amount = filter_var($body['amount_paise'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 100]]);
$reason = trim((string) ($body['reason'] ?? ''));
if ($amount === false || $amount % 100 !== 0) {
    json_error('discount.error.amount');
}
if (!is_valid_name($reason, 100)) {
    json_error('discount.error.reason');
}

$result = Auth::verifyOwnerPin((string) ($body['pin'] ?? ''));
if (!$result['ok']) {
    json_error($result['error'], 403);
}

$_SESSION['discount_approval'] = [
    'amount' => $amount,
    'reason' => $reason,
    'owner_id' => $result['owner']['id'],
    'expires' => time() + 600,
];
json_response(['ok' => true, 'data' => [
    'amount_paise' => $amount,
    'reason' => $reason,
    'owner_name' => $result['owner']['name'],
]]);
