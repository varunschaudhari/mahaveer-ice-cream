<?php

declare(strict_types=1);

// Admin: bulk / party orders (SPEC §8, §12). List → one order: edit lines and prices, approve with
// an advance, reject (pending) or cancel (approved; the advance is marked refunded).
// Staff record payments, mark ready and hand over on the counter's Bulk tab.

use App\Auth;
use App\Csrf;
use App\Services\BulkService;
use App\Services\MenuService;
use App\Services\OrderService;
use App\ValidationException;

require __DIR__ . '/../boot.php';

$user = Auth::requireAdmin();
$bulk = new BulkService();
$orders = new OrderService();
$id = isset($_GET['id']) ? (int) $_GET['id'] : null;

if (is_post() && $id !== null) {
    Csrf::check();
    try {
        switch ((string) ($_POST['action'] ?? '')) {
            case 'update_lines':
                $lines = [];
                foreach ((array) ($_POST['lines'] ?? []) as $line) {
                    if (is_array($line) && empty($line['remove'])) {
                        $lines[] = $line;
                    }
                }
                $new = (array) ($_POST['new'] ?? []);
                if (!empty($new['variant_id'])) {
                    $lines[] = $new;
                }
                $bulk->updateLines($id, $lines, $user['id']);
                flash('success', t('bulk.admin.lines_saved'));
                break;
            case 'approve':
                $bulk->approve($id, parse_rupees((string) ($_POST['advance'] ?? '0')), $user['id']);
                flash('success', t('bulk.admin.approved'));
                break;
            case 'cancel':
                $bulk->cancel($id, (string) ($_POST['reason'] ?? ''), $user['id']);
                flash('success', t('bulk.admin.cancelled'));
                break;
        }
    } catch (ValidationException $e) {
        flash('error', implode(' ', array_map('error_text', $e->errors)));
    } catch (InvalidArgumentException) {
        flash('error', t('bulk.error.advance'));
    }
    redirect('/admin/bulk.php?id=' . $id);
}

$statusClass = static fn (string $status): string => match ($status) {
    'pending_approval' => 'badge-danger',
    'cancelled', 'completed' => 'badge-muted',
    default => '',
};
$row = static function (array $order) use ($statusClass): void {
    $pickup = strtotime($order['pickup_at']);
    ?>
    <li>
      <a class="admin-row" href="/admin/bulk.php?id=<?= (int) $order['id'] ?>">
        <span class="admin-row__main">
          <strong><?= e($order['customer_name']) ?></strong> · <?= e(date('d/m/Y', $pickup)) ?>, <?= e(format_time(date('H:i', $pickup))) ?><br>
          <span class="text-sm"><?= e(implode(', ', array_map(static fn ($l) => format_qty((string) $l['qty']) . ' × ' . line_name($l['item_name']), $order['items']))) ?></span>
        </span>
        <span class="cluster">
          <strong><?= e(money(paise_from_decimal((string) $order['total']))) ?></strong>
          <span class="badge <?= e($statusClass($order['status'])) ?>"><?= e(order_status_label($order['status'])) ?></span>
        </span>
      </a>
    </li>
    <?php
};

if ($id === null) {
    $open = $bulk->open();
    $pending = array_values(array_filter($open, static fn ($o) => $o['status'] === 'pending_approval'));
    $approved = array_values(array_filter($open, static fn ($o) => $o['status'] !== 'pending_approval'));
    view('admin_header', ['title' => t('admin.nav.bulk'), 'active' => 'bulk', 'user' => $user]);
    ?>
    <h1><?= e(t('admin.nav.bulk')) ?></h1>
    <p class="muted"><?= e(t('bulk.admin.help')) ?></p>
    <?php foreach ([[t('bulk.admin.pending'), $pending], [t('bulk.admin.approved_list'), $approved], [t('bulk.admin.recent'), $bulk->recent()]] as [$heading, $list]): ?>
    <h2><?= e($heading) ?> (<?= count($list) ?>)</h2>
    <?php if ($list === []): ?>
    <p class="muted"><?= e(t('bulk.admin.none')) ?></p>
    <?php else: ?>
    <ul class="admin-list card"><?php foreach ($list as $order) { $row($order); } ?></ul>
    <?php endif; ?>
    <?php endforeach; ?>
    <?php
    view('admin_footer');
    exit;
}

