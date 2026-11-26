<?php

declare(strict_types=1);

// Counter Bulk tab (developer decision 2026-11-26): approved bulk orders by pickup time with
// Record payment (advance), Ready, Picked up (balance → bill). Pending requests are shown greyed
// until the owner approves them in admin.

use App\Auth;
use App\Services\BulkService;

require __DIR__ . '/../boot.php';

$user = Auth::requireStaff();
$open = (new BulkService())->open();
$approved = array_values(array_filter($open, static fn (array $o): bool => $o['status'] !== 'pending_approval'));
$pending = array_values(array_filter($open, static fn (array $o): bool => $o['status'] === 'pending_approval'));
$today = now()->format('Y-m-d');

$texts = [
    'amount_title' => t('bulk.counter.amount_title'),
    'method_title' => t('bulk.counter.method_title'),
    'cash' => t('billing.pay_cash'),
    'upi' => t('billing.pay_upi'),
    'paid' => t('bulk.counter.paid_done'),
    'ready' => t('bulk.counter.ready_done'),
    'completed' => t('bulk.counter.completed_done'),
    'confirm_free' => t('bulk.counter.confirm_prepaid'),
];

$card = static function (array $order, bool $actions) use ($today): void {
    $pickup = strtotime($order['pickup_at']);
    $balance = BulkService::balance($order);
    $isToday = date('Y-m-d', $pickup) === $today;
    ?>
    <article class="order-card bulk-card status-<?= e($order['status']) ?><?= $isToday ? ' is-today' : '' ?>">
      <header class="order-card__head">
        <span class="order-card__token"><?= e(date('d/m', $pickup)) ?> · <?= e(format_time(date('H:i', $pickup))) ?></span>
        <span class="order-card__status"><?= e($order['status'] === 'preparing' ? t('status.bulk_approved') : order_status_label($order['status'])) ?></span>
      </header>
      <p class="order-card__customer"><?= e($order['customer_name']) ?> <a class="order-card__mobile" href="tel:+91<?= e($order['customer_mobile']) ?>"><?= e($order['customer_mobile']) ?></a></p>
      <ul class="order-card__items">
        <?php foreach ($order['items'] as $line): ?>
        <li><?= e(format_qty((string) $line['qty'])) ?> × <?= e(line_name($line['item_name'])) ?></li>
        <?php endforeach; ?>
      </ul>
      <?php if (!empty($order['note'])): ?><p class="order-card__note"><?= e($order['note']) ?></p><?php endif; ?>
      <dl class="totals">
        <div><dt><?= e(t('billing.total')) ?></dt><dd><?= e(money(paise_from_decimal((string) $order['total']))) ?></dd></div>
        <?php if ($order['advance_amount'] !== null): ?>
        <div><dt><?= e(t('bulk.advance')) ?></dt><dd><?= e(money(paise_from_decimal((string) $order['advance_amount']))) ?></dd></div>
        <?php endif; ?>
        <div><dt><?= e(t('bulk.paid_so_far')) ?></dt><dd><?= e(money(paise_from_decimal((string) $order['amount_paid']))) ?></dd></div>
        <div class="totals__grand"><dt><?= e(t('bulk.balance')) ?></dt><dd><?= e(money($balance)) ?></dd></div>
      </dl>
      <?php if ($actions): ?>
      <div class="order-card__actions">
        <?php if ($balance > 0): ?>
        <button type="button" class="counter-btn" data-bulk-pay="<?= (int) $order['id'] ?>" data-due="<?= BulkService::advanceDue($order) ?: $balance ?>" data-balance="<?= $balance ?>"><?= e(t('bulk.counter.record_payment')) ?></button>
        <?php endif; ?>
        <?php if ($order['status'] === 'preparing'): ?>
        <button type="button" class="counter-btn btn-go" data-bulk-ready="<?= (int) $order['id'] ?>"><?= e(t('online.ready')) ?></button>
        <?php endif; ?>
        <button type="button" class="counter-btn btn-go" data-bulk-complete="<?= (int) $order['id'] ?>" data-balance="<?= $balance ?>"><?= e(t('online.picked_up')) ?></button>
      </div>
      <?php endif; ?>
    </article>
    <?php
};

view('counter_head', ['title' => t('counter.tab.bulk')]);
view('counter_frame_start', ['user' => $user, 'active' => 'bulk']);
?>
<section class="counter-panel stack" data-bulk-counter data-texts="<?= e(json_encode($texts, JSON_UNESCAPED_UNICODE)) ?>">
  <?php if ($approved === []): ?>
  <p class="muted online-empty"><?= e(t('bulk.counter.none')) ?></p>
  <?php else: ?>
  <div class="online-grid"><?php foreach ($approved as $order) { $card($order, true); } ?></div>
  <?php endif; ?>

  <?php if ($pending !== []): ?>
  <h2><?= e(t('bulk.counter.waiting_owner')) ?></h2>
  <div class="online-grid is-pending"><?php foreach ($pending as $order) { $card($order, false); } ?></div>
  <?php endif; ?>
</section>
<?php
view('counter_frame_end', ['scripts' => ['js/bulk_counter.js']]);
