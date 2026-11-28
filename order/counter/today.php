<?php

declare(strict_types=1);

// Counter Today tab: today's bills (reprint, cancel with owner PIN), day-close summary + print.
// Counter / takeaway orders are listed one by one; table orders are billed per table visit, so
// settled table bills get their own list.

use App\Auth;
use App\Services\OrderService;
use App\Services\ReportService;
use App\Services\TableService;

require __DIR__ . '/../boot.php';

$user = Auth::requireStaff();
$today = now();
$orders = array_values(array_filter((new OrderService())->forDay($today), static fn (array $o): bool => $o['order_type'] !== 'table'));
$tableBills = (new TableService())->settledOn($today);
$summary = (new ReportService())->daySummary($today);

$paymentLabel = static function (array $order): string {
    $modes = array_unique(array_column($order['payments'], 'mode'));
    if ($modes === []) {
        return '—';
    }
    return implode(' + ', array_map(static fn (string $mode): string => match ($mode) {
        'cash' => t('billing.pay_cash'),
        'upi_online' => t('print.upi_online'),
        default => t('billing.pay_upi'),
    }, $modes));
};
$typeLabel = static fn (array $order): string => match ($order['order_type']) {
    'takeaway' => t('print.takeaway') . ' · ' . $order['customer_name'],
    'bulk' => t('print.bulk') . ' · ' . $order['customer_name'],
    default => $order['service_type'] === 'parcel' ? t('billing.parcel') : t('billing.dine_in'),
};

$cancelReasons = [t('cancel.reason.wrong_bill'), t('cancel.reason.customer'), t('cancel.reason.duplicate')];
$texts = [
    'cancel_title' => t('today.cancel_title'),
    'cancelled' => t('today.cancelled_done'),
];

