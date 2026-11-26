<?php
/**
 * 80 mm bill (SPEC §10). Item names in Marathi; shop name and footer in both languages.
 * Vars: $order (OrderService::find), $copy (bool: reprint), $shop (settings).
 * @var array $order
 * @var bool $copy
 * @var array $shop
 */

$paise = static fn (string|int|float|null $decimal): int => paise_from_decimal((string) ($decimal ?? '0'));
$subtotal = $paise($order['subtotal']);
$parcel = $paise($order['parcel_charge']);
$discount = $paise($order['discount']);
$total = $paise($order['total']);
$roundOff = $total - ($subtotal + $parcel - $discount);

$type = match ($order['order_type']) {
    'takeaway' => t('print.takeaway'),
    'bulk' => t('print.bulk'),
    default => $order['service_type'] === 'parcel' ? t('billing.parcel') : t('billing.dine_in'),
};
$modeLabel = static fn (string $mode): string => $mode === 'cash' ? t('billing.pay_cash') : t('billing.pay_upi');
?>
<!doctype html>
<html lang="mr">
<head>
<meta charset="utf-8">
<title><?= e(t('print.bill_no')) ?> <?= e($order['bill_no']) ?></title>
<link rel="stylesheet" href="<?= e(asset('css/print.css')) ?>">
</head>
<body class="receipt">
<?php if ($order['status'] === 'cancelled'): ?>
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
  <span><?= e(t('print.token')) ?></span>
  <strong><?= (int) $order['token_no'] ?></strong>
</div>
<div class="receipt-row">
  <span><?= e(t('print.bill_no')) ?> <strong><?= e($order['bill_no']) ?></strong></span>
  <span><?= e(format_date_time($order['created_at'])) ?></span>
</div>
<div class="receipt-row">
  <span><strong><?= e($type) ?></strong></span>
  <span><?= e($order['staff_name'] ?? '') ?></span>
</div>

<table class="receipt-items">
  <thead>
    <tr><th><?= e(t('print.item')) ?></th><th class="num"><?= e(t('print.qty')) ?></th><th class="num"><?= e(t('print.rate')) ?></th><th class="num"><?= e(t('print.amount')) ?></th></tr>
  </thead>
  <tbody>
    <?php foreach ($order['items'] as $line): ?>
    <tr>
      <td><?= e(marathi_name($line['item_name'])) ?></td>
      <td class="num"><?= e(format_qty((string) $line['qty'])) ?></td>
      <td class="num"><?= e(rupees_input($paise($line['unit_price']))) ?></td>
      <td class="num"><?= e(rupees_input($paise($line['line_total']))) ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<table class="receipt-totals">
  <tr><td><?= e(t('billing.subtotal')) ?></td><td class="num"><?= e(money($subtotal)) ?></td></tr>
  <?php if ($parcel > 0): ?>
  <tr><td><?= e(t('billing.parcel_charge')) ?></td><td class="num"><?= e(money($parcel)) ?></td></tr>
  <?php endif; ?>
  <?php if ($discount > 0): ?>
  <tr><td><?= e(t('billing.discount')) ?></td><td class="num">−<?= e(money($discount)) ?></td></tr>
  <?php endif; ?>
  <?php if ($roundOff !== 0): ?>
  <tr><td><?= e(t('billing.round_off')) ?></td><td class="num"><?= e(money($roundOff)) ?></td></tr>
  <?php endif; ?>
  <tr class="receipt-grand"><td><?= e(t('billing.total')) ?></td><td class="num"><?= e(money($total)) ?></td></tr>
</table>

<?php if ($order['payments'] !== []): ?>
<p class="receipt-payments">
  <?php foreach ($order['payments'] as $payment): ?>
  <span><?= e($modeLabel($payment['mode'])) ?> <?= e(money($paise($payment['amount']))) ?></span>
  <?php endforeach; ?>
</p>
<?php endif; ?>

<footer class="receipt-foot">
  <p><?= e($shop['bill_footer_mr'] ?? '') ?></p>
  <p><?= e($shop['bill_footer_en'] ?? '') ?></p>
</footer>
</body>
</html>
