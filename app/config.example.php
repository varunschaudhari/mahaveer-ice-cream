<?php

declare(strict_types=1);

// Copy to app/config.php and fill in real values. config.php is gitignored and the
// deploy never overwrites it, so each server (local, staging, production) keeps its own.

return [
    'APP_ENV'         => 'local',                  // local | staging | production
    'APP_URL_ORDER'   => 'http://localhost:8000',  // https://order.mahaveericecream.in
    'APP_URL_WEBSITE' => 'http://localhost:8001',  // https://mahaveericecream.in

    'DB_HOST' => '127.0.0.1',
    'DB_NAME' => 'mahavir',
    'DB_USER' => 'root',
    'DB_PASS' => '',

    // Web Push (M6). Generate once, keep the same keys forever or existing subscriptions break.
    'VAPID_PUBLIC'  => '',
    'VAPID_PRIVATE' => '',
    'VAPID_SUBJECT' => 'mailto:owner@example.com',

    'SESSION_NAME' => 'mahavir_sid',
];
