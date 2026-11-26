<?php

declare(strict_types=1);

namespace Tests;

use App\Services\CustomerService;
use DateTimeImmutable;

/** Admin → Customers (SPEC §12, BUILD_PLAN M7). */
final class CustomerAdminTest extends DatabaseTestCase
{
    private CustomerService $customers;
    private int $ownerId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->customers = new CustomerService($this->db());
        $this->ownerId = $this->makeOwner();
        $at = new DateTimeImmutable('2026-11-20 18:00');
        foreach ([['9876543210', 'Asha Patil'], ['9822000000', 'सुनीता'], ['7000012345', 'Ravi 100% Shah'], ['9123456780', 'Asha_K']] as $i => [$mobile, $name]) {
            $this->customers->recordOrder($mobile, $name, $at->modify("+{$i} days"));
        }
    }

    private function id(string $mobile): int
    {
        $stmt = $this->db()->prepare('SELECT id FROM customers WHERE mobile = ?');
        $stmt->execute([$mobile]);
        return (int) $stmt->fetchColumn();
    }

    private function names(string $query, string $filter = 'all'): array
    {
        return array_column($this->customers->search($query, $filter)['rows'], 'name');
    }

    public function testListIsMostRecentOrderFirst(): void
    {
        $result = $this->customers->search('', 'all');
        self::assertSame(4, $result['total']);
        self::assertSame(['Asha_K', 'Ravi 100% Shah', 'सुनीता', 'Asha Patil'], array_column($result['rows'], 'name'));
    }

    public function testSearchByMobileDigits(): void
    {
        self::assertSame(['Asha Patil'], $this->names('98765'));
        self::assertSame(['Asha Patil'], $this->names('+91 98765 43210'), 'a pasted number with +91 still matches');
        self::assertSame(['Asha_K', 'सुनीता', 'Asha Patil'], $this->names('9'));
    }

    public function testSearchByNameIgnoresCaseAndTreatsWildcardsAsText(): void
    {
        self::assertSame(['Asha_K', 'Asha Patil'], $this->names('asha'));
        self::assertSame(['सुनीता'], $this->names('सुनी'));
        self::assertSame(['Ravi 100% Shah'], $this->names('100%'));
        self::assertSame(['Asha_K'], $this->names('a_k'), '"_" is not a one-letter wildcard');
        self::assertSame([], $this->names('nobody'));
    }

    public function testFiltersAndPages(): void
    {
        $this->db()->exec("UPDATE customers SET no_show_count = 1 WHERE mobile = '9822000000'");
        $this->db()->exec("UPDATE customers SET no_show_count = 2, is_blocked = 1 WHERE mobile = '7000012345'");

        self::assertSame(['Ravi 100% Shah'], $this->names('', 'blocked'));
        self::assertSame(['Ravi 100% Shah', 'सुनीता'], $this->names('', 'no_shows'));
        self::assertSame(['Ravi 100% Shah'], array_column($this->customers->blocked(), 'name'));

        $page2 = $this->customers->search('', 'all', 2, 3);
        self::assertSame(4, $page2['total']);
        self::assertSame(['Asha Patil'], array_column($page2['rows'], 'name'));
    }

    public function testBlockAndUnblockAreAudited(): void
    {
        $id = $this->id('9876543210');
        $this->customers->block($id, $this->ownerId);
        $this->customers->block($id, $this->ownerId); // second tap: no second log entry

        self::assertTrue($this->customers->isBlocked('9876543210'));
        self::assertSame(1, (int) $this->db()->query("SELECT COUNT(*) FROM audit_log WHERE action = 'customer_block'")->fetchColumn());

        $this->db()->exec("UPDATE customers SET no_show_count = 3 WHERE id = {$id}");
        $this->customers->unblock($id, $this->ownerId);
        self::assertFalse($this->customers->isBlocked('9876543210'));
        self::assertSame(0, (int) $this->customers->find($id)['no_show_count'], 'unblocking starts the no-show count again');
        self::assertSame(1, (int) $this->db()->query("SELECT COUNT(*) FROM audit_log WHERE action = 'customer_unblock'")->fetchColumn());

        $this->customers->block(999999, $this->ownerId); // unknown id: nothing happens
        self::assertNull($this->customers->find(999999));
    }

    public function testExportIsWrittenToTheAuditLog(): void
    {
        $rows = $this->customers->export('asha', 'all', $this->ownerId);

        self::assertSame(['Asha_K', 'Asha Patil'], array_column($rows, 'name'));
        $log = $this->db()->query("SELECT staff_id, details FROM audit_log WHERE action = 'customers_export'")->fetch();
        self::assertSame($this->ownerId, (int) $log['staff_id']);
        self::assertSame(['count' => 2, 'filter' => 'all', 'search' => 'asha'], json_decode($log['details'], true));
    }

    public function testCustomerOrdersNewestFirst(): void
    {
        $customerId = $this->id('9876543210');
        $this->db()->exec(
            "INSERT INTO orders (order_key, token_no, token_date, order_type, customer_id, status, subtotal, total, created_at) VALUES
             ('" . str_repeat('a', 32) . "', 1, '2026-11-20', 'takeaway', {$customerId}, 'completed', 80, 85, '2026-11-20 18:00:00'),
             ('" . str_repeat('b', 32) . "', 4, '2026-11-21', 'takeaway', {$customerId}, 'no_show', 80, 85, '2026-11-21 18:00:00')"
        );

        $orders = $this->customers->orders($customerId);
        self::assertSame(['no_show', 'completed'], array_column($orders, 'status'));
        self::assertSame([], $this->customers->orders($this->id('9822000000')));
    }
}
