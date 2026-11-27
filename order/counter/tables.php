<?php

declare(strict_types=1);

// Counter Tables tab (SPEC §9): 7 tiles (Free / Occupied / Bill requested, running total, minutes,
// alerts, unsettled warning). Tap a tile → orders, Served, Move, dismiss alerts, discount, Settle.

use App\Auth;

require __DIR__ . '/../boot.php';

$user = Auth::requireStaff();

$texts = [
    'free' => t('tables.free'),
    'occupied' => t('tables.occupied'),
    'bill_requested' => t('tables.bill_requested'),
    'outside' => t('table.outside'),
    'minutes' => t('tables.minutes'),
    'hours_minutes' => t('tables.hours_minutes'),
    'unsettled' => t('tables.unsettled'),
    'unserved' => t('tables.unserved'),
    'call_staff' => t('tables.alert.call_staff'),
    'request_bill' => t('tables.alert.request_bill'),
    'no_orders' => t('tables.no_orders'),
    'accept' => t('online.accept'),
    'serve' => t('online.serve'),
    'move' => t('tables.move'),
    'move_title' => t('tables.move_title'),
    'cancel' => t('online.cancel'),
    'cancel_title' => t('online.cancel_title'),
    'dismiss' => t('tables.dismiss'),
    'confirm_unserved' => t('tables.confirm_unserved'),
    'settled' => t('tables.settled_done'),
    'moved' => t('tables.moved_done'),
    'closed' => t('tables.closed_done'),
    'discount' => t('billing.discount'),
    'remove_discount' => t('billing.remove_discount'),
    'discount_amount' => t('billing.discount_amount'),
    'discount_reason' => t('billing.discount_reason'),
    'discount_approved' => t('billing.discount_approved'),
];
$discountReasons = [t('discount.reason.regular'), t('discount.reason.large_order'), t('discount.reason.staff'), t('discount.reason.complaint')];
$cancelReasons = [t('cancel.reason.customer_called'), t('cancel.reason.out_of_stock'), t('cancel.reason.duplicate')];

view('counter_head', ['title' => t('counter.tab.tables')]);
view('counter_frame_start', ['user' => $user, 'active' => 'tables']);
?>
<section class="counter-panel" data-tables
         data-texts="<?= e(json_encode($texts, JSON_UNESCAPED_UNICODE)) ?>"
         data-discount-reasons="<?= e(json_encode($discountReasons, JSON_UNESCAPED_UNICODE)) ?>"
         data-cancel-reasons="<?= e(json_encode($cancelReasons, JSON_UNESCAPED_UNICODE)) ?>">
  <p class="muted online-empty" data-tables-loading><?= e(t('online.loading')) ?></p>
  <div class="table-grid" data-table-grid></div>
</section>

<dialog class="counter-dialog table-dialog" data-table-dialog>
  <div class="spread">
    <h2 data-td-title></h2>
    <button type="button" class="counter-btn" data-td-close aria-label="<?= e(t('common.cancel')) ?>">×</button>
  </div>
  <div class="td-alerts" data-td-alerts></div>
  <div class="td-orders" data-td-orders></div>
  <dl class="totals">
    <div><dt><?= e(t('billing.subtotal')) ?></dt><dd data-td-subtotal></dd></div>
    <div data-td-discount-row hidden><dt><?= e(t('billing.discount')) ?> <span class="muted" data-td-discount-reason></span></dt><dd data-td-discount></dd></div>
    <div class="totals__grand"><dt><?= e(t('billing.total')) ?></dt><dd data-td-total></dd></div>
  </dl>
  <div class="dialog-actions">
    <button type="button" class="counter-btn" data-td-discount><?= e(t('billing.discount')) ?></button>
    <button type="button" class="counter-btn btn-go" data-td-settle><?= e(t('tables.settle')) ?></button>
  </div>
  <button type="button" class="counter-btn td-close-empty" data-td-close-empty hidden><?= e(t('tables.close_empty')) ?></button>
</dialog>
<?php
view('counter_frame_end', ['scripts' => ['js/tables.js']]);
