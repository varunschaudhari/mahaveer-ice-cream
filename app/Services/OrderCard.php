<?php

declare(strict_types=1);

namespace App\Services;

use App\Settings;
use DateTimeImmutable;

/** An active order as the counter's Online tab and the owner's phone show it (JSON for JS). */
final class OrderCard
{
    /** @param array $order a row of OrderService::activeOnline() */
    public static function make(array $order, DateTimeImmutable $now): array
    {
        $slotMins = max(5, Settings::int('slot_mins', 15));
        $pickup = $order['pickup_at'] === null ? null : new DateTimeImmutable($order['pickup_at']);
        $waiting = $order['status'] === 'pending_payment';
        $claimed = $waiting && !empty($order['pay_claimed']);
        return [
            'id' => (int) $order['id'],
            'type' => $order['order_type'],
            'table' => $order['table_code'] === null ? null : table_number($order['table_code']),
            'token' => (int) $order['token_no'],
            'status' => $order['status'],
            'status_label' => $claimed ? t('online.check_payment') : order_status_label($order['status']),
            // Pay-first by UPI: paid = nothing to collect at pickup; pay_claimed = the customer says
            // they paid, the counter checks the soundbox / UPI app; pay_by = cancelled after this.
            'paid' => ($order['payment_pref'] ?? '') === 'online' && !$waiting
                && paise_from_decimal((string) ($order['amount_paid'] ?? '0')) >= paise_from_decimal((string) $order['total']),
            'pay_claimed' => $claimed,
            'pay_by' => $waiting && !$claimed ? format_time(OrderService::payBy($order)->format('H:i')) : '',
            'slot' => $pickup === null ? '' : format_time($pickup->format('H:i')) . ' – ' . format_time($pickup->modify("+{$slotMins} minutes")->format('H:i')),
            'customer' => (string) $order['customer_name'],
            'mobile' => (string) $order['customer_mobile'],
            'items' => array_map(static fn (array $line): array => [
                'name' => line_name($line['item_name']),
                'qty' => format_qty((string) $line['qty']),
            ], $order['items']),
            'total_paise' => paise_from_decimal((string) $order['total']),
            'note' => (string) ($order['note'] ?? ''),
            'age_mins' => max(0, intdiv($now->getTimestamp() - strtotime($order['created_at']), 60)),
            // Still open after its pickup window ended: the counter marks it so it is not missed.
            'late' => $pickup !== null && $pickup->modify("+{$slotMins} minutes") < $now,
        ];
    }

    /** @param array $order a row of BulkService::open() (owner's phone) */
    public static function bulk(array $order): array
    {
        $pickup = new DateTimeImmutable($order['pickup_at']);
        return [
            'id' => (int) $order['id'],
            'type' => 'bulk',
            'token' => (int) $order['token_no'],
            'status' => $order['status'],
            'status_label' => order_status_label($order['status']),
            'pickup' => $pickup->format('d/m') . ' · ' . format_time($pickup->format('H:i')),
            'customer' => (string) $order['customer_name'],
            'mobile' => (string) $order['customer_mobile'],
            'items' => array_map(static fn (array $line): array => [
                'name' => line_name($line['item_name']),
                'qty' => format_qty((string) $line['qty']),
            ], $order['items']),
            'total_paise' => paise_from_decimal((string) $order['total']),
            'paid_paise' => paise_from_decimal((string) $order['amount_paid']),
            'advance_paise' => paise_from_decimal((string) ($order['advance_amount'] ?? '0')),
            'note' => (string) ($order['note'] ?? ''),
        ];
    }
}
