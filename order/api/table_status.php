<?php

declare(strict_types=1);

// The table page's order list (public, needs the table's QR code): GET ?t=<qr_token>.
// Orders of the table's current visit with their status, and the running total.

use App\Services\TableService;

require __DIR__ . '/../boot.php';
session_write_close();

$tables = new TableService();
$table = $tables->findByToken((string) ($_GET['t'] ?? ''));
if ($table === null) {
    json_error('table.error.invalid', 404);
}

$session = $tables->openSession((int) $table['id']);
$orders = $session === null ? [] : $tables->sessionOrders((int) $session['id']);

json_response(['ok' => true, 'data' => [
    'session_id' => $session === null ? null : (int) $session['id'],
    'bill_requested' => $session !== null && $session['status'] === 'bill_requested',
    'total_paise' => $session === null ? 0 : $tables->runningTotal((int) $session['id']),
    'orders' => array_map(static fn (array $o): array => [
        'token' => (int) $o['token_no'],
        'status' => $o['status'],
        'label' => order_status_label($o['status']),
        'items' => array_map(static fn (array $l): string => format_qty((string) $l['qty']) . ' × ' . line_name($l['item_name']), $o['items']),
        'total_paise' => paise_from_decimal((string) $o['total']),
    ], $orders),
]]);
