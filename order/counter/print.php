<?php

declare(strict_types=1);

// Print pages for the 80 mm Epson TM-T82, loaded by the counter in a hidden iframe and printed
// silently (Chrome --kiosk-printing):
//   print.php?type=bill&id=12          bill
//   print.php?type=bill&id=12&copy=1   reprint, marked DUPLICATE
//   print.php?type=day[&date=Y-m-d]    day-close summary (default today)

use App\Auth;
use App\Lang;
use App\Services\OrderService;
use App\Services\ReportService;
use App\Settings;

require __DIR__ . '/../boot.php';

Auth::requireStaff();
$shop = Settings::all();

if (($_GET['type'] ?? 'bill') === 'day') {
    $date = is_string($_GET['date'] ?? null) ? DateTimeImmutable::createFromFormat('!Y-m-d', $_GET['date'], new DateTimeZone('Asia/Kolkata')) : false;
    view('print_day', [
        'summary' => (new ReportService())->daySummary($date ?: now()),
        'shop' => $shop,
    ]);
    exit;
}

$order = (new OrderService())->find((int) ($_GET['id'] ?? 0));
if ($order === null) {
    http_response_code(404);
    exit;
}

Lang::set('mr'); // bills always print in Marathi (decision 2026-11-26)
view('print_bill', ['order' => $order, 'copy' => !empty($_GET['copy']), 'shop' => $shop]);
