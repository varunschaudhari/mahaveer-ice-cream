<?php

declare(strict_types=1);

// Old address of the blocked-numbers page (M3): it is now a filter on admin → Customers (M7).

use App\Auth;

require __DIR__ . '/../boot.php';

Auth::requireAdmin();
redirect('/admin/customers.php?filter=blocked', 301);