$order = $orders->find($id);
if ($order === null || $order['order_type'] !== 'bulk') {
    http_response_code(404);
    view('error', ['message' => t('error.not_found')]);
    exit;
}
$isOpen = in_array($order['status'], BulkService::OPEN_STATUSES, true);
$pickup = strtotime($order['pickup_at']);
$variants = [];
foreach ((new MenuService())->counterMenu() as $category) {
    foreach ($category['items'] as $item) {
        foreach ($item['variants'] as $variant) {
            $variants[] = ['id' => $variant['id'], 'label' => lang_field($item, 'name') . ' (' . lang_field($variant, 'label') . ')', 'price' => $variant['price_paise']];
        }
    }
}

view('admin_header', ['title' => t('admin.nav.bulk'), 'active' => 'bulk', 'user' => $user]);
?>
<p><a href="/admin/bulk.php">← <?= e(t('admin.nav.bulk')) ?></a></p>
<div class="section-head">
  <h1><?= e($order['customer_name']) ?> · <?= e(t('online.token', ['n' => $order['token_no']])) ?></h1>
  <span class="badge <?= e($statusClass($order['status'])) ?>"><?= e(order_status_label($order['status'])) ?></span>
</div>

<div class="card stack-sm">
  <p><strong><?= e(t('bulk.event_date')) ?>:</strong> <?= e(date('d/m/Y', $pickup)) ?>, <?= e(format_time(date('H:i', $pickup))) ?></p>
  <p><strong><?= e(t('checkout.mobile')) ?>:</strong> <a href="tel:+91<?= e($order['customer_mobile']) ?>"><?= e($order['customer_mobile']) ?></a></p>
  <?php if (!empty($order['note'])): ?><p><strong><?= e(t('checkout.note')) ?>:</strong> <?= e($order['note']) ?></p><?php endif; ?>
  <?php if ($order['status'] === 'cancelled'): ?><p class="flash flash-error"><?= e(t('today.reason', ['reason' => $order['cancel_reason']])) ?></p><?php endif; ?>
  <p>
    <?= e(t('billing.total')) ?>: <strong><?= e(money(paise_from_decimal((string) $order['total']))) ?></strong>
    · <?= e(t('bulk.advance')) ?>: <strong><?= $order['advance_amount'] === null ? '—' : e(money(paise_from_decimal((string) $order['advance_amount']))) ?></strong>
    · <?= e(t('bulk.paid_so_far')) ?>: <strong><?= e(money(paise_from_decimal((string) $order['amount_paid']))) ?></strong>
    · <?= e(t('bulk.balance')) ?>: <strong><?= e(money(BulkService::balance($order))) ?></strong>
  </p>
</div>

