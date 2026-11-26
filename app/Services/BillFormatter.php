<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Turns an order, or a settled table visit, into what the 80 mm bill prints (SPEC §10), so one
 * template (Views/print_bill.php) prints both. Amounts in paise; item names in Marathi.
 */
final class BillFormatter
{
    /** A counter / takeaway / bulk bill from OrderService::find(). */
    public static function fromOrder(array $order): array
    {
        $subtotal = self::paise($order['subtotal']);
        $parcel = self::paise($order['parcel_charge']);
        $discount = self::paise($order['discount']);
        $total = self::paise($order['total']);

        $type = match ($order['order_type']) {
            'takeaway' => t('print.takeaway') . (!empty($order['customer_name']) ? ' · ' . $order['customer_name'] : ''),
            'bulk' => t('print.bulk') . (!empty($order['customer_name']) ? ' · ' . $order['customer_name'] : ''),
            default => $order['service_type'] === 'parcel' ? t('billing.parcel') : t('billing.dine_in'),
        };

        return [
            'number' => $order['bill_no'],
            'headline_label' => t('print.token'),
            'headline' => (string) $order['token_no'],
            'type' => $type,
            'at' => $order['completed_at'] ?? $order['created_at'],
            'staff' => (string) ($order['staff_name'] ?? ''),
            'cancelled' => $order['status'] === 'cancelled',
            'lines' => self::lines($order['items']),
            'subtotal' => $subtotal,
            'parcel' => $parcel,
            'discount' => $discount,
            'round_off' => $total - ($subtotal + $parcel - $discount),
            'total' => $total,
            'payments' => self::payments($order['payments']),
        ];
    }

    /**
     * A settled table visit: one bill for every order at the table (TableService::findSession,
     * ::combinedLines, ::sessionPayments).
     */
    public static function fromSession(array $session, array $lines, array $payments, bool $cancelled): array
    {
        $subtotal = self::paise($session['subtotal']);
        $discount = self::paise($session['discount']);
        $total = self::paise($session['total']);

        return [
            'number' => $session['bill_no'],
            'headline_label' => t('print.table'),
            'headline' => table_number($session['table_code']),
            'type' => t('print.table_bill'),
            'at' => $session['closed_at'],
            'staff' => (string) ($session['closed_by_name'] ?? ''),
            'cancelled' => $cancelled,
            'lines' => self::lines($lines),
            'subtotal' => $subtotal,
            'parcel' => 0,
            'discount' => $discount,
            'round_off' => $total - ($subtotal - $discount),
            'total' => $total,
            'payments' => self::payments($payments),
        ];
    }

    private static function lines(array $items): array
    {
        return array_map(static fn (array $line): array => [
            'name' => marathi_name($line['item_name']),
            'qty' => format_qty((string) $line['qty']),
            'rate' => self::paise($line['unit_price']),
            'amount' => self::paise($line['line_total']),
        ], $items);
    }

    private static function payments(array $payments): array
    {
        return array_map(static fn (array $p): array => ['mode' => $p['mode'], 'amount' => self::paise($p['amount'])], $payments);
    }

    private static function paise(mixed $decimal): int
    {
        return paise_from_decimal((string) ($decimal ?? '0'));
    }
}
