<?php

declare(strict_types=1);

// Customer order status (SPEC §6.7, §8): order.php?id=…&k=<secret order_key>, for takeaway and
// bulk orders. Live status every 10 s (status.js). The customer may cancel while the order is
// still Received (takeaway) or Pending approval (bulk). A pay-first takeaway order (SPEC §6) shows
// the UPI payment card until the counter confirms the money: pay button (opens the customer's UPI
// app), "I have paid", and a QR code + UPI ID for paying from another phone.

use App\Services\BulkService;
use App\Services\OrderService;
use App\Services\QrService;
use App\Services\UpiService;
use App\Settings;

require __DIR__ . '/boot.php';

$order = (new OrderService())->findForCustomer((int) ($_GET['id'] ?? 0), (string) ($_GET['k'] ?? ''));
if ($order === null || !in_array($order['order_type'], ['takeaway', 'bulk'], true)) {
    http_response_code(404);
    view('error', ['message' => t('status.not_found')]);
    exit;
}

$isBulk = $order['order_type'] === 'bulk';
$shop = Settings::all();
$token = (int) $order['token_no'];
$view = customer_status($order);
$totalPaise = paise_from_decimal((string) $order['total']);
$waitingPay = $order['status'] === 'pending_payment';
$payClaimed = $waitingPay && OrderService::hasPaymentClaim($order);
$upiLink = $waitingPay && UpiService::enabled() ? UpiService::linkForOrder($order) : '';
$pickup = new DateTimeImmutable($order['pickup_at']);
if ($isBulk) {
    $whenLabel = t('bulk.event_date');
    $when = $pickup->format('d/m/Y') . ', ' . format_time($pickup->format('H:i'));
} else {
    $slotMins = max(5, Settings::int('slot_mins', 15));
    $whenLabel = t('checkout.slot');
    $when = format_time($pickup->format('H:i')) . ' – ' . format_time($pickup->modify("+{$slotMins} minutes")->format('H:i'));
}

