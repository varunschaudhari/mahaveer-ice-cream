<?php

declare(strict_types=1);

namespace Tests;

use App\Services\PaymentService;
use App\ValidationException;
use PHPUnit\Framework\TestCase;

final class PaymentServiceTest extends TestCase
{
    public function testCashAndUpiPayTheWholeTotal(): void
    {
        self::assertSame([['mode' => 'cash', 'amount' => 18000]], PaymentService::split('cash', 18000));
        self::assertSame([['mode' => 'upi_counter', 'amount' => 18000]], PaymentService::split('upi', 18000));
    }

    public function testMixedSplitsCashAndUpi(): void
    {
        self::assertSame(
            [['mode' => 'cash', 'amount' => 10000], ['mode' => 'upi_counter', 'amount' => 8000]],
            PaymentService::split('mixed', 18000, 10000)
        );
    }

    public function testMixedNeedsBothPartsAboveZero(): void
    {
        foreach ([0, 18000, 20000, -100] as $cash) {
            try {
                PaymentService::split('mixed', 18000, $cash);
                self::fail("accepted cash part {$cash}");
            } catch (ValidationException $e) {
                self::assertSame('payment.error.mixed', $e->errors['_']);
            }
        }
    }

    public function testUnknownMethodIsRefused(): void
    {
        $this->expectException(ValidationException::class);
        PaymentService::split('card', 18000);
    }

    public function testFullyDiscountedBillHasNoPaymentRows(): void
    {
        self::assertSame([], PaymentService::split('cash', 0));
    }

    public function testChange(): void
    {
        self::assertSame(2000, PaymentService::change(18000, 20000)); // the M2 example: ₹200 for ₹180
        self::assertSame(0, PaymentService::change(18000, 18000));
        self::assertNull(PaymentService::change(18000, 10000));
    }
}
