<?php

declare(strict_types=1);

// Admin: reports for a date range (SPEC §12, BUILD_PLAN M7): totals, sales by day, payment split,
// order types, top flavours, item-wise, staff-wise, cancelled bills. Every section has a CSV
// download (?export=<report>); Print gives an A4 page (print styles in admin.css).

use App\Auth;
use App\Csv;
use App\Services\ReportExport;
use App\Services\ReportService;
use App\Settings;

require __DIR__ . '/../boot.php';

$user = Auth::requireAdmin();
$reports = new ReportService();
$range = ReportService::range($_GET['preset'] ?? null, $_GET['from'] ?? null, $_GET['to'] ?? null, now());
$from = $range['from'];
$to = $range['to'];
$rangeQuery = ['preset' => $range['preset'], 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')];

if (is_string($_GET['export'] ?? null)) {
    $table = (new ReportExport($reports))->build($_GET['export'], $from, $to);
    if ($table !== null && $range['error'] === null) {
        Csv::send($table['name'], $table['header'], $table['rows']);
    }
}

$summary = $reports->summary($from, $to);
$days = $reports->salesByDay($from, $to);
$types = $reports->orderTypes($from, $to);
$items = $reports->itemSales($from, $to);
$top = array_slice($reports->topItems($from, $to), 0, 10);
$staff = $reports->staffBills($from, $to);
$cancelled = $summary['cancelled'];
$showOnline = $summary['by_mode']['upi_online'] > 0;

$csvUrl = static fn (string $report): string => '/admin/reports.php?' . http_build_query($rangeQuery + ['export' => $report]);
$presetUrl = static fn (string $preset): string => '/admin/reports.php?preset=' . $preset;
$rangeText = $from == $to
    ? $from->format('d/m/Y')
    : t('report.range_text', ['from' => $from->format('d/m/Y'), 'to' => $to->format('d/m/Y')]);
$percent = static fn (int $part, int $whole): string => $whole > 0 ? (string) round($part * 100 / $whole) . '%' : '–';
$csvLink = static fn (string $report): string => '<a class="btn btn-secondary btn-sm no-print" href="' . e($csvUrl($report)) . '" download>' . e(t('report.csv')) . '</a>';

view('admin_header', ['title' => t('admin.nav.reports'), 'active' => 'reports', 'user' => $user]);
?>
<div class="print-only report-print-head">
  <strong><?= e(lang_field(Settings::all(), 'shop_name')) ?></strong> · <?= e(t('admin.nav.reports')) ?> · <?= e($rangeText) ?>
  <span class="muted"> · <?= e(t('print.printed_at', ['time' => format_date_time(now())])) ?></span>
</div>

<div class="section-head no-print">
  <h1><?= e(t('admin.nav.reports')) ?></h1>
  <button type="button" class="btn btn-secondary" data-print-page><?= e(t('report.print')) ?></button>
</div>

<?php if ($range['error'] !== null): ?>
<p class="flash flash-error no-print" role="alert"><?= e(t($range['error'], ['days' => ReportService::MAX_DAYS])) ?></p>
<?php endif; ?>

<nav class="chip-row no-print" aria-label="<?= e(t('report.range')) ?>">
  <?php foreach (['today', 'yesterday', 'week', 'month', 'last_month'] as $preset): ?>
  <a class="chip<?= $range['preset'] === $preset ? ' is-active' : '' ?>" href="<?= e($presetUrl($preset)) ?>"<?= $range['preset'] === $preset ? ' aria-current="true"' : '' ?>><?= e(t('report.preset.' . $preset)) ?></a>
  <?php endforeach; ?>
</nav>

<form method="get" action="/admin/reports.php" class="card form-grid no-print">
  <input type="hidden" name="preset" value="custom">
  <div class="field">
    <label for="r-from"><?= e(t('report.from')) ?></label>
    <input class="input" id="r-from" name="from" type="date" value="<?= e($from->format('Y-m-d')) ?>" required>
  </div>
  <div class="field">
    <label for="r-to"><?= e(t('report.to')) ?></label>
    <input class="input" id="r-to" name="to" type="date" value="<?= e($to->format('Y-m-d')) ?>" required>
  </div>
  <div class="field field-actions">
    <button type="submit" class="btn btn-primary"><?= e(t('report.show')) ?></button>
  </div>
</form>

<h2 class="report-range"><?= e($rangeText) ?></h2>

<section class="report-cards">
  <div class="card report-card report-card--main"><span><?= e(t('report.received')) ?></span><strong><?= e(money($summary['total'])) ?></strong></div>
  <div class="card report-card"><span><?= e(t('report.col.bills')) ?></span><strong><?= (int) $summary['bill_count'] ?></strong></div>
  <div class="card report-card"><span><?= e(t('report.col.billed')) ?></span><strong><?= e(money($summary['bill_total'])) ?></strong></div>
  <div class="card report-card"><span><?= e(t('report.col.discount')) ?></span><strong><?= e(money($summary['discount_total'])) ?></strong></div>
  <div class="card report-card"><span><?= e(t('report.col.cancelled')) ?></span><strong><?= count($cancelled) ?></strong></div>
</section>
<p class="muted text-sm"><?= e(t('report.bases_help')) ?></p>

<section class="card stack-sm report-section">
  <div class="section-head"><h2><?= e(t('report.payments')) ?></h2><?= $csvLink('payments') ?></div>
  <table class="report-table">
    <thead><tr><th><?= e(t('report.col.mode')) ?></th><th class="num"><?= e(t('report.col.amount')) ?></th><th class="num">%</th></tr></thead>
    <tbody>
      <?php foreach ($summary['by_mode'] as $mode => $paise): if ($mode === 'upi_online' && !$showOnline) { continue; } ?>
      <tr><td><?= e(ReportExport::modeLabel($mode)) ?></td><td class="num"><?= e(money($paise)) ?></td><td class="num"><?= e($percent($paise, $summary['total'])) ?></td></tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot><tr><th><?= e(t('report.total')) ?></th><th class="num"><?= e(money($summary['total'])) ?></th><th></th></tr></tfoot>
  </table>
</section>

<section class="card stack-sm report-section">
  <div class="section-head"><h2><?= e(t('report.by_day')) ?></h2><?= $csvLink('days') ?></div>
  <div class="table-scroll">
    <table class="report-table">
      <thead><tr>
        <th><?= e(t('report.col.date')) ?></th>
        <th class="num"><?= e(t('report.col.bills')) ?></th>
        <th class="num"><?= e(ReportExport::modeLabel('cash')) ?></th>
        <th class="num"><?= e(ReportExport::modeLabel('upi_counter')) ?></th>
        <?php if ($showOnline): ?><th class="num"><?= e(ReportExport::modeLabel('upi_online')) ?></th><?php endif; ?>
        <th class="num"><?= e(t('report.col.received')) ?></th>
        <th class="num"><?= e(t('report.col.discount')) ?></th>
        <th class="num"><?= e(t('report.col.cancelled')) ?></th>
      </tr></thead>
      <tbody>
        <?php foreach ($days as $day): $empty = $day['total'] === 0 && $day['bills'] === 0 && $day['cancelled'] === 0; ?>
        <tr<?= $empty ? ' class="is-empty"' : '' ?>>
          <td><?= e(DateTimeImmutable::createFromFormat('!Y-m-d', $day['date'])->format('d/m/Y')) ?></td>
          <td class="num"><?= (int) $day['bills'] ?></td>
          <td class="num"><?= e(money($day['by_mode']['cash'])) ?></td>
          <td class="num"><?= e(money($day['by_mode']['upi_counter'])) ?></td>
          <?php if ($showOnline): ?><td class="num"><?= e(money($day['by_mode']['upi_online'])) ?></td><?php endif; ?>
          <td class="num"><strong><?= e(money($day['total'])) ?></strong></td>
          <td class="num"><?= e(money($day['discount'])) ?></td>
          <td class="num"><?= (int) $day['cancelled'] ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot><tr>
        <th><?= e(t('report.total')) ?></th>
        <th class="num"><?= (int) $summary['bill_count'] ?></th>
        <th class="num"><?= e(money($summary['by_mode']['cash'])) ?></th>
        <th class="num"><?= e(money($summary['by_mode']['upi_counter'])) ?></th>
        <?php if ($showOnline): ?><th class="num"><?= e(money($summary['by_mode']['upi_online'])) ?></th><?php endif; ?>
        <th class="num"><?= e(money($summary['total'])) ?></th>
        <th class="num"><?= e(money($summary['discount_total'])) ?></th>
        <th class="num"><?= count($cancelled) ?></th>
      </tr></tfoot>
    </table>
  </div>
</section>

<section class="card stack-sm report-section">
  <div class="section-head"><h2><?= e(t('report.types')) ?></h2><?= $csvLink('types') ?></div>
  <table class="report-table">
    <thead><tr><th><?= e(t('report.col.type')) ?></th><th class="num"><?= e(t('report.col.orders')) ?></th><th class="num"><?= e(t('report.col.bills')) ?></th><th class="num"><?= e(t('report.col.billed')) ?></th></tr></thead>
    <tbody>
      <?php foreach ($types as $type => $row): ?>
      <tr><td><?= e(ReportExport::typeLabel($type)) ?></td><td class="num"><?= (int) $row['orders'] ?></td><td class="num"><?= (int) $row['bills'] ?></td><td class="num"><?= e(money($row['amount'])) ?></td></tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot><tr><th><?= e(t('report.total')) ?></th><th class="num"><?= array_sum(array_column($types, 'orders')) ?></th><th class="num"><?= (int) $summary['bill_count'] ?></th><th class="num"><?= e(money($summary['bill_total'])) ?></th></tr></tfoot>
  </table>
  <p class="muted text-sm"><?= e(t('report.types_help')) ?></p>
</section>

<section class="card stack-sm report-section">
  <div class="section-head"><h2><?= e(t('report.top')) ?></h2><?= $csvLink('top') ?></div>
  <?php if ($top === []): ?>
  <p class="muted"><?= e(t('report.none')) ?></p>
  <?php else: ?>
  <table class="report-table">
    <thead><tr><th class="num">#</th><th><?= e(t('report.col.item')) ?></th><th class="num"><?= e(t('report.col.qty')) ?></th><th class="num"><?= e(t('report.col.amount')) ?></th></tr></thead>
    <tbody>
      <?php foreach ($top as $i => $item): ?>
      <tr><td class="num"><?= $i + 1 ?></td><td><?= e(lang_field($item, 'name')) ?></td><td class="num"><?= e(format_qty($item['qty'])) ?></td><td class="num"><?= e(money($item['amount'])) ?></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <p class="muted text-sm"><?= e(t('report.top_help')) ?></p>
  <?php endif; ?>
</section>

<section class="card stack-sm report-section">
  <div class="section-head"><h2><?= e(t('report.items')) ?></h2><?= $csvLink('items') ?></div>
  <?php if ($items === []): ?>
  <p class="muted"><?= e(t('report.none')) ?></p>
  <?php else: ?>
  <table class="report-table">
    <thead><tr><th><?= e(t('report.col.item')) ?></th><th class="num"><?= e(t('report.col.qty')) ?></th><th class="num"><?= e(t('report.col.amount')) ?></th></tr></thead>
    <tbody>
      <?php foreach ($items as $line): ?>
      <tr><td><?= e(ReportExport::variantName($line)) ?></td><td class="num"><?= e(format_qty($line['qty'])) ?></td><td class="num"><?= e(money($line['amount'])) ?></td></tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot><tr><th><?= e(t('report.total')) ?></th><th></th><th class="num"><?= e(money(array_sum(array_column($items, 'amount')))) ?></th></tr></tfoot>
  </table>
  <p class="muted text-sm"><?= e(t('report.items_help')) ?></p>
  <?php endif; ?>
</section>

<section class="card stack-sm report-section">
  <div class="section-head"><h2><?= e(t('report.staff')) ?></h2><?= $csvLink('staff') ?></div>
  <?php if ($staff === []): ?>
  <p class="muted"><?= e(t('report.none')) ?></p>
  <?php else: ?>
  <table class="report-table">
    <thead><tr><th><?= e(t('report.col.staff')) ?></th><th class="num"><?= e(t('report.col.bills')) ?></th><th class="num"><?= e(t('report.col.billed')) ?></th><th class="num"><?= e(t('report.col.discount')) ?></th></tr></thead>
    <tbody>
      <?php foreach ($staff as $row): ?>
      <tr><td><?= e($row['name'] ?? t('report.no_staff')) ?></td><td class="num"><?= (int) $row['bills'] ?></td><td class="num"><?= e(money($row['amount'])) ?></td><td class="num"><?= e(money($row['discount'])) ?></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <p class="muted text-sm"><?= e(t('report.staff_help')) ?></p>
  <?php endif; ?>
</section>

<section class="card stack-sm report-section">
  <div class="section-head"><h2><?= e(t('report.cancelled')) ?></h2><?= $csvLink('cancelled') ?></div>
  <?php if ($cancelled === []): ?>
  <p class="muted"><?= e(t('report.none')) ?></p>
  <?php else: ?>
  <div class="table-scroll">
    <table class="report-table">
      <thead><tr>
        <th><?= e(t('report.col.billed_at')) ?></th><th class="num"><?= e(t('report.col.bill_no')) ?></th><th><?= e(t('report.col.token')) ?></th>
        <th class="num"><?= e(t('report.col.amount')) ?></th><th><?= e(t('report.col.reason')) ?></th><th><?= e(t('report.col.cancelled_by')) ?></th>
      </tr></thead>
      <tbody>
        <?php foreach ($cancelled as $bill): ?>
        <tr>
          <td><?= e(format_date_time($bill['billed_at'])) ?></td>
          <td class="num"><?= (int) $bill['bill_no'] ?></td>
          <td><?= e($bill['table_code'] !== null ? table_label($bill['table_code']) : ReportExport::typeLabel($bill['type']) . ' · ' . $bill['token_no']) ?></td>
          <td class="num"><?= e(money(paise_from_decimal((string) $bill['total']))) ?></td>
          <td><?= e((string) $bill['cancel_reason']) ?></td>
          <td><?= e((string) $bill['cancelled_by_name']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<?php
view('admin_footer');