view('header', ['title' => t('status.title', ['token' => $token]), 'css' => ['css/base.css', 'css/shop.css']]);
?>
<section class="status-page" data-status
         data-id="<?= (int) $order['id'] ?>" data-key="<?= e($order['order_key']) ?>"
         data-current="<?= e($order['status']) ?>"
         data-steps="<?= e(json_encode(array_column($view['steps'], 'key'))) ?>"
         data-confirm="<?= e(t('status.cancel_confirm')) ?>">
  <div class="status-hero">
    <?php if ($isBulk): ?><p class="status-hero__kind"><?= e(t('bulk.heading')) ?></p><?php endif; ?>
    <div class="status-token">
      <span><?= e(t('print.token')) ?></span>
      <strong><?= $token ?></strong>
    </div>
    <p class="status-label status-<?= e($order['status']) ?>" data-status-label><?= e($view['label']) ?></p>
  </div>

  <?php if ($upiLink !== ''): ?>
  <div class="card pay-card<?= $payClaimed ? ' is-claimed' : '' ?>">
    <?php if ($payClaimed): ?>
    <p class="pay-card__checking"><?= e(t('status.pay_checking_note')) ?></p>
    <a class="pay-card__again" href="<?= e($upiLink) ?>"><?= e(t('status.pay_open_again')) ?></a>
    <?php else: ?>
    <p class="pay-card__amount"><span><?= e(t('status.pay_amount')) ?></span><strong><?= e(money($totalPaise)) ?></strong></p>
    <a class="btn btn-primary btn-block btn-lg" href="<?= e($upiLink) ?>"><?= e(t('status.pay_with_upi')) ?></a>
    <p class="pay-card__apps"><?= e(t('status.pay_apps')) ?></p>
    <button type="button" class="btn btn-secondary btn-block btn-lg" data-paid><?= e(t('status.i_have_paid')) ?></button>
    <details class="pay-card__other">
      <summary><?= e(t('status.pay_other_phone')) ?></summary>
      <img class="pay-card__qr" src="<?= e(QrService::svgDataUri($upiLink)) ?>" alt="<?= e(t('status.pay_qr_alt')) ?>" width="220" height="220">
      <p class="pay-card__id"><span><?= e(t('status.upi_id')) ?></span> <strong data-upi-id><?= e(UpiService::shopId()) ?></strong>
        <button type="button" class="btn btn-ghost pay-card__copy" data-copy data-copied="<?= e(t('status.copied')) ?>"><?= e(t('status.copy')) ?></button></p>
    </details>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="card status-progress">
    <ol class="status-steps" data-steps-list>
      <?php foreach ($view['steps'] as $step): ?>
      <li data-step="<?= e($step['key']) ?>"><span class="status-steps__dot" aria-hidden="true"></span><span class="status-steps__text"><?= e($step['label']) ?></span></li>
      <?php endforeach; ?>
    </ol>
    <p class="info-note" data-status-help><?= e($view['help']) ?></p>
  </div>

  <div class="card status-details">
    <p class="status-when">
      <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="2"/><path d="M12 7v5l3 2" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
      <span><span class="muted"><?= e($whenLabel) ?></span> <strong><?= e($when) ?></strong></span>
    </p>
    <ul class="status-items">
      <?php foreach ($order['items'] as $line): ?>
      <li><span><?= e(format_qty((string) $line['qty'])) ?> × <?= e(line_name($line['item_name'])) ?></span><span><?= e(money(paise_from_decimal((string) $line['line_total']))) ?></span></li>
      <?php endforeach; ?>
      <?php if (!$isBulk): ?>
      <li class="muted"><span><?= e(t('billing.parcel_charge')) ?></span><span><?= e(money(paise_from_decimal((string) $order['parcel_charge']))) ?></span></li>
      <?php endif; ?>
      <li class="status-items__total"><span><?= e(t('billing.total')) ?></span><span><?= e(money($totalPaise)) ?></span></li>
      <?php if ($isBulk && paise_from_decimal((string) $order['amount_paid']) > 0): ?>
      <li><span><?= e(t('bulk.paid_so_far')) ?></span><span><?= e(money(paise_from_decimal((string) $order['amount_paid']))) ?></span></li>
      <li><span><?= e(t('bulk.balance')) ?></span><span><?= e(money(BulkService::balance($order))) ?></span></li>
      <?php endif; ?>
    </ul>
    <?php if ($isBulk): ?>
    <p class="muted text-sm"><?= e(t('bulk.price_note')) ?></p>
    <?php elseif (OrderService::isPrepaid($order)): ?>
    <p class="pay-done text-sm"><?= e(t('status.paid_upi', ['amount' => money($totalPaise)])) ?></p>
    <?php elseif ($order['payment_pref'] !== 'online'): ?>
    <p class="muted text-sm"><?= e(t('checkout.pay_at_counter')) ?></p>
    <?php endif; ?>
    <?php if (!empty($order['note'])): ?><p class="muted text-sm"><?= e(t('checkout.note')) ?>: <?= e($order['note']) ?></p><?php endif; ?>
  </div>

  <button type="button" class="btn btn-danger-soft btn-block" data-cancel-order<?= $view['can_cancel'] ? '' : ' hidden' ?>>
    <?= e(t('status.cancel')) ?>
  </button>
  <p class="flash flash-error" data-status-error role="alert" hidden></p>

  <div class="status-actions">
    <a class="btn btn-primary btn-block btn-lg" href="/"><?= e(t('status.order_more')) ?></a>
    <?php if (config('APP_URL_WEBSITE', '') !== ''): ?>
    <a class="status-website" href="<?= e(cross_link((string) config('APP_URL_WEBSITE'), '/')) ?>"><?= e(t('status.back_to_website')) ?></a>
    <?php endif; ?>
    <p class="text-center muted">
      <?= e(t('status.questions')) ?>
      <a class="status-phone" href="tel:<?= e(preg_replace('/[^\d+]/', '', $shop['shop_phone'] ?? '')) ?>"><?= e($shop['shop_phone'] ?? '') ?></a>
    </p>
  </div>
</section>
<script src="<?= e(asset('js/status.js')) ?>"></script>
<?php
view('footer');
