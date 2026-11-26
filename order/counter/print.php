<?php

declare(strict_types=1);

// Print pages for the 80 mm Epson TM-T82, loaded by the counter in a hidden iframe and printed
// silently (Chrome --kiosk-printing):
//   print.php?type=bill&id=12[&copy=1]    bill of an order (copy=1 → DUPLICATE)
//   print.php?type=table&id=5[&copy=1]    combined bill of a settled table visit
//   print.php?type=kot&id=12              kitchen ticket of a table order
//   print.php?type=day[&date=Y-m-d]       day-close summary (default today)

use App\Auth;
use App\Lang;
use App\Services\BillFormatter;
use App\Services\OrderService;
use App\Services\ReportService;
use App\Services\TableService;
use App\Settings;

require __DIR__ . '/../boot.php';

Auth::requireStaff();
$shop = Settings::all();
$type = (string) ($_GET['type'] ?? 'bill');
$id = (int) ($_GET['id'] ?? 0);
$copy = !empty($_GET['copy']);

$notFound = static function (): never {
    http_response_code(404);
    exit;
};

if ($type === 'day') {
    $date = is_string($_GET['date'] ?? null) ? DateTimeImmutable::createFromFormat('!Y-m-d', $_GET['date'], new DateTimeZone('Asia/Kolkata')) : false;
    view('print_day', ['summary' => (new ReportService())->daySummary($date ?: now()), 'shop' => $shop]);
    exit;
}

Lang::set('mr'); // bills and kitchen tickets always print in Marathi (decision 2026-11-26)

if ($type === 'table') {
    $tables = new TableService();
    $session = $tables->findSession($id);
    if ($session === null || $session['bill_no'] === null) {
        $notFound();
    }
    $orders = $tables->sessionOrders($id);
    $cancelled = $orders !== [] && array_filter($orders, static fn (array $o): bool => $o['status'] !== 'cancelled') === [];
    $bill = BillFormatter::fromSession($session, $tables->combinedLines($id), $tables->sessionPayments($id), $cancelled);
    view('print_bill', ['bill' => $bill, 'copy' => $copy, 'shop' => $shop]);
    exit;
}

$order = (new OrderService())->find($id) ?? $notFound();

if ($type === 'kot') {
    if ($order['order_type'] !== 'table') {
        $notFound();
    }
    view('print_kot', ['order' => $order]);
    exit;
}

view('print_bill', ['bill' => BillFormatter::fromOrder($order), 'copy' => $copy, 'shop' => $shop]);
