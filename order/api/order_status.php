<?php

declare(strict_types=1);

// Customer status page polling (public, needs the order's secret key): GET ?id=…&k=…

use App\Services\OrderService;

require __DIR__ . '/../boot.php';
session_write_close();

$order = (new OrderService())->findForCustomer((int) ($_GET['id'] ?? 0), (string) ($_GET['k'] ?? ''));
if ($order === null) {
    json_error('error.not_found', 404);
}

json_response(['ok' => true, 'data' => [
    'status' => $order['status'],
    'label' => order_status_label($order['status']),
    'can_cancel' => $order['order_type'] === 'takeaway' && $order['status'] === 'received',
]]);
