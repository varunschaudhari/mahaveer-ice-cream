<?php

declare(strict_types=1);

namespace App\Services;

use App\Settings;

/**
 * Pay-first takeaway by UPI, without a payment gateway (decision 2026-11-28, SPEC §6). The customer
 * pays the shop's UPI ID from their own UPI app; the counter confirms the money arrived. Switched on
 * by entering a UPI ID in admin → Settings; empty = customers pay at the counter as before.
 */
final class UpiService
{
    /** name@handle as UPI apps accept it, e.g. mahavir.icecream@okaxis, 9922220000@ybl. */
    private const ID_PATTERN = '/^[a-z0-9][a-z0-9._-]{1,99}@[a-z][a-z0-9.-]{1,63}$/';

    public static function enabled(): bool
    {
        return self::shopId() !== '';
    }

    public static function shopId(): string
    {
        return Settings::get('upi_id');
    }

    public static function isValidId(string $id): bool
    {
        return preg_match(self::ID_PATTERN, $id) === 1;
    }

    /**
     * upi://pay link that opens the customer's UPI app with the shop, the amount and the token
     * filled in. No transaction reference (tr): that is for gateway merchants, and some apps refuse
     * a link that has one without a merchant signature.
     */
    public static function payLink(string $upiId, string $payeeName, int $paise, int $token): string
    {
        $query = http_build_query([
            'pa' => $upiId,
            'pn' => $payeeName,
            'am' => decimal_from_paise($paise),
            'cu' => 'INR',
            'tn' => 'Mahavir token ' . $token,
        ], '', '&', PHP_QUERY_RFC3986);
        // "@" is allowed in a query, and some UPI apps do not decode %40 in the payee address.
        return 'upi://pay?' . str_replace('%40', '@', $query);
    }

    /** The order's link with the shop's own UPI ID and English name (UPI apps show Latin text best). */
    public static function linkForOrder(array $order): string
    {
        $name = Settings::get('shop_name_en', 'Mahavir Ice Cream');
        return self::payLink(self::shopId(), $name, paise_from_decimal((string) $order['total']), (int) $order['token_no']);
    }
}
