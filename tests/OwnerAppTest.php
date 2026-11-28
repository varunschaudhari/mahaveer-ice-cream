<?php

declare(strict_types=1);

namespace Tests;

use App\Services\OwnerAppService;
use PHPUnit\Framework\TestCase;

/** The owner app download in admin: each server offers only its own, newest APK. */
final class OwnerAppTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/mahavir-apk-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        foreach (['staging-1.0.2', 'staging-1.0.10', 'production-1.0.1', 'staging-test'] as $name) {
            file_put_contents($this->dir . "/mahavir-owner-{$name}.apk", str_repeat('x', 2048));
        }
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
    }

    public function testNewestVersionOfThisServersApp(): void
    {
        self::assertSame(
            ['url' => '/downloads/mahavir-owner-staging-1.0.10.apk', 'version' => '1.0.10', 'size_mb' => '0.0'],
            OwnerAppService::latest('staging', $this->dir)
        );
        self::assertSame('1.0.1', OwnerAppService::latest('production', $this->dir)['version']);
    }

    public function testNoAppForOtherServers(): void
    {
        self::assertNull(OwnerAppService::latest('local', $this->dir));
        self::assertNull(OwnerAppService::latest('../staging', $this->dir));
    }

    public function testThePublishedStagingAppIsInTheRepo(): void
    {
        self::assertNotNull(OwnerAppService::latest('staging'), 'order/downloads/ should hold the staging APK');
    }
}
