<?php

declare(strict_types=1);

// Counter actions on tables (staff). Body (JSON): action, …
//   move         order_id, to_table_id
//   dismiss      alert_id
//   settle       session_id, method, cash_paise, expected_total[, discount_paise (owner-approved)]
//   close        session_id            (nothing to bill: every order cancelled / moved)
//   reprint      session_id            (marked DUPLICATE, audit logged)
//   cancel_bill  session_id, reason, pin   (settled today; owner PIN)

use App\Auth;
use App\Csrf;
use App\Services\AuditService;
use App\Services\TableService;
use App\ValidationException;

require __DIR__ . '/../boot.php';

$user = Auth::requireStaff();
if (!is_post()) {
    json_error('error.generic', 405);
}
Csrf::check();

$body = json_body();
$tables = new TableService();
$sessionId = (int) ($body['session_id'] ?? 0);

try {
    switch ((string) ($body['action'] ?? '')) {
        case 'move':
            $tables->moveOrder((int) ($body['order_id'] ?? 0), (int) ($body['to_table_id'] ?? 0), $user['id']);
            json_response(['ok' => true, 'data' => ['moved' => true]]);

        case 'dismiss':
            $tables->dismissAlert((int) ($body['alert_id'] ?? 0), $user['id']);
            json_response(['ok' => true, 'data' => ['dismissed' => true]]);

        case 'settle':
            $discount = null;
            $discountPaise = (int) ($body['discount_paise'] ?? 0);
            if ($discountPaise > 0) {
                $approval = $_SESSION['discount_approval'] ?? null;
                if (!is_array($approval) || $approval['amount'] !== $discountPaise || $approval['expires'] < time()) {
                    json_error('discount.error.not_approved');
                }
                $discount = ['amount' => $discountPaise, 'reason' => $approval['reason'], 'owner_id' => $approval['owner_id']];
            }
            $session = $tables->settle(
                $sessionId,
                (string) ($body['method'] ?? ''),
                (int) ($body['cash_paise'] ?? 0),
                $discount,
                $user['id'],
                isset($body['expected_total']) ? (int) $body['expected_total'] : null,
            );
            if ($discount !== null) {
                unset($_SESSION['discount_approval']);
            }
            json_response(['ok' => true, 'data' => [
                'bill_no' => (int) $session['bill_no'],
                'print_url' => '/counter/print.php?type=table&id=' . $sessionId,
            ]]);

        case 'close':
            if (!$tables->closeIfEmpty($sessionId, $user['id'])) {
                json_error('table.error.not_empty');
            }
            json_response(['ok' => true, 'data' => ['closed' => true]]);

        case 'reprint':
            $session = $tables->findSession($sessionId);
            if ($session === null || $session['bill_no'] === null) {
                json_error('cancel.error.not_found', 404);
            }
            (new AuditService())->log($user['id'], 'bill_reprint', 'table_session', $sessionId, ['bill_no' => $session['bill_no']]);
            json_response(['ok' => true, 'data' => ['print_url' => '/counter/print.php?type=table&copy=1&id=' . $sessionId]]);

        case 'cancel_bill':
            $reason = (string) ($body['reason'] ?? '');
            if (!is_valid_name($reason, 200)) {
                json_error('cancel.error.reason');
            }
            $approval = Auth::verifyOwnerPin((string) ($body['pin'] ?? ''));
            if (!$approval['ok']) {
                json_error($approval['error'], 403);
            }
            $tables->cancelBill($sessionId, $reason, $approval['owner']['id'], $user['id']);
            json_response(['ok' => true, 'data' => ['cancelled' => true]]);

        default:
            json_error('error.generic', 400);
    }
} catch (ValidationException $e) {
    json_error($e->first());
}
