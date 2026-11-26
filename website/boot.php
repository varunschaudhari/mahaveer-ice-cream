<?php
// Public website: no sessions (see app/bootstrap.php).
const APP_NO_SESSION = true;

// Locally and for order/ in production, app/ sits next to this web root.
// For website/ deployed into public_html, the deploy writes approot.php returning the real path.
$root = is_file(__DIR__ . '/approot.php') ? require __DIR__ . '/approot.php' : dirname(__DIR__);
require $root . '/app/bootstrap.php';
