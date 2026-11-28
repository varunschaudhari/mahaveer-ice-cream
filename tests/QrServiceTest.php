<?php

declare(strict_types=1);

namespace Tests;

use App\Services\QrService;
use PHPUnit\Framework\TestCase;

final class QrServiceTest extends TestCase
{
    public function testOnlyProductionPrintsCleanCodesForTheShop(): void
    {
        self::assertSame('live', QrService::mode('production', 'https://order.mahaveericecream.in/'));
        // Staging: scannable for testing, with the "not for customers" band (decision 2026-11-28).
        self::assertSame('test', QrService::mode('staging', 'https://mahaveer.foreverstory.in/'));
        // Everything else keeps the stamp across the code.
        self::assertSame('blocked', QrService::mode('staging', 'http://mahaveer.foreverstory.in/'));
        self::assertSame('blocked', QrService::mode('production', 'http://order.mahaveericecream.in/'));
        self::assertSame('blocked', QrService::mode('local', 'http://localhost:8000/'));
        self::assertSame('blocked', QrService::mode('local', 'https://localhost:8000/'));
    }

    public function testCodesAreSvgDataUris(): void
    {
        self::assertStringStartsWith('data:image/svg+xml;base64,', QrService::svgDataUri('https://mahaveer.foreverstory.in/'));
    }

    public function testPaymentCodeIsAPngForTheGallery(): void
    {
        $png = QrService::pngBinary('upi://pay?pa=shop@okaxis&pn=Mahavir%20Ice%20Cream&am=20.00&cu=INR&tn=Mahavir%20token%206');

        self::assertStringStartsWith("\x89PNG\r\n\x1a\n", $png);
        $size = getimagesizefromstring($png);
        self::assertSame($size[0], $size[1]);
        self::assertGreaterThanOrEqual(300, $size[0]); // big enough to scan from a screenshot
    }
}
