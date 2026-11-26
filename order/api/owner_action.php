<?php

declare(strict_types=1);

// Actions from the owner's phone (SPEC §11), owner / super-admin logged in with a password only.
// Body (JSON): action, order_id, …
//   accept                 takeaway / table order → preparing (the counter prints a table KOT itself)
//   cancel   reason        any open order; the password login counts as the owner's approval
//   approve  advance_paise bulk request → approved with that advance (₹0 allowed)

use App\Auth;
use App\Csrf;
use App\Services\BulkService;
use App\Services\OrderService;
use App\ValidationException;

require __DIR__ . '/../boot.php';

$user = Auth::requireAdmin();
if (!is_post()) {
    json_error('error.generic', 405);
}
Csrf::check();

$body = json_body();
$orderId = (int) ($body['order_id'] ?? 0);
$orders = new OrderService();
$order = $orders->find($orderId);
if ($order === null) {
    json_error('cancel.error.not_found', 404);
}
$isBulk = $order['order_type'] === 'bulk';

try {
    switch ((string) ($body['action'] ?? '')) {
        case 'accept':
            if ($isBulk) {
                json_error('error.generic', 400);
            }
            $orders->accept($orderId, $user['id']);
            json_response(['ok' => true, 'data' => ['message' => t('owner.accepted', ['token' => $order['token_no']])]]);

        case 'cancel':
            $reason = (string) ($body['reason'] ?? '');
            if ($isBulk) {
                (new BulkService())->cancel($orderId, $reason, $user['id']);
            } else {
                $orders->cancel($orderId, $reason, $user['id'], $user['id']);
            }
            json_response(['ok' => true, 'data' => ['message' => t('owner.cancelled', ['token' => $order['token_no']])]]);

        case 'approve':
            if (!$isBulk) {
                json_error('error.generic', 400);
            }
            (new BulkService())->approve($orderId, (int) ($body['advance_paise'] ?? -1), $user['id']);
            json_response(['ok' => true, 'data' => ['message' => t('owner.approved', ['name' => $order['customer_name']])]]);

        default:
            json_error('error.generic', 400);
    }
} catch (ValidationException $e) {
    json_error($e->first());
}
