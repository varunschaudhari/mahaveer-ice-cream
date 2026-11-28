<?php

declare(strict_types=1);

// Counter poll, every 5 s on every counter tab: new online orders and table alerts (badge + beep),
// table orders accepted elsewhere whose kitchen ticket this counter still has to print, and the
// connection check for the "No internet" banner. ?full=1 (Online tab) adds the active orders,
// ?view=tables the table tiles. 401 when the login has ended. Does not count as activity.
// ?view=owner (owner's phone, every 10 s, owner / super-admin only): today's sales, active
// orders, bulk orders and open table alerts.

use App\Auth;
use App\Services\BulkService;
use App\Services\OrderCard;
use App\Services\OrderService;
use App\Services\PrintQueueService;
use App\Services\ReportService;
use App\Services\TableService;
use App\Settings;

require __DIR__ . '/../boot.php';

$user = Auth::user(false);
session_write_close();
if ($user === null) {
    json_error('auth.required', 401);
}

$today = now();
$orders = new OrderService();
$data = $orders->newOnlineSummary($today) + [
    'time' => $today->format('H:i'),
    // The counter prints these KOTs unless it already did (e.g. the owner accepted on his phone).
    'kot_pending' => $orders->recentlyAcceptedTableOrders($today),
    // Bills sent from a phone; a counter screen that prints claims them (api/print_queue.php).
    'print_queue' => (new PrintQueueService())->pendingCount($today),
];

if (($_GET['view'] ?? '') === 'tables') {
    $data['tables'] = array_map(static fn (array $table): array => [
        'id' => $table['id'],
        'number' => table_number($table['code']),
        'outside' => $table['area'] === 'outside',
        'status' => $table['status'],
        'total_paise' => $table['total_paise'],
        'minutes' => $table['minutes'],
        'unsettled' => $table['status'] !== 'free' && $table['minutes'] >= Settings::int('table_unsettled_mins', 120),
        'unserved' => $table['unserved'],
        'alerts' => array_map(static fn (array $a): string => $a['type'], $table['alerts']),
    ], (new TableService())->overview($today));
}

if (($_GET['view'] ?? '') === 'owner') {
    if (!Auth::isAdmin($user)) {
        json_error('auth.required', 401); // a counter PIN login is not enough: back to the password login
    }
    $sales = (new ReportService())->daySummary($today);
    $data['owner'] = [
        'sales_paise' => $sales['total'],
        'bill_count' => $sales['bill_count'],
        'orders' => array_map(static fn (array $order): array => OrderCard::make($order, $today), $orders->activeOnline($today)),
        'bulk' => array_map(static fn (array $order): array => OrderCard::bulk($order), (new BulkService())->open()),
        'alerts' => array_map(static fn (array $alert): array => [
            'id' => (int) $alert['id'],
            'table' => table_number($alert['table_code']),
            'label' => $alert['type'] === 'request_bill' ? t('tables.alert.request_bill') : t('tables.alert.call_staff'),
            'time' => format_time(substr($alert['created_at'], 11, 5)),
        ], (new TableService())->openAlerts()),
    ];
}

if (!empty($_GET['full'])) {
    $data['orders'] = array_map(static fn (array $order): array => OrderCard::make($order, $today), $orders->activeOnline($today));
}

json_response(['ok' => true, 'data' => $data]);
