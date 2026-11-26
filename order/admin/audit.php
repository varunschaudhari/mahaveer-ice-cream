<?php

declare(strict_types=1);

// Admin: audit log — who changed prices, menu, settings and staff (newest first).

use App\Auth;
use App\Services\AuditService;

require __DIR__ . '/../boot.php';

$user = Auth::requireAdmin();
$audit = new AuditService();

$validDate = static fn (mixed $v): string => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : '';
$filters = [
    'action' => is_string($_GET['action'] ?? null) ? $_GET['action'] : '',
    'from' => $validDate($_GET['from'] ?? ''),
    'to' => $validDate($_GET['to'] ?? ''),
];
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;
$result = $audit->search($filters, $page, $perPage);
$pages = max(1, (int) ceil($result['total'] / $perPage));

$actionLabel = static fn (string $action): string => match ($action) {
    'price_change' => t('audit.action.price_change'),
    'item_create' => t('audit.action.item_create'),
    'item_update' => t('audit.action.item_update'),
    'variant_create' => t('audit.action.variant_create'),
    'variant_update' => t('audit.action.variant_update'),
    'category_create' => t('audit.action.category_create'),
    'category_update' => t('audit.action.category_update'),
    'settings_change' => t('audit.action.settings_change'),
    'staff_create' => t('audit.action.staff_create'),
    'staff_update' => t('audit.action.staff_update'),
    'staff_pin_reset' => t('audit.action.staff_pin_reset'),
    'staff_password_change' => t('audit.action.staff_password_change'),
    'staff_activate' => t('audit.action.staff_activate'),
    'staff_deactivate' => t('audit.action.staff_deactivate'),
    'staff_unlock' => t('audit.action.staff_unlock'),
    'discount' => t('audit.action.discount'),
    'cancel_order' => t('audit.action.cancel_order'),
    'bill_reprint' => t('audit.action.bill_reprint'),
    'stock_toggle' => t('audit.action.stock_toggle'),
    'stock_reset' => t('audit.action.stock_reset'),
    'no_show' => t('audit.action.no_show'),
    'customer_unblock' => t('audit.action.customer_unblock'),
    'table_move' => t('audit.action.table_move'),
    'table_qr_regenerate' => t('audit.action.table_qr_regenerate'),
    'table_activate' => t('audit.action.table_activate'),
    'table_deactivate' => t('audit.action.table_deactivate'),
    'cancel_table_bill' => t('audit.action.cancel_table_bill'),
    default => $action,
};

/** One value for display: prices as ₹, true/false flags as yes/no, empty as —. */
$show = static function (string $field, mixed $value): string {
    if ($value === null || $value === '') {
        return '—';
    }
    if ($field === 'price' && is_string($value) && preg_match('/^\d+\.\d{2}$/', $value)) {
        return money(paise_from_decimal($value));
    }
    if (in_array($field, ['is_active', 'online_enabled'], true)) {
        return $value ? t('common.yes') : t('common.no');
    }
    return is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE);
};

/** @return list<string> lines like "price: ₹50 → ₹55" */
$describe = static function (array $row) use ($show): array {
    $details = $row['details'];
    $lines = [];
    foreach (['item', 'variant', 'name'] as $key) {
        if (isset($details[$key]) && is_scalar($details[$key])) {
            $lines[] = (string) $details[$key];
        }
    }
    if (isset($details['bill_no'])) {
        $lines[] = t('print.bill_no') . ' ' . $details['bill_no'];
    }
    foreach (['amount' => t('audit.amount'), 'total' => t('billing.total')] as $key => $label) {
        if (isset($details[$key]) && is_string($details[$key])) {
            $lines[] = $label . ': ' . $show('price', $details[$key]);
        }
    }
    if (isset($details['reason'])) {
        $lines[] = t('audit.reason') . ': ' . $details['reason'];
    }
    if (array_key_exists('available', $details)) {
        $lines[] = $details['available'] ? t('stock.available') : t('menu.sold_out');
    }
    $before = $details['before'] ?? null;
    $after = $details['after'] ?? null;
    if ($row['entity'] === 'setting') {
        $lines[] = t('settings.' . $row['entity_id']) . ': ' . $show('', $before) . ' → ' . $show('', $after);
    } elseif (is_array($after)) {
        foreach ($after as $field => $value) {
            $lines[] = is_array($before) && array_key_exists($field, $before)
                ? "{$field}: " . $show($field, $before[$field]) . ' → ' . $show($field, $value)
                : "{$field}: " . $show($field, $value);
        }
    }
    return $lines;
};

$pageUrl = static fn (int $p): string => '/admin/audit.php?' . http_build_query(array_filter($filters) + ['page' => $p]);

view('admin_header', ['title' => t('admin.nav.audit'), 'active' => 'audit', 'user' => $user]);
?>
<h1><?= e(t('admin.nav.audit')) ?></h1>

<form method="get" action="/admin/audit.php" class="card form-grid">
  <div class="field">
    <label for="a-action"><?= e(t('audit.filter_action')) ?></label>
    <select class="select" id="a-action" name="action">
      <option value=""><?= e(t('audit.all_actions')) ?></option>
      <?php foreach ($audit->actions() as $action): ?>
      <option value="<?= e($action) ?>"<?= $filters['action'] === $action ? ' selected' : '' ?>><?= e($actionLabel($action)) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="field">
    <label for="a-from"><?= e(t('audit.from')) ?></label>
    <input class="input" id="a-from" name="from" type="date" value="<?= e($filters['from']) ?>">
  </div>
  <div class="field">
    <label for="a-to"><?= e(t('audit.to')) ?></label>
    <input class="input" id="a-to" name="to" type="date" value="<?= e($filters['to']) ?>">
  </div>
  <div class="field field-actions">
    <button type="submit" class="btn btn-primary"><?= e(t('audit.filter')) ?></button>
  </div>
</form>

<?php if ($result['rows'] === []): ?>
<p class="muted"><?= e(t('audit.empty')) ?></p>
<?php else: ?>
<ol class="admin-list card">
  <?php foreach ($result['rows'] as $row): ?>
  <li class="audit-entry">
    <div class="spread">
      <strong><?= e($actionLabel($row['action'])) ?></strong>
      <span class="text-sm muted"><?= e(format_date_time($row['created_at'])) ?></span>
    </div>
    <p class="text-sm muted"><?= e($row['staff_name'] ?? t('audit.system')) ?></p>
    <?php foreach ($describe($row) as $line): ?>
    <p class="text-sm"><?= e($line) ?></p>
    <?php endforeach; ?>
  </li>
  <?php endforeach; ?>
</ol>
<nav class="spread" aria-label="<?= e(t('audit.pages')) ?>">
  <?php if ($page > 1): ?><a class="btn btn-secondary" href="<?= e($pageUrl($page - 1)) ?>"><?= e(t('audit.newer')) ?></a><?php else: ?><span></span><?php endif; ?>
  <span class="muted"><?= e(t('audit.page_of', ['page' => $page, 'pages' => $pages])) ?></span>
  <?php if ($page < $pages): ?><a class="btn btn-secondary" href="<?= e($pageUrl($page + 1)) ?>"><?= e(t('audit.older')) ?></a><?php else: ?><span></span><?php endif; ?>
</nav>
<?php endif; ?>
<?php
view('admin_footer');
