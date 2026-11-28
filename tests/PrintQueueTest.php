<?php

declare(strict_types=1);

namespace Tests;

use App\Services\PrintQueueService;
use App\ValidationException;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;

final class PrintQueueTest extends DatabaseTestCase
{
    public function testParsesOnlyTheCountersOwnPrintPages(): void
    {
        self::assertSame(['bill', 12, false], PrintQueueService::parse('/counter/print.php?type=bill&id=12'));
        self::assertSame(['bill', 12, true], PrintQueueService::parse('/counter/print.php?type=bill&copy=1&id=12'));
        self::assertSame(['table', 5, false], PrintQueueService::parse('/counter/print.php?type=table&id=5'));
        self::assertSame(['day', null, false], PrintQueueService::parse('/counter/print.php?type=day'));
    }

    public static function refusedUrls(): array
    {
        return [
            'other host' => ['https://example.com/counter/print.php?type=bill&id=1'],
            'other page' => ['/admin/settings.php?type=bill&id=1'],
            'kitchen ticket' => ['/counter/print.php?type=kot&id=1'],
            'no id' => ['/counter/print.php?type=bill'],
            'bad id' => ['/counter/print.php?type=bill&id=1x'],
            'empty' => [''],
        ];
    }

    #[DataProvider('refusedUrls')]
    public function testRefusesAnythingElse(string $url): void
    {
        $this->expectException(ValidationException::class);
        PrintQueueService::parse($url);
    }

    public function testEachJobIsClaimedOnceOldestFirst(): void
    {
        $queue = new PrintQueueService($this->db());
        $now = new DateTimeImmutable();
        $queue->add('/counter/print.php?type=bill&id=7', 1);
        $queue->add('/counter/print.php?type=table&copy=1&id=3', 1);
        self::assertSame(2, $queue->pendingCount($now));

        self::assertSame(['/counter/print.php?type=bill&id=7', '/counter/print.php?type=table&copy=1&id=3'], $queue->claim($now));
        self::assertSame([], $queue->claim($now), 'a second counter gets nothing: no double print');
        self::assertSame(0, $queue->pendingCount($now));
    }

    public function testStaleJobsAreNotPrintedAndOldOnesAreDeleted(): void
    {
        $now = new DateTimeImmutable();
        $insert = $this->db()->prepare("INSERT INTO print_jobs (job_type, ref_id, created_at) VALUES ('bill', ?, ?)");
        $insert->execute([1, $now->modify('-31 minutes')->format('Y-m-d H:i:s')]);  // counter PC was off
        $insert->execute([2, $now->modify('-3 days')->format('Y-m-d H:i:s')]);

        $queue = new PrintQueueService($this->db());
        self::assertSame(0, $queue->pendingCount($now));
        self::assertSame([], $queue->claim($now));
        self::assertSame(1, $queue->deleteOld($now), 'only the job older than 2 days goes');
    }
}
