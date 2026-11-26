<?php

declare(strict_types=1);

// Creates the VAPID key pair for Web Push (ARCHITECTURE §9). Run ONCE per server and paste the
// output into that server's app/config.php. Keep production's keys forever: new keys make every
// subscribed phone stop receiving notifications until it subscribes again.
//
//   php database/vapid_keys.php
//
// Windows (local): PHP's OpenSSL needs OPENSSL_CONF set before PHP starts, e.g.
//   set OPENSSL_CONF=C:\xampp\php\extras\ssl\openssl.cnf   (cmd)   then run the command above.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/vendor/autoload.php';

try {
    $keys = Minishlink\WebPush\VAPID::createVapidKeys();
} catch (Throwable $e) {
    fwrite(STDERR, 'Could not create keys: ' . $e->getMessage() . "\n");
    if (PHP_OS_FAMILY === 'Windows') {
        fwrite(STDERR, "On Windows set OPENSSL_CONF first, e.g. C:\\xampp\\php\\extras\\ssl\\openssl.cnf\n");
    }
    exit(1);
}

echo "Put these in app/config.php:\n\n";
echo "    'VAPID_PUBLIC'  => '{$keys['publicKey']}',\n";
echo "    'VAPID_PRIVATE' => '{$keys['privateKey']}',\n";
echo "    'VAPID_SUBJECT' => 'mailto:<an email address the push services can contact>',\n";
