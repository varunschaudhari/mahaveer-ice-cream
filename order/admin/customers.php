<?php

declare(strict_types=1);

// Admin: customers (SPEC §12, BUILD_PLAN M7). Search by mobile or name, filter blocked / with
// no-shows, block / unblock, one customer's latest orders (?id=), CSV export of the list
// (developer decision: allowed, written to the audit log).

use App\Auth;
use App\Csrf;
use App\Csv;
use App\Services\CustomerService;
use App\Services\ReportExport;
use App\Settings;

require __DIR__ . '/../boot.php';

$user = Auth::requireAdmin();
$customers = new CustomerService();

$query = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 60) : '';
$filter = in_array($_GET['filter'] ?? '', CustomerService::FILTERS, true) ? $_GET['filter'] : 'all';
$page = max(1, (int) ($_GET['page'] ?? 1));
$listQuery = array_filter(['q' => $query, 'filter' => $filter === 'all' ? '' : $filter]);
$listUrl = static fn (array $extra = []): string => '/admin/customers.php' . (($listQuery + $extra) === [] ? '' : '?' . http_build_query($listQuery + $extra));

if (is_post()) {
    Csrf::check();
    $id = (int) ($_POST['id'] ?? 0);
    if (($_POST['action'] ?? '') === 'block') {
        $customers->block($id, $user['id']);
        flash('success', t('customers.blocked_done'));
    } else {
        $customers->unblock($id, $user['id']);
        flash('success', t('blocked.unblocked'));
    }
    $back = (string) ($_POST['back'] ?? '');
    redirect(str_starts_with($back, '/admin/customers.php') && !str_contains($back, '//') ? $back : '/admin/customers.php');
}

if (isset($_GET['export'])) {
    $rows = array_map(static fn (array $c): array => [
        $c['name'], $c['mobile'], (int) $c['order_count'],
        $c['last_order_at'] === null ? '' : substr($c['last_order_at'], 0, 16),
        (int) $c['no_show_count'], $c['is_blocked'] ? t('common.yes') : t('common.no'), substr($c['created_at'], 0, 10),
    ], $customers->export($query, $filter, $user['id']));
    Csv::send('mahavir-customers-' . now()->format('Y-m-d'), [
        t('customers.col.name'), t('customers.col.mobile'), t('customers.col.orders'), t('customers.col.last_order'),
        t('customers.col.no_shows'), t('customers.col.blocked'), t('customers.col.since'),
    ], $rows);
}

/** Block / Unblock button for one customer; $back = where to return after the POST. */
$blockForm = static function (array $customer, string $back): string {
    $blocked = (int) $customer['is_blocked'] === 1;
    return '<form method="post" action="/admin/customers.php" data-confirm="'
        . e(t($blocked ? 'blocked.confirm' : 'customers.block_confirm', ['name' => $customer['name']])) . '">'
        . csrf_field()
        . '<input type="hidden" name="id" value="' . (int) $customer['id'] . '">'
        . '<input type="hidden" name="action" value="' . ($blocked ? 'unblock' : 'block') . '">'
        . '<input type="hidden" name="back" value="' . e($back) . '">'
        . '<button type="submit" class="btn ' . ($blocked ? 'btn-success' : 'btn-outline-danger') . '">'
        . e(t($blocked ? 'blocked.unblock' : 'customers.block')) . '</button></form>';
};
$statusLine = static fn (array $c): string => t('blocked.counts', ['orders' => $c['order_count'], 'no_shows' => $c['no_show_count']])
    . ($c['last_order_at'] ? ' · ' . format_date_time($c['last_order_at']) : '');

// ---------- one customer ----------

