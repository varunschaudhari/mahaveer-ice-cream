<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;

/**
 * The CSV version of each report on admin → Reports (SPEC §12: "CSV export for each"), in the
 * page's language. Amounts are rupees with 2 decimals, dates Y-m-d, so Excel reads them as
 * numbers and dates.
 */
final class ReportExport
{
    public const REPORTS = ['days', 'payments', 'types', 'items', 'top', 'staff', 'cancelled'];

    public function __construct(private readonly ReportService $reports)
    {
    }

    /** @return array{name: string, header: list<string>, rows: list<list<mixed>>}|null null for an unknown report */
    public function build(string $report, DateTimeImmutable $from, DateTimeImmutable $to): ?array
    {
        $table = match ($report) {
            'days' => $this->days($from, $to),
            'payments' => $this->payments($from, $to),
            'types' => $this->types($from, $to),
            'items' => $this->items($from, $to),
            'top' => $this->top($from, $to),
            'staff' => $this->staff($from, $to),
            'cancelled' => $this->cancelled($from, $to),
            default => null,
        };
        if ($table === null) {
            return null;
        }
        return ['name' => 'mahavir-' . $report . '-' . $from->format('Y-m-d') . '_' . $to->format('Y-m-d')] + $table;
    }

    private static function rupees(int $paise): string
    {
        return decimal_from_paise($paise);
    }

    public static function modeLabel(string $mode): string
    {
        return match ($mode) {
            'cash' => t('report.mode.cash'),
            'upi_counter' => t('report.mode.upi_counter'),
            default => t('report.mode.upi_online'),
        };
    }

    public static function typeLabel(string $type): string
    {
        return match ($type) {
            'counter' => t('report.type.counter'),
            'takeaway' => t('report.type.takeaway'),
            'table' => t('report.type.table'),
            default => t('report.type.bulk'),
        };
    }

    /** "बदाम शेक (फुल)" in the current language. */
    public static function variantName(array $line): string
    {
        return lang_field($line, 'name') . ' (' . lang_field($line, 'label') . ')';
    }

    private function days(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $rows = [];
        foreach ($this->reports->salesByDay($from, $to) as $day) {
            $rows[] = [
                $day['date'], $day['bills'],
                self::rupees($day['by_mode']['cash']), self::rupees($day['by_mode']['upi_counter']), self::rupees($day['by_mode']['upi_online']),
                self::rupees($day['total']), self::rupees($day['bill_total']), self::rupees($day['discount']), $day['cancelled'],
            ];
        }
        return [
            'header' => [
                t('report.col.date'), t('report.col.bills'),
                self::modeLabel('cash'), self::modeLabel('upi_counter'), self::modeLabel('upi_online'),
                t('report.col.received'), t('report.col.billed'), t('report.col.discount'), t('report.col.cancelled'),
            ],
            'rows' => $rows,
        ];
    }

    private function payments(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $rows = [];
        foreach ($this->reports->paymentSplit($from, $to) as $mode => $paise) {
            $rows[] = [self::modeLabel($mode), self::rupees($paise)];
        }
        return ['header' => [t('report.col.mode'), t('report.col.amount')], 'rows' => $rows];
    }

    private function types(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $rows = [];
        foreach ($this->reports->orderTypes($from, $to) as $type => $row) {
            $rows[] = [self::typeLabel($type), $row['orders'], $row['bills'], self::rupees($row['amount'])];
        }
        return ['header' => [t('report.col.type'), t('report.col.orders'), t('report.col.bills'), t('report.col.billed')], 'rows' => $rows];
    }

    private function items(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $rows = [];
        foreach ($this->reports->itemSales($from, $to) as $line) {
            $rows[] = [self::variantName($line), format_qty($line['qty']), self::rupees($line['amount'])];
        }
        return ['header' => [t('report.col.item'), t('report.col.qty'), t('report.col.amount')], 'rows' => $rows];
    }

    private function top(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $rows = [];
        foreach ($this->reports->topItems($from, $to) as $i => $item) {
            $rows[] = [$i + 1, lang_field($item, 'name'), format_qty($item['qty']), self::rupees($item['amount'])];
        }
        return ['header' => [t('report.col.rank'), t('report.col.item'), t('report.col.qty'), t('report.col.amount')], 'rows' => $rows];
    }

    private function staff(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $rows = [];
        foreach ($this->reports->staffBills($from, $to) as $row) {
            $rows[] = [$row['name'] ?? t('report.no_staff'), $row['bills'], self::rupees($row['amount']), self::rupees($row['discount'])];
        }
        return ['header' => [t('report.col.staff'), t('report.col.bills'), t('report.col.billed'), t('report.col.discount')], 'rows' => $rows];
    }

    private function cancelled(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $rows = [];
        foreach ($this->reports->cancelledBills($from, $to) as $bill) {
            $rows[] = [
                substr((string) $bill['billed_at'], 0, 16), (int) $bill['bill_no'], self::typeLabel($bill['type']),
                $bill['table_code'] !== null ? table_label($bill['table_code']) : (int) $bill['token_no'],
                self::rupees(paise_from_decimal((string) $bill['total'])), (string) $bill['cancel_reason'], (string) $bill['cancelled_by_name'],
            ];
        }
        return [
            'header' => [
                t('report.col.billed_at'), t('report.col.bill_no'), t('report.col.type'), t('report.col.token'),
                t('report.col.amount'), t('report.col.reason'), t('report.col.cancelled_by'),
            ],
            'rows' => $rows,
        ];
    }
}
