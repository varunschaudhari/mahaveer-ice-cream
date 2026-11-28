<?php

declare(strict_types=1);

// Print queue between a phone and the counter PC (PrintQueueService). Body (JSON):
//   {action: 'add', url: '/counter/print.php?type=bill&id=12'}   a phone sends a bill to the printer
//   {action: 'claim'}                                            a printing counter takes waiting jobs
// Any counter login (staff PIN or owner); POST + CSRF like every state-changing call.

use App\Auth;
use App\Csrf;
use App\Services\PrintQueueService;
use App\ValidationException;

require __DIR__ . '/../boot.php';

$user = Auth::requireStaff();
if (!is_post()) {
    json_error('error.generic', 405);
}
Csrf::check();

$body = json_body();
$queue = new PrintQueueService();
switch ((string) ($body['action'] ?? '')) {
    case 'add':
        try {
            $queue->add((string) ($body['url'] ?? ''), (int) $user['id']);
        } catch (ValidationException $e) {
            json_error($e->first());
        }
        json_response(['ok' => true, 'data' => ['queued' => true]]);
        break;
    case 'claim':
        json_response(['ok' => true, 'data' => ['urls' => $queue->claim(now())]]);
        break;
    default:
        json_error('error.generic', 400);
}
