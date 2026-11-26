<?php

declare(strict_types=1);

// Customer buttons on the table page (public): Call staff / Request bill.
// Body (JSON): t (table QR code), type ('call_staff' | 'request_bill'). One per 30 s per table.

use App\Csrf;
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
    $tables->raiseAlert((int) $table['id'], (string) ($body['type'] ?? ''), now());
} catch (ValidationException $e) {
    json_error($e->first());
}
json_response(['ok' => true, 'data' => [
    'message' => ($body['type'] ?? '') === 'request_bill' ? t('table.bill_requested') : t('table.staff_called'),
]]);
