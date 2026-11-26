<?php

declare(strict_types=1);

// Counter Tables tab → one table (staff): GET ?id=<table id>. Its open visit's orders, total,
// open alerts and the other tables (for "Move order").

use App\Auth;
use App\Services\TableService;

require __DIR__ . '/../boot.php';

Auth::requireStaff();
session_write_close();

$tables = new TableService();
$table = $tables->find((int) ($_GET['id'] ?? 0));
if ($table === null) {
    json_error('table.error.not_found', 404);
}
$session = $tables->openSession((int) $table['id']);
$orders = $session === null ? [] : $tables->sessionOrders((int) $session['id']);
$alerts = array_values(array_filter($tables->openAlerts(), static fn (array $a): bool => (int) $a['table_id'] === (int) $table['id']));

json_response(['ok' => true, 'data' => [
    'id' => (int) $table['id'],
    'label' => table_label($table['code'], $table['area']),
    'session_id' => $session === null ? null : (int) $session['id'],
    'bill_requested' => $session !== null && $session['status'] === 'bill_requested',
    'total_paise' => $session === null ? 0 : $tables->runningTotal((int) $session['id']),
    'orders' => array_map(static fn (array $o): array => [
        'id' => (int) $o['id'],
        'token' => (int) $o['token_no'],
        'status' => $o['status'],
        'label' => order_status_label($o['status']),
        'customer' => (string) ($o['customer_name'] ?? ''),
        'items' => array_map(static fn (array $l): string => format_qty((string) $l['qty']) . ' × ' . line_name($l['item_name']), $o['items']),
        'note' => (string) ($o['note'] ?? ''),
        'total_paise' => paise_from_decimal((string) $o['total']),
    ], $orders),
    'alerts' => array_map(static fn (array $a): array => [
        'id' => (int) $a['id'],
        'type' => $a['type'],
        'label' => $a['type'] === 'request_bill' ? t('tables.alert.request_bill') : t('tables.alert.call_staff'),
        'time' => format_time(substr($a['created_at'], 11, 5)),
    ], $alerts),
    'move_targets' => array_values(array_map(
        static fn (array $t): array => ['id' => (int) $t['id'], 'label' => table_label($t['code'], $t['area'])],
        array_filter($tables->tables(), static fn (array $t): bool => $t['is_active'] && (int) $t['id'] !== (int) $table['id'])
    )),
]]);
