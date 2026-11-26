<?php

declare(strict_types=1);

namespace Tests;

use App\Db;
use App\Services\SequenceService;
use DateTimeImmutable;
use LogicException;

final class SequenceServiceTest extends DatabaseTestCase
{
    private SequenceService $sequences;

    protected function setUp(): void
    {
        parent::setUp(); // opens the test transaction the sequences need
        $this->sequences = new SequenceService($this->db());
    }

    public function testTokensCountUpWithinADay(): void
    {
        $day = new DateTimeImmutable('2026-11-26 10:05');
        self::assertSame([1, 2, 3], [
            $this->sequences->nextToken($day),
            $this->sequences->nextToken($day->modify('+3 hours')),
            $this->sequences->nextToken($day->modify('+12 hours')),
        ]);
    }

    public function testTokenResetsToOneTheNextDay(): void
    {
        $today = new DateTimeImmutable('2026-11-26 21:00');
        $this->sequences->nextToken($today);
        $this->sequences->nextToken($today);

        self::assertSame(1, $this->sequences->nextToken($today->modify('+1 day')));
        self::assertSame(3, $this->sequences->nextToken($today), 'yesterday keeps its own count');
    }

    public function testBillNumbersContinueAcrossDaysAndNeverRepeat(): void
    {
        $numbers = [];
        for ($i = 0; $i < 30; $i++) {
            $this->sequences->nextToken(new DateTimeImmutable('2026-11-' . (20 + intdiv($i, 10))));
            $numbers[] = $this->sequences->nextBillNo();
        }
        self::assertSame(range(1, 30), $numbers);
    }

    public function testNumbersCanOnlyBeTakenInsideATransaction(): void
    {
        $outside = new SequenceService(Db::connect((string) config('DB_NAME')));
        $this->expectException(LogicException::class);
        $outside->nextBillNo();
    }
}