<form method="post" action="/admin/bulk.php?id=<?= $id ?>" class="card stack">
  <h2><?= e(t('bulk.items')) ?></h2>
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="update_lines">
  <div class="table-scroll">
    <table class="variant-table">
      <thead><tr><th><?= e(t('print.item')) ?></th><th><?= e(t('bulk.qty')) ?></th><th><?= e(t('menu.price')) ?></th><th><?= e(t('print.amount')) ?></th><?php if ($isOpen): ?><th><?= e(t('billing.remove')) ?></th><?php endif; ?></tr></thead>
      <tbody>
        <?php foreach ($order['items'] as $i => $line): ?>
        <tr>
          <td><?= e(line_name($line['item_name'])) ?><input type="hidden" name="lines[<?= $i ?>][variant_id]" value="<?= (int) $line['variant_id'] ?>"></td>
          <td><input class="input input-sort" name="lines[<?= $i ?>][qty]" value="<?= e(format_qty((string) $line['qty'])) ?>" inputmode="decimal"<?= $isOpen ? '' : ' disabled' ?>></td>
          <td><input class="input input-price" name="lines[<?= $i ?>][price]" value="<?= e(rupees_input(paise_from_decimal((string) $line['unit_price']))) ?>" inputmode="decimal"<?= $isOpen ? '' : ' disabled' ?>></td>
          <td><?= e(money(paise_from_decimal((string) $line['line_total']))) ?></td>
          <?php if ($isOpen): ?><td class="text-center"><input type="checkbox" name="lines[<?= $i ?>][remove]" value="1" aria-label="<?= e(t('billing.remove')) ?>"></td><?php endif; ?>
        </tr>
        <?php endforeach; ?>
        <?php if ($isOpen): ?>
        <tr class="is-new">
          <td>
            <select class="select" name="new[variant_id]" aria-label="<?= e(t('bulk.choose_item')) ?>">
              <option value=""><?= e(t('bulk.admin.add_line')) ?></option>
              <?php foreach ($variants as $variant): ?>
              <option value="<?= (int) $variant['id'] ?>"><?= e($variant['label'] . ' – ' . money($variant['price'])) ?></option>
              <?php endforeach; ?>
            </select>
          </td>
          <td><input class="input input-sort" name="new[qty]" value="1" inputmode="decimal" aria-label="<?= e(t('bulk.qty')) ?>"></td>
          <td><input class="input input-price" name="new[price]" inputmode="decimal" placeholder="<?= e(t('bulk.admin.menu_price')) ?>" aria-label="<?= e(t('menu.price')) ?>"></td>
          <td colspan="2"></td>
        </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($isOpen): ?>
  <p class="text-sm muted"><?= e(t('bulk.admin.lines_help')) ?></p>
  <div><button type="submit" class="btn btn-secondary"><?= e(t('bulk.admin.save_lines')) ?></button></div>
  <?php endif; ?>
</form>

<?php if ($order['status'] === 'pending_approval'): ?>
<form method="post" action="/admin/bulk.php?id=<?= $id ?>" class="card stack">
  <h2><?= e(t('bulk.admin.approve_title')) ?></h2>
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="approve">
  <div class="field">
    <label for="advance"><?= e(t('bulk.advance')) ?> (₹)</label>
    <input class="input input-price" id="advance" name="advance" inputmode="decimal" value="0">
    <p class="text-sm muted"><?= e(t('bulk.admin.advance_help')) ?></p>
  </div>
  <div><button type="submit" class="btn btn-primary"><?= e(t('bulk.admin.approve')) ?></button></div>
</form>
<?php endif; ?>

<?php if ($order['payments'] !== []): ?>
<div class="card stack-sm">
  <h2><?= e(t('bulk.admin.payments')) ?></h2>
  <ul class="admin-list">
    <?php foreach ($order['payments'] as $payment): ?>
    <li class="admin-row">
      <span><?= e(format_date_time($payment['created_at'])) ?> · <?= e($payment['mode'] === 'cash' ? t('billing.pay_cash') : t('billing.pay_upi')) ?></span>
      <span><?= e(money(paise_from_decimal((string) $payment['amount']))) ?><?= $payment['status'] === 'refunded' ? ' (' . e(t('bulk.admin.refunded')) . ')' : '' ?></span>
    </li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<?php if ($isOpen): ?>
<form method="post" action="/admin/bulk.php?id=<?= $id ?>" class="card stack"
      data-confirm="<?= e($order['status'] === 'pending_approval' ? t('bulk.admin.confirm_reject') : t('bulk.admin.confirm_cancel')) ?>">
  <h2><?= e($order['status'] === 'pending_approval' ? t('bulk.admin.reject_title') : t('bulk.admin.cancel_title')) ?></h2>
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="cancel">
  <div class="field">
    <label for="reason"><?= e(t('bulk.admin.reason')) ?></label>
    <input class="input" id="reason" name="reason" maxlength="200" required>
    <p class="text-sm muted"><?= e(t('bulk.admin.reason_help')) ?></p>
  </div>
  <div><button type="submit" class="btn btn-danger"><?= e($order['status'] === 'pending_approval' ? t('bulk.admin.reject') : t('bulk.admin.cancel')) ?></button></div>
</form>
<?php endif; ?>
<?php
view('admin_footer');
