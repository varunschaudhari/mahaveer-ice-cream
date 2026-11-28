<?php

declare(strict_types=1);

// The UPI payment QR of a pay-first takeaway order as a PNG (SPEC §6.10): shown on the status
// page and saved by the customer to scan from the gallery in their UPI app. Public, needs the
// order's secret key: GET ?id=…&k=…[&save=1]. Only while the order still waits for payment.

use App\Services\OrderService;
use App\Services\QrService;
use App\Services\UpiService;

require __DIR__ . '/boot.php';
session_write_close();

$order = (new OrderService())->findForCustomer((int) ($_GET['id'] ?? 0), (string) ($_GET['k'] ?? ''));
if ($order === null || $order['status'] !== 'pending_payment' || !UpiService::enabled()) {
    http_response_code(404);
    exit;
}

header('Content-Type: image/png');
header('Cache-Control: private, no-store');
if (!empty($_GET['save'])) {
    header('Content-Disposition: attachment; filename="mahavir-token-' . (int) $order['token_no'] . '.png"');
}
echo QrService::pngBinary(UpiService::linkForOrder($order));
