<?php

declare(strict_types=1);

// Customer buttons on the table page (public): Call staff / Request bill.
// Body (JSON): t (table QR code), type ('call_staff' | 'request_bill'). One per 30 s per table.
// A new Request bill also notifies the owner's phone (ARCHITECTURE §9).

use App\Csrf;
use App\Services\PushService;
use App\Services\TableService;
use App\ValidationException;

require __DIR__ . '/../boot.php';

if (!is_post()) {
    json_error('error.generic', 405);
}
Csrf::check();

$body = json_body();
$tables = new TableService();
$table = $tables->findByToken((string) ($body['t'] ?? ''));
if ($table === null) {
    json_error('table.error.invalid', 404);
}

try {
    $added = $tables->raiseAlert((int) $table['id'], (string) ($body['type'] ?? ''), now());
} catch (ValidationException $e) {
    json_error($e->first());
}
if ($added && ($body['type'] ?? '') === 'request_bill') {
    notify_owners_later(PushService::forBillRequest((string) $table['code']));
}
json_response(['ok' => true, 'data' => [
    'message' => ($body['type'] ?? '') === 'request_bill' ? t('table.bill_requested') : t('table.staff_called'),
]]);