if (isset($_GET['id'])) {
    $customer = $customers->find((int) $_GET['id']);
    if ($customer === null) {
        flash('error', t('customers.not_found'));
        redirect('/admin/customers.php');
    }
    $orders = $customers->orders((int) $customer['id']);
    $self = '/admin/customers.php?id=' . (int) $customer['id'];

    view('admin_header', ['title' => $customer['name'], 'active' => 'customers', 'user' => $user]);
    ?>
    <p><a href="/admin/customers.php">← <?= e(t('admin.nav.customers')) ?></a></p>
    <section class="card stack-sm">
      <div class="section-head">
        <h1><?= e($customer['name']) ?> <?php if ($customer['is_blocked']): ?><span class="badge badge-danger"><?= e(t('customers.blocked_badge')) ?></span><?php endif; ?></h1>
        <?= $blockForm($customer, $self) ?>
      </div>
      <p><a href="tel:+91<?= e($customer['mobile']) ?>"><?= e($customer['mobile']) ?></a></p>
      <p class="muted"><?= e($statusLine($customer)) ?></p>
      <p class="muted text-sm"><?= e(t('customers.since', ['date' => format_date_time($customer['created_at'])])) ?></p>
    </section>

    <h2><?= e(t('customers.recent_orders')) ?></h2>
    <?php if ($orders === []): ?>
    <p class="muted"><?= e(t('customers.no_orders')) ?></p>
    <?php else: ?>
    <div class="card table-scroll">
      <table class="report-table">
        <thead><tr>
          <th><?= e(t('report.col.date')) ?></th><th><?= e(t('report.col.type')) ?></th><th><?= e(t('report.col.token')) ?></th>
          <th><?= e(t('customers.col.status')) ?></th><th class="num"><?= e(t('report.col.amount')) ?></th>
        </tr></thead>
        <tbody>
          <?php foreach ($orders as $order): ?>
          <tr>
            <td><?= e(format_date_time($order['created_at'])) ?></td>
            <td><?= e(ReportExport::typeLabel($order['order_type'])) ?><?= $order['table_code'] !== null ? ' · ' . e(table_label($order['table_code'])) : '' ?></td>
            <td>
              <?php if ($order['order_type'] === 'bulk'): ?>
              <a href="/admin/bulk.php?id=<?= (int) $order['id'] ?>"><?= (int) $order['token_no'] ?></a>
              <?php else: ?>
              <?= (int) $order['token_no'] ?>
              <?php endif; ?>
            </td>
            <td><?= e(order_status_label($order['status'])) ?><?= $order['cancel_reason'] ? '<br><span class="text-sm muted">' . e($order['cancel_reason']) . '</span>' : '' ?></td>
            <td class="num"><?= e(money(paise_from_decimal((string) $order['total']))) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
    <?php
    view('admin_footer');
    exit;
}

// ---------- list ----------

$perPage = 50;
$result = $customers->search($query, $filter, $page, $perPage);
$pages = max(1, (int) ceil($result['total'] / $perPage));
$self = $listUrl($page > 1 ? ['page' => $page] : []);

view('admin_header', ['title' => t('admin.nav.customers'), 'active' => 'customers', 'user' => $user]);
?>
<div class="section-head">
  <h1><?= e(t('admin.nav.customers')) ?></h1>
  <a class="btn btn-secondary" href="<?= e($listUrl(['export' => 1])) ?>" download><?= e(t('customers.export')) ?></a>
</div>
<p class="muted text-sm"><?= e(t('blocked.help', ['n' => Settings::int('noshow_block_count', 2)])) ?></p>

<form method="get" action="/admin/customers.php" class="card form-grid" role="search">
  <div class="field">
    <label for="c-q"><?= e(t('customers.search')) ?></label>
    <input class="input" id="c-q" name="q" type="search" value="<?= e($query) ?>" maxlength="60" placeholder="<?= e(t('customers.search_hint')) ?>">
  </div>
  <div class="field">
    <label for="c-filter"><?= e(t('customers.filter')) ?></label>
    <select class="select" id="c-filter" name="filter">
      <?php foreach (CustomerService::FILTERS as $option): ?>
      <option value="<?= e($option) ?>"<?= $filter === $option ? ' selected' : '' ?>><?= e(t('customers.filter.' . $option)) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="field field-actions">
    <button type="submit" class="btn btn-primary"><?= e(t('customers.search_button')) ?></button>
  </div>
</form>

<p class="muted"><?= e(t('customers.count', ['n' => $result['total']])) ?></p>

<?php if ($result['rows'] !== []): ?>
<ul class="admin-list card">
  <?php foreach ($result['rows'] as $customer): ?>
  <li class="admin-row">
    <span class="admin-row__main">
      <a href="/admin/customers.php?id=<?= (int) $customer['id'] ?>"><strong><?= e($customer['name']) ?></strong></a>
      <?php if ($customer['is_blocked']): ?><span class="badge badge-danger"><?= e(t('customers.blocked_badge')) ?></span><?php endif; ?>
      <a href="tel:+91<?= e($customer['mobile']) ?>"><?= e($customer['mobile']) ?></a><br>
      <span class="text-sm muted"><?= e($statusLine($customer)) ?></span>
    </span>
    <?= $blockForm($customer, $self) ?>
  </li>
  <?php endforeach; ?>
</ul>
<?php if ($pages > 1): ?>
<nav class="spread" aria-label="<?= e(t('audit.pages')) ?>">
  <?php if ($page > 1): ?><a class="btn btn-secondary" href="<?= e($listUrl(['page' => $page - 1])) ?>"><?= e(t('customers.prev')) ?></a><?php else: ?><span></span><?php endif; ?>
  <span class="muted"><?= e(t('audit.page_of', ['page' => $page, 'pages' => $pages])) ?></span>
  <?php if ($page < $pages): ?><a class="btn btn-secondary" href="<?= e($listUrl(['page' => $page + 1])) ?>"><?= e(t('customers.next')) ?></a><?php else: ?><span></span><?php endif; ?>
</nav>
<?php endif; ?>
<?php endif; ?>
<?php
view('admin_footer');
