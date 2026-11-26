<?php

declare(strict_types=1);

// Customer order status (SPEC §6.7): order.php?id=…&k=<secret order_key>. Live status every
// 10 s (status.js). The customer may cancel while the order is still Received.

use App\Services\OrderService;
use App\Settings;

require __DIR__ . '/boot.php';

$order = (new OrderService())->findForCustomer((int) ($_GET['id'] ?? 0), (string) ($_GET['k'] ?? ''));
if ($order === null || $order['order_type'] !== 'takeaway') {
    http_response_code(404);
    view('error', ['message' => t('status.not_found')]);
    exit;
}

$shop = Settings::all();
$slotMins = max(5, Settings::int('slot_mins', 15));
$pickup = new DateTimeImmutable($order['pickup_at']);
$slotLabel = format_time($pickup->format('H:i')) . ' – ' . format_time($pickup->modify("+{$slotMins} minutes")->format('H:i'));
$token = (int) $order['token_no'];

$help = [
    'received' => t('status.help.received'),
    'preparing' => t('status.help.preparing'),
    'ready' => t('status.help.ready', ['token' => $token]),
    'completed' => t('status.help.completed'),
    'cancelled' => t('status.help.cancelled'),
    'no_show' => t('status.help.no_show'),
];
$steps = ['received', 'preparing', 'ready', 'completed'];

view('header', ['title' => t('status.title', ['token' => $token]), 'css' => ['css/base.css', 'css/shop.css']]);
?>
<section class="status-page stack" data-status
         data-id="<?= (int) $order['id'] ?>" data-key="<?= e($order['order_key']) ?>"
         data-current="<?= e($order['status']) ?>"
         data-help="<?= e(json_encode($help, JSON_UNESCAPED_UNICODE)) ?>"
         data-confirm="<?= e(t('status.cancel_confirm')) ?>">
  <div class="status-token">
    <span><?= e(t('print.token')) ?></span>
    <strong><?= $token ?></strong>
  </div>

  <p class="status-label status-<?= e($order['status']) ?>" data-status-label><?= e(order_status_label($order['status'])) ?></p>
  <ol class="status-steps" data-steps>
    <?php foreach ($steps as $step): ?>
    <li data-step="<?= e($step) ?>"><?= e(order_status_label($step)) ?></li>
    <?php endforeach; ?>
  </ol>
  <p class="notice" data-status-help><?= e($help[$order['status']] ?? '') ?></p>

  <div class="card stack-sm">
    <p><strong><?= e(t('checkout.slot')) ?>:</strong> <?= e($slotLabel) ?></p>
    <ul class="status-items">
      <?php foreach ($order['items'] as $line): ?>
      <li><span><?= e(format_qty((string) $line['qty'])) ?> × <?= e(line_name($line['item_name'])) ?></span><span><?= e(money(paise_from_decimal((string) $line['line_total']))) ?></span></li>
      <?php endforeach; ?>
      <li><span><?= e(t('billing.parcel_charge')) ?></span><span><?= e(money(paise_from_decimal((string) $order['parcel_charge']))) ?></span></li>
      <li class="status-items__total"><span><?= e(t('billing.total')) ?></span><span><?= e(money(paise_from_decimal((string) $order['total']))) ?></span></li>
    </ul>
    <p class="muted"><?= e(t('checkout.pay_at_counter')) ?></p>
    <?php if (!empty($order['note'])): ?><p class="muted"><?= e(t('checkout.note')) ?>: <?= e($order['note']) ?></p><?php endif; ?>
  </div>

  <button type="button" class="btn btn-danger btn-block" data-cancel-order<?= $order['status'] === 'received' ? '' : ' hidden' ?>>
    <?= e(t('status.cancel')) ?>
  </button>
  <p class="flash flash-error" data-status-error role="alert" hidden></p>

  <p class="text-center">
    <?= e(t('status.questions')) ?>
    <a href="tel:<?= e(preg_replace('/[^\d+]/', '', $shop['shop_phone'] ?? '')) ?>"><?= e($shop['shop_phone'] ?? '') ?></a>
  </p>
  <p class="text-center"><a class="btn btn-secondary" href="/"><?= e(t('status.order_more')) ?></a></p>
</section>
<script src="<?= e(asset('js/status.js')) ?>"></script>
<?php
view('footer');
