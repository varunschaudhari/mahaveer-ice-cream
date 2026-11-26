<?php

declare(strict_types=1);

// "Lock" button: ends the staff session and returns to the name + PIN screen.

use App\Auth;
use App\Csrf;

require __DIR__ . '/../boot.php';

if (is_post()) {
    Csrf::check();
    Auth::logout();
}
redirect('/counter/');
