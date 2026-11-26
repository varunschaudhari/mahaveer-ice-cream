<?php

declare(strict_types=1);

// Counter poll, every 5 s on every counter tab: new online orders (badge + beep) and the
// connection check for the "No internet" banner. ?full=1 (Online tab) adds the active orders.
// 401 when the login has ended. Does not count as activity (idle counter still logs out).

use App\Auth;
use App\Services\OrderService;

require __DIR__ . '/../boot.php';

$user = Auth::user(false);
session_write_close();
if ($user === null) {
    json_error('auth.required', 401);
}

$today = now();
$orders = new OrderService();
$data = $orders->newOnlineSummary($today) + ['time' => $today->format('H:i')];

if (!empty($_GET['full'])) {
    $slotMins = max(5, App\Settings::int('slot_mins', 15));
    $data['orders'] = array_map(static function (array $order) use ($today, $slotMins): array {
        $pickup = new DateTimeImmutable($order['pickup_at']);
        return [
            'id' => (int) $order['id'],
            'token' => (int) $order['token_no'],
            'status' => $order['status'],
            'status_label' => order_status_label($order['status']),
            'slot' => format_time($pickup->format('H:i')) . ' – ' . format_time($pickup->modify("+{$slotMins} minutes")->format('H:i')),
            'customer' => (string) $order['customer_name'],
            'mobile' => (string) $order['customer_mobile'],
            'items' => array_map(static fn (array $line): array => [
                'name' => line_name($line['item_name']),
                'qty' => format_qty((string) $line['qty']),
            ], $order['items']),
            'total_paise' => paise_from_decimal((string) $order['total']),
            'note' => (string) ($order['note'] ?? ''),
            'age_mins' => max(0, intdiv($today->getTimestamp() - strtotime($order['created_at']), 60)),
        ];
    }, $orders->activeOnline($today));
}

json_response(['ok' => true, 'data' => $data]);
