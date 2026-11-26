<?php

declare(strict_types=1);

// Counter connection check (every 15 s). 401 when the login has ended, so the screen returns
// to the PIN pad. Does not count as activity, so an idle counter still logs out after 12 h.

use App\Auth;

require __DIR__ . '/../boot.php';

$user = Auth::user(false);
session_write_close();

if ($user === null) {
    json_error('auth.required', 401);
}
json_response(['ok' => true, 'data' => ['time' => now()->format('H:i')]]);
