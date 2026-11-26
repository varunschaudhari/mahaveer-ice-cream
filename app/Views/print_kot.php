<?php
/**
 * Kitchen ticket (KOT) for a table order, printed on Accept (SPEC §10): the table number large,
 * order token, time, items and quantities, note. No prices.
 * Var: $order (OrderService::find with table_code).
 * @var array $order
 */
$number = table_number((string) $order['table_code']);
?>
<!doctype html>
<html lang="mr">
<head>
<meta charset="utf-8">
<title>KOT <?= e($order['token_no']) ?></title>
<link rel="stylesheet" href="<?= e(asset('css/print.css')) ?>">
</head>
<body class="receipt kot">
<p class="kot-table"><?= e(t('print.kot_table', ['n' => $number])) ?></p>
<p class="kot-table-en">TABLE <?= e($number) ?></p>
<div class="receipt-row">
  <span><?= e(t('print.token')) ?> <strong><?= (int) $order['token_no'] ?></strong></span>
  <span><?= e(format_time(substr((string) $order['created_at'], 11, 5))) ?></span>
</div>
<table class="receipt-items kot-items">
  <tbody>
    <?php foreach ($order['items'] as $line): ?>
    <tr>
      <td class="kot-qty"><?= e(format_qty((string) $line['qty'])) ?> ×</td>
      <td><?= e(marathi_name($line['item_name'])) ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<?php if (!empty($order['note'])): ?>
<p class="kot-note"><?= e($order['note']) ?></p>
<?php endif; ?>
<?php if (!empty($order['customer_name'])): ?>
<p class="receipt-small"><?= e($order['customer_name']) ?></p>
<?php endif; ?>
</body>
</html>
