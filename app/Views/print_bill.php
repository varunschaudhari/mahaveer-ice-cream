<?php
/**
 * 80 mm bill (SPEC §10). Item names in Marathi; shop name and footer in both languages.
 * Vars: $bill (BillFormatter::fromOrder / ::fromSession), $copy (bool: reprint), $shop (settings).
 * @var array $bill
 * @var bool $copy
 * @var array $shop
 */
$modeLabel = static fn (string $mode): string => match ($mode) {
    'cash' => t('billing.pay_cash'),
    'upi_online' => t('print.upi_online'), // paid in advance (pay-first takeaway)
    default => t('billing.pay_upi'),
};
?>
<!doctype html>
<html lang="mr">
<head>
<meta charset="utf-8">
<title><?= e(t('print.bill_no')) ?> <?= e($bill['number']) ?></title>
<link rel="stylesheet" href="<?= e(asset('css/print.css')) ?>">
</head>
<body class="receipt">
<?php if ($bill['cancelled']): ?>
<p class="receipt-flag"><?= e(t('print.cancelled')) ?></p>
<?php elseif ($copy): ?>
<p class="receipt-flag"><?= e(t('print.duplicate')) ?></p>
<?php endif; ?>

<header class="receipt-head">
  <p class="receipt-shop"><?= e($shop['shop_name_mr'] ?? '') ?></p>
  <p class="receipt-shop-en"><?= e($shop['shop_name_en'] ?? '') ?></p>
  <p><?= e($shop['shop_address_mr'] ?? '') ?></p>
  <p><?= e(t('print.phone')) ?> <?= e($shop['shop_phone'] ?? '') ?></p>
</header>

<div class="receipt-token">
  <span><?= e($bill['headline_label']) ?></span>
  <strong><?= e($bill['headline']) ?></strong>
</div>
<div class="receipt-row">
  <span><?= e(t('print.bill_no')) ?> <strong><?= e($bill['number']) ?></strong></span>
  <span><?= e(format_date_time($bill['at'])) ?></span>
</div>
<div class="receipt-row">
  <span><strong><?= e($bill['type']) ?></strong></span>
  <span><?= e($bill['staff']) ?></span>
</div>

<table class="receipt-items">
  <thead>
    <tr><th><?= e(t('print.item')) ?></th><th class="num"><?= e(t('print.qty')) ?></th><th class="num"><?= e(t('print.rate')) ?></th><th class="num"><?= e(t('print.amount')) ?></th></tr>
  </thead>
  <tbody>
    <?php foreach ($bill['lines'] as $line): ?>
    <tr>
      <td><?= e($line['name']) ?></td>
      <td class="num"><?= e($line['qty']) ?></td>
      <td class="num"><?= e(rupees_input($line['rate'])) ?></td>
      <td class="num"><?= e(rupees_input($line['amount'])) ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<table class="receipt-totals">
  <tr><td><?= e(t('billing.subtotal')) ?></td><td class="num"><?= e(money($bill['subtotal'])) ?></td></tr>
  <?php if ($bill['parcel'] > 0): ?>
  <tr><td><?= e(t('billing.parcel_charge')) ?></td><td class="num"><?= e(money($bill['parcel'])) ?></td></tr>
  <?php endif; ?>
  <?php if ($bill['discount'] > 0): ?>
  <tr><td><?= e(t('billing.discount')) ?></td><td class="num">−<?= e(money($bill['discount'])) ?></td></tr>
  <?php endif; ?>
  <?php if ($bill['round_off'] !== 0): ?>
  <tr><td><?= e(t('billing.round_off')) ?></td><td class="num"><?= e(money($bill['round_off'])) ?></td></tr>
  <?php endif; ?>
  <tr class="receipt-grand"><td><?= e(t('billing.total')) ?></td><td class="num"><?= e(money($bill['total'])) ?></td></tr>
  <?php foreach ($bill['extras'] ?? [] as $extra): ?>
  <tr><td><?= e($extra['label']) ?></td><td class="num"><?= e($extra['value']) ?></td></tr>
  <?php endforeach; ?>
</table>

<?php if ($bill['payments'] !== []): ?>
<p class="receipt-payments">
  <?php foreach ($bill['payments'] as $payment): ?>
  <span><?= e($modeLabel($payment['mode'])) ?> <?= e(money($payment['amount'])) ?></span>
  <?php endforeach; ?>
</p>
<?php endif; ?>

<footer class="receipt-foot">
  <p><?= e($shop['bill_footer_mr'] ?? '') ?></p>
  <p><?= e($shop['bill_footer_en'] ?? '') ?></p>
</footer>
</body>
</html>
