<?php

declare(strict_types=1);

namespace Tests;

use App\Lang;
use App\Services\BulkService;
use App\Services\OrderCard;
use App\Services\OrderService;
use App\Services\TableService;
use DateTimeImmutable;

/** What the owner's phone and the counter need for M6 (SPEC §11; BUILD_PLAN M6). */
final class OwnerPhoneTest extends DatabaseTestCase
{
    private OrderService $orders;
    private TableService $tables;
    private DateTimeImmutable $now;
    private int $ownerId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->orders = new OrderService($this->db());
        $this->tables = new TableService($this->db());
        $this->now = new DateTimeImmutable('2026-11-26 19:00');
        $this->ownerId = $this->makeOwner();
    }

    protected function tearDown(): void
    {
        Lang::set(Lang::DEFAULT);
        parent::tearDown();
    }

    private function variant(string $itemEn, string $labelEn): int
    {
        $stmt = $this->db()->prepare(
            'SELECT v.id FROM item_variants v JOIN items i ON i.id = v.item_id WHERE i.name_en = ? AND v.label_en = ?'
        );
        $stmt->execute([$itemEn, $labelEn]);
        return (int) $stmt->fetchColumn();
    }

    private function tableId(string $code): int
    {
        $stmt = $this->db()->prepare('SELECT id FROM dining_tables WHERE code = ?');
        $stmt->execute([$code]);
        return (int) $stmt->fetchColumn();
    }

    private function tableOrder(string $code, DateTimeImmutable $at): array
    {
        $stmt = $this->db()->prepare('SELECT qr_token FROM dining_tables WHERE code = ?');
        $stmt->execute([$code]);
        return $this->orders->placeTableOrder([
            'table_token' => (string) $stmt->fetchColumn(),
            'order_key' => bin2hex(random_bytes(16)),
            'name' => 'Asha',
            'mobile' => '9876543210',
            'note' => '',
            'lines' => [['variant_id' => $this->variant('Badam Shake', 'Full'), 'qty' => 1]],
        ], $at);
    }

    public function testCounterPrintsKitchenTicketsForTableOrdersAcceptedInTheLastTenMinutes(): void
    {
        $old = $this->tableOrder('T1', $this->now->modify('-30 minutes'));
        $this->orders->accept((int) $old['id'], $this->ownerId, $this->now->modify('-11 minutes'));
        $fresh = $this->tableOrder('T2', $this->now->modify('-5 minutes'));
        $this->orders->accept((int) $fresh['id'], $this->ownerId, $this->now->modify('-2 minutes'));
        $waiting = $this->tableOrder('T3', $this->now->modify('-1 minute'));
        $takeaway = $this->orders->placeTakeaway([
            'order_key' => bin2hex(random_bytes(16)),
            'name' => 'Ravi',
            'mobile' => '9822000000',
            'slot' => '19:30',
            'note' => '',
            'lines' => [['variant_id' => $this->variant('Badam Shake', 'Full'), 'qty' => 1]],
        ], $this->now->modify('-3 minutes'));
        $this->orders->accept((int) $takeaway['id'], $this->ownerId, $this->now->modify('-1 minute'));

        self::assertSame([(int) $fresh['id']], $this->orders->recentlyAcceptedTableOrders($this->now));
        self::assertNotContains((int) $waiting['id'], $this->orders->recentlyAcceptedTableOrders($this->now));

        // Served already (very fast kitchen) still needs its ticket printed.
        $this->orders->markServed((int) $fresh['id'], $this->ownerId, $this->now);
        self::assertSame([(int) $fresh['id']], $this->orders->recentlyAcceptedTableOrders($this->now));
    }

    public function testRaiseAlertSaysWhetherANewAlertWasAdded(): void
    {
        $this->tableOrder('T4', $this->now);
        $t4 = $this->tableId('T4');

        self::assertTrue($this->tables->raiseAlert($t4, 'request_bill', $this->now));
        // Tapped again later while the first is still open: no new alert, so no second push.
        self::assertFalse($this->tables->raiseAlert($t4, 'request_bill', $this->now->modify('+1 minute')));
        self::assertTrue($this->tables->raiseAlert($t4, 'call_staff', $this->now->modify('+2 minutes')));
    }

    public function testOwnerCancelsAnAcceptedOrderWithTheirOwnLogin(): void
    {
        $order = $this->tableOrder('T5', $this->now);
        $this->orders->accept((int) $order['id'], $this->ownerId, $this->now);

        // owner_action.php passes the logged-in owner as the approving owner: no PIN on the phone.
        $this->orders->cancel((int) $order['id'], 'Item ran out', $this->ownerId, $this->ownerId, $this->now);

        $saved = $this->orders->find((int) $order['id']);
        self::assertSame('cancelled', $saved['status']);
        self::assertSame($this->ownerId, (int) $saved['cancelled_by']);
    }

    public function testBulkCardForTheOwnersPhone(): void
    {
        $this->db()->exec("INSERT INTO staff (name, role) VALUES ('Ramesh', 'staff')");
        $staffId = (int) $this->db()->lastInsertId();
        $bulk = new BulkService($this->db());
        $order = $bulk->request([
            'order_key' => bin2hex(random_bytes(16)),
            'name' => 'Sunita',
            'mobile' => '9822000000',
            'note' => 'लग्न',
            'event_date' => '2026-11-28',
            'pickup_time' => '18:00',
            'lines' => [['variant_id' => $this->variant('Badam Shake', 'Full'), 'qty' => '2.5']],
        ], $this->now->setTime(12, 0));
        $bulk->approve((int) $order['id'], 20000, $this->ownerId, $this->now);
        $bulk->recordPayment((int) $order['id'], 'cash', 20000, $staffId, $this->now);
        Lang::set('en');

        $card = OrderCard::bulk($bulk->open()[0]);

        self::assertSame((int) $order['id'], $card['id']);
        self::assertSame('bulk', $card['type']);
        self::assertSame('preparing', $card['status']);
        self::assertSame('Sunita', $card['customer']);
        self::assertSame('9822000000', $card['mobile']);
        self::assertStringStartsWith('28/11 · 6:00', $card['pickup']);
        self::assertSame('2.5', $card['items'][0]['qty']);
        self::assertSame(paise_from_decimal((string) $order['total']), $card['total_paise']);
        self::assertSame(20000, $card['advance_paise']);
        self::assertSame(20000, $card['paid_paise']);
        self::assertSame('लग्न', $card['note']);
    }
}
