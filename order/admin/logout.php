<?php

declare(strict_types=1);

use App\Auth;
use App\Csrf;

require __DIR__ . '/../boot.php';

if (is_post()) {
    Csrf::check();
    Auth::logout();
}
redirect('/admin/');