view('counter_head', ['title' => t('counter.tab.today')]);
view('counter_frame_start', ['user' => $user, 'active' => 'today']);
?>
<section class="counter-panel stack" data-today
         data-texts="<?= e(json_encode($texts, JSON_UNESCAPED_UNICODE)) ?>"
         data-cancel-reasons="<?= e(json_encode($cancelReasons, JSON_UNESCAPED_UNICODE)) ?>">
  <div class="summary-cards">
    <div class="summary-card summary-card--total"><span><?= e(t('today.total')) ?></span><strong><?= e(money($summary['total'])) ?></strong></div>
    <div class="summary-card"><span><?= e(t('billing.pay_cash')) ?></span><strong><?= e(money($summary['by_mode']['cash'])) ?></strong></div>
    <div class="summary-card"><span><?= e(t('billing.pay_upi')) ?></span><strong><?= e(money($summary['by_mode']['upi_counter'] + $summary['by_mode']['upi_online'])) ?></strong></div>
    <div class="summary-card"><span><?= e(t('today.bills')) ?></span><strong><?= (int) $summary['bill_count'] ?></strong></div>
    <div class="summary-card"><span><?= e(t('today.cancelled')) ?></span><strong><?= count($summary['cancelled']) ?></strong></div>
    <button type="button" class="counter-btn btn-go" data-print-day><?= e(t('today.print_summary')) ?></button>
  </div>

  <?php /* Per device (counter.js): on by default on the wide counter PC, off on phones. */ ?>
  <label class="printer-switch">
    <input type="checkbox" data-printer-toggle>
    <span><strong><?= e(t('counter.printer_device')) ?></strong><small><?= e(t('counter.printer_hint')) ?></small></span>
  </label>

  <?php if ($orders === [] && $tableBills === []): ?>
  <p class="muted"><?= e(t('today.empty')) ?></p>
  <?php endif; ?>

  <?php if ($tableBills !== []): ?>
  <h2><?= e(t('today.table_bills')) ?></h2>
  <table class="bills-table">
    <tbody>
      <?php foreach ($tableBills as $bill): $cancelled = (bool) $bill['is_cancelled']; ?>
      <tr<?= $cancelled ? ' class="is-cancelled"' : '' ?>>
        <td><strong><?= e($bill['bill_no']) ?></strong></td>
        <td><?= e(table_label($bill['table_code'])) ?></td>
        <td><?= e(format_time(substr($bill['closed_at'], 11, 5))) ?></td>
        <td class="num"><?= e(money(paise_from_decimal((string) $bill['total']))) ?></td>
        <td><?= $cancelled ? '<span class="badge badge-danger">' . e(t('today.cancelled_badge')) . '</span>' : e($paymentLabel($bill)) ?></td>
        <td><?= e($bill['staff_name'] ?? '') ?></td>
        <td class="row-actions">
          <button type="button" class="counter-btn" data-reprint-session="<?= (int) $bill['id'] ?>"><?= e(t('today.reprint')) ?></button>
          <?php if (!$cancelled): ?>
          <button type="button" class="counter-btn btn-danger-c" data-cancel-session="<?= (int) $bill['id'] ?>" data-bill="<?= e($bill['bill_no']) ?>"><?= e(t('today.cancel')) ?></button>
          <?php endif; ?>
        </td>
      </tr>
      <?php if ($cancelled && $bill['cancel_reason']): ?>
      <tr class="is-cancelled cancel-reason"><td colspan="7"><?= e(t('today.reason', ['reason' => $bill['cancel_reason']])) ?></td></tr>
      <?php endif; ?>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>

  <?php if ($orders !== []): ?>
  <?php if ($tableBills !== []): ?><h2><?= e(t('today.other_bills')) ?></h2><?php endif; ?>
  <table class="bills-table">
    <thead>
      <tr>
        <th><?= e(t('today.bill_no')) ?></th>
        <th><?= e(t('today.token')) ?></th>
        <th><?= e(t('today.time')) ?></th>
        <th><?= e(t('today.type')) ?></th>
        <th class="num"><?= e(t('billing.total')) ?></th>
        <th><?= e(t('today.payment')) ?></th>
        <th><?= e(t('today.staff')) ?></th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($orders as $order): $cancelled = $order['status'] === 'cancelled'; ?>
      <tr<?= $cancelled ? ' class="is-cancelled"' : '' ?>>
        <td><strong><?= e($order['bill_no'] ?? '—') ?></strong></td>
        <td><?= (int) $order['token_no'] ?></td>
        <td><?= e(format_time(substr($order['created_at'], 11, 5))) ?></td>
        <td><?= e($typeLabel($order)) ?></td>
        <td class="num"><?= e(money(paise_from_decimal((string) $order['total']))) ?></td>
        <td>
          <?php if ($cancelled): ?>
          <span class="badge badge-danger"><?= e(t('today.cancelled_badge')) ?></span>
          <?php elseif ($order['status'] !== 'completed'): ?>
          <span class="badge badge-muted"><?= e(order_status_label($order['status'])) ?></span>
          <?php else: ?>
          <?= e($paymentLabel($order)) ?>
          <?php endif; ?>
        </td>
        <td><?= e($order['staff_name'] ?? '') ?></td>
        <td class="row-actions">
          <?php if ($order['bill_no'] !== null): ?>
          <button type="button" class="counter-btn" data-reprint="<?= (int) $order['id'] ?>"><?= e(t('today.reprint')) ?></button>
          <?php endif; ?>
          <?php if (!$cancelled && $order['status'] !== 'no_show'): ?>
          <button type="button" class="counter-btn btn-danger-c" data-cancel="<?= (int) $order['id'] ?>" data-bill="<?= e($order['bill_no'] ?? '#' . $order['token_no']) ?>"><?= e(t('today.cancel')) ?></button>
          <?php endif; ?>
        </td>
      </tr>
      <?php if ($cancelled && $order['cancel_reason']): ?>
      <tr class="is-cancelled cancel-reason"><td colspan="8"><?= e(t('today.reason', ['reason' => $order['cancel_reason']])) ?></td></tr>
      <?php endif; ?>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</section>
<?php
view('counter_frame_end', ['scripts' => ['js/today.js']]);
