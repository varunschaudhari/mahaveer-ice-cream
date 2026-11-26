<?php

declare(strict_types=1);

// Customer status page polling (public, needs the order's secret key): GET ?id=…&k=…
// Takeaway and bulk orders. Label, help line and cancel button come from customer_status().

use App\Services\OrderService;

require __DIR__ . '/../boot.php';
session_write_close();

$order = (new OrderService())->findForCustomer((int) ($_GET['id'] ?? 0), (string) ($_GET['k'] ?? ''));
if ($order === null || !in_array($order['order_type'], ['takeaway', 'bulk'], true)) {
    json_error('error.not_found', 404);
}

$view = customer_status($order);
json_response(['ok' => true, 'data' => [
    'status' => $order['status'],
    'label' => $view['label'],
    'help' => $view['help'],
    'can_cancel' => $view['can_cancel'],
]]);
