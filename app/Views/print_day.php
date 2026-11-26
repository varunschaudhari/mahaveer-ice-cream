<?php
/**
 * 80 mm day-close summary (SPEC §10): totals by payment mode, counts by order type, cancelled bills.
 * Vars: $summary (ReportService::daySummary), $shop.
 * @var array $summary
 * @var array $shop
 */
$date = DateTimeImmutable::createFromFormat('!Y-m-d', $summary['date'], new DateTimeZone('Asia/Kolkata'));
?>
<!doctype html>
<html lang="<?= e(lang()) ?>">
<head>
<meta charset="utf-8">
<title><?= e(t('print.day_title')) ?> <?= e($date->format('d/m/Y')) ?></title>
<link rel="stylesheet" href="<?= e(asset('css/print.css')) ?>">
</head>
<body class="receipt">
<header class="receipt-head">
  <p class="receipt-shop"><?= e(lang_field($shop, 'shop_name')) ?></p>
  <p><strong><?= e(t('print.day_title')) ?></strong></p>
  <p><?= e($date->format('d/m/Y')) ?></p>
</header>

<table class="receipt-totals">
  <tr><td><?= e(t('billing.pay_cash')) ?></td><td class="num"><?= e(money($summary['by_mode']['cash'])) ?></td></tr>
  <tr><td><?= e(t('billing.pay_upi')) ?></td><td class="num"><?= e(money($summary['by_mode']['upi_counter'])) ?></td></tr>
  <?php if ($summary['by_mode']['upi_online'] > 0): ?>
  <tr><td><?= e(t('print.upi_online')) ?></td><td class="num"><?= e(money($summary['by_mode']['upi_online'])) ?></td></tr>
  <?php endif; ?>
  <tr class="receipt-grand"><td><?= e(t('today.total')) ?></td><td class="num"><?= e(money($summary['total'])) ?></td></tr>
</table>

<table class="receipt-totals">
  <tr><td><?= e(t('today.bills')) ?></td><td class="num"><?= (int) $summary['bill_count'] ?></td></tr>
  <tr><td><?= e(t('print.counter_orders')) ?></td><td class="num"><?= (int) $summary['by_type']['counter'] ?></td></tr>
  <tr><td><?= e(t('print.takeaway')) ?></td><td class="num"><?= (int) $summary['by_type']['takeaway'] ?></td></tr>
  <tr><td><?= e(t('print.table_orders')) ?></td><td class="num"><?= (int) $summary['by_type']['table'] ?></td></tr>
  <tr><td><?= e(t('print.bulk')) ?></td><td class="num"><?= (int) $summary['by_type']['bulk'] ?></td></tr>
  <?php if ($summary['discount_total'] > 0): ?>
  <tr><td><?= e(t('print.discounts')) ?></td><td class="num"><?= e(money($summary['discount_total'])) ?></td></tr>
  <?php endif; ?>
</table>

<p class="receipt-section"><strong><?= e(t('today.cancelled')) ?>: <?= count($summary['cancelled']) ?></strong></p>
<?php if ($summary['cancelled'] !== []): ?>
<table class="receipt-items">
  <?php foreach ($summary['cancelled'] as $bill): ?>
  <tr>
    <td><?= e(t('print.bill_no')) ?> <?= e($bill['bill_no']) ?><br><span class="receipt-small"><?= e($bill['cancel_reason'] ?? '') ?></span></td>
    <td class="num"><?= e(money(paise_from_decimal((string) $bill['total']))) ?></td>
  </tr>
  <?php endforeach; ?>
</table>
<?php endif; ?>

<footer class="receipt-foot">
  <p class="receipt-small"><?= e(t('print.printed_at', ['time' => format_date_time(now())])) ?></p>
</footer>
</body>
</html>
