<?php

declare(strict_types=1);

// Stock tab switch: mark an item sold out / available for today. Body (JSON): item_id, available.

use App\Auth;
use App\Csrf;
use App\Services\MenuService;
use App\ValidationException;

require __DIR__ . '/../boot.php';

$user = Auth::requireStaff();
if (!is_post()) {
    json_error('error.generic', 405);
}
Csrf::check();

$body = json_body();
$menu = new MenuService();
$menu->resetStockIfNewDay();
try {
    $menu->setAvailability((int) ($body['item_id'] ?? 0), (bool) ($body['available'] ?? false), $user['id']);
} catch (ValidationException $e) {
    json_error($e->first());
}
json_response(['ok' => true, 'data' => ['available' => (bool) ($body['available'] ?? false)]]);
