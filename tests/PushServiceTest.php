<?php

declare(strict_types=1);

namespace Tests;

use App\Lang;
use App\Services\OrderService;
use App\Services\PushService;
use App\ValidationException;
use DateTimeImmutable;
use RuntimeException;

/** Web Push to the owner's phone (SPEC §11, ARCHITECTURE §9; BUILD_PLAN M6). No real push service is called. */
final class PushServiceTest extends DatabaseTestCase
{
    /** @var list<array{endpoint: string, payload: string}> what the fake push service received */
    private array $sent = [];

    /** endpoint → result the fake push service gives (default: delivered) */
    private array $results = [];

    private PushService $push;
    private int $ownerId;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sent = [];
        $this->results = [];
        $this->push = new PushService($this->db(), function (array $subscription, string $payload): array {
            $this->sent[] = ['endpoint' => $subscription['endpoint'], 'payload' => $payload];
            return $this->results[$subscription['endpoint']] ?? ['ok' => true, 'expired' => false, 'reason' => ''];
        });
        $this->ownerId = $this->makeOwner();
        $this->now = new DateTimeImmutable('2026-11-26 18:00');
    }

    protected function tearDown(): void
    {
        Lang::set(Lang::DEFAULT);
        parent::tearDown();
    }

    private function subscription(string $name): array
    {
        return [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/' . $name,
            'keys' => ['p256dh' => str_repeat('B', 87), 'auth' => 'abcdefghijklmnopqrstuv'],
        ];
    }

    private function endpoint(string $name): string
    {
        return $this->subscription($name)['endpoint'];
    }

    private function staff(string $name, string $role, int $active = 1): int
    {
        $this->db()->prepare('INSERT INTO staff (name, role, is_active) VALUES (?, ?, ?)')->execute([$name, $role, $active]);
        return (int) $this->db()->lastInsertId();
    }

    private function sentTo(): array
    {
        $endpoints = array_column($this->sent, 'endpoint');
        sort($endpoints);
        return $endpoints;
    }

    private function message(): array
    {
        return ['title' => ['mr' => 'नवीन', 'en' => 'New'], 'body' => ['mr' => 'ब', 'en' => 'b'], 'url' => '/owner/', 'tag' => 'x'];
    }

    // ---------------------------------------------------------------
    // Subscriptions
    // ---------------------------------------------------------------

    public function testSubscribeSavesTheDeviceAndKeepsItsMuteWhenSentAgain(): void
    {
        $this->push->subscribe($this->ownerId, $this->subscription('phone'));
        self::assertSame(['muted' => false], $this->push->status($this->ownerId, $this->endpoint('phone')));

        self::assertTrue($this->push->setMuted($this->ownerId, $this->endpoint('phone'), true));
        // The page sends the subscription again on every load: the mute switch must survive that.
        $this->push->subscribe($this->ownerId, $this->subscription('phone'));
        self::assertSame(['muted' => true], $this->push->status($this->ownerId, $this->endpoint('phone')));
        self::assertSame(1, (int) $this->db()->query('SELECT COUNT(*) FROM push_subscriptions')->fetchColumn());
    }

    public function testBadSubscriptionsAreRefused(): void
    {
        foreach ([
            ['endpoint' => 'http://insecure.example/x', 'keys' => ['p256dh' => str_repeat('B', 87), 'auth' => 'abcdefghij']],
            ['endpoint' => $this->endpoint('a'), 'keys' => ['p256dh' => 'short', 'auth' => 'abcdefghij']],
            ['endpoint' => $this->endpoint('a'), 'keys' => ['p256dh' => str_repeat('B', 87), 'auth' => 'bad auth!']],
            ['endpoint' => 'https://x/' . str_repeat('a', 500), 'keys' => ['p256dh' => str_repeat('B', 87), 'auth' => 'abcdefghij']],
            [],
        ] as $bad) {
            try {
                $this->push->subscribe($this->ownerId, $bad);
                self::fail('Expected a ValidationException');
            } catch (ValidationException $e) {
                self::assertSame('owner.error.subscription', $e->first());
            }
        }
    }

    public function testMuteAndUnsubscribeOnlyTouchTheOwnersOwnDevice(): void
    {
        $other = $this->staff('Partner', 'owner');
        $this->push->subscribe($this->ownerId, $this->subscription('mine'));
        $this->push->subscribe($other, $this->subscription('theirs'));

        self::assertFalse($this->push->setMuted($this->ownerId, $this->endpoint('theirs'), true));
        self::assertSame(['muted' => false], $this->push->status($other, $this->endpoint('theirs')));

        $this->push->unsubscribe($this->ownerId, $this->endpoint('theirs'));
        self::assertNotNull($this->push->status($other, $this->endpoint('theirs')));
        $this->push->unsubscribe($this->ownerId, $this->endpoint('mine'));
        self::assertNull($this->push->status($this->ownerId, $this->endpoint('mine')));
    }

    // ---------------------------------------------------------------
    // Sending
    // ---------------------------------------------------------------

    public function testNotifiesUnmutedDevicesOfActiveOwnersOnly(): void
    {
        $super = $this->staff('Developer', 'super');
        $staff = $this->staff('Ramesh', 'staff');
        $former = $this->staff('Old owner', 'owner', 0);
        $this->push->subscribe($this->ownerId, $this->subscription('owner-phone'));
        $this->push->subscribe($this->ownerId, $this->subscription('owner-tablet'));
        $this->push->subscribe($super, $this->subscription('super'));
        $this->push->subscribe($staff, $this->subscription('staff'));
        $this->push->subscribe($former, $this->subscription('former'));
        $this->push->setMuted($this->ownerId, $this->endpoint('owner-tablet'), true);

        self::assertSame(2, $this->push->notifyOwners($this->message()));
        self::assertSame([$this->endpoint('owner-phone'), $this->endpoint('super')], $this->sentTo());
        self::assertSame($this->message(), json_decode($this->sent[0]['payload'], true));
    }

    public function testUnmutingStartsNotificationsAgain(): void
    {
        $this->push->subscribe($this->ownerId, $this->subscription('phone'));
        $this->push->setMuted($this->ownerId, $this->endpoint('phone'), true);
        self::assertSame(0, $this->push->notifyOwners($this->message()));
        self::assertSame([], $this->sent);

        $this->push->setMuted($this->ownerId, $this->endpoint('phone'), false);
        self::assertSame(1, $this->push->notifyOwners($this->message()));
    }

    public function testDevicesThePushServiceReportsGoneAreDeletedOthersKept(): void
    {
        $this->push->subscribe($this->ownerId, $this->subscription('gone'));
        $this->push->subscribe($this->ownerId, $this->subscription('flaky'));
        $this->push->subscribe($this->ownerId, $this->subscription('fine'));
        $this->results[$this->endpoint('gone')] = ['ok' => false, 'expired' => true, 'reason' => '410 Gone'];
        $this->results[$this->endpoint('flaky')] = ['ok' => false, 'expired' => false, 'reason' => '500 Server error'];

        self::assertSame(1, $this->push->notifyOwners($this->message()));
        self::assertNull($this->push->status($this->ownerId, $this->endpoint('gone')));
        self::assertNotNull($this->push->status($this->ownerId, $this->endpoint('flaky')), 'a temporary failure keeps the device');
        self::assertNotNull($this->push->status($this->ownerId, $this->endpoint('fine')));
    }

    public function testAPushFailureNeverThrows(): void
    {
        $push = new PushService($this->db(), static function (): array {
            throw new RuntimeException('network down');
        });
        $push->subscribe($this->ownerId, $this->subscription('phone'));

        self::assertSame(0, $push->notifyOwners($this->message()));
        self::assertFalse($push->notifyDevice($this->ownerId, $this->endpoint('phone'), $this->message()));
    }

    public function testNothingToSendWhenNoOwnerHasSubscribed(): void
    {
        self::assertSame(0, $this->push->notifyOwners($this->message()));
        self::assertSame([], $this->sent);
    }

    public function testTestNotificationGoesToThisDeviceOnlyEvenWhenMuted(): void
    {
        $other = $this->staff('Partner', 'owner');
        $this->push->subscribe($this->ownerId, $this->subscription('mine'));
        $this->push->subscribe($other, $this->subscription('theirs'));
        $this->push->setMuted($this->ownerId, $this->endpoint('mine'), true);

        self::assertTrue($this->push->notifyDevice($this->ownerId, $this->endpoint('mine'), PushService::forTest()));
        self::assertSame([$this->endpoint('mine')], $this->sentTo());
        self::assertFalse($this->push->notifyDevice($this->ownerId, $this->endpoint('theirs'), PushService::forTest()));
    }

    // ---------------------------------------------------------------
    // Messages: both languages, the phone picks one
    // ---------------------------------------------------------------

    private function variant(string $itemEn, string $labelEn): int
    {
        $stmt = $this->db()->prepare(
            'SELECT v.id FROM item_variants v JOIN items i ON i.id = v.item_id WHERE i.name_en = ? AND v.label_en = ?'
        );
        $stmt->execute([$itemEn, $labelEn]);
        return (int) $stmt->fetchColumn();
    }

    public function testTakeawayMessage(): void
    {
        $order = (new OrderService($this->db()))->placeTakeaway([
            'order_key' => bin2hex(random_bytes(16)),
            'name' => 'Asha',
            'mobile' => '9876543210',
            'slot' => '19:30',
            'note' => '',
            'lines' => [['variant_id' => $this->variant('Badam Shake', 'Full'), 'qty' => 2]],
        ], $this->now);
        Lang::set('en');

        $message = PushService::forOrder($order);

        self::assertSame('en', Lang::current(), 'the page language is restored');
        self::assertSame('New takeaway order · Token ' . $order['token_no'], $message['title']['en']);
        self::assertSame('नवीन पार्सल ऑर्डर · टोकन ' . $order['token_no'], $message['title']['mr']);
        $total = money(paise_from_decimal((string) $order['total']));
        self::assertStringStartsWith('Pickup 7:30', $message['body']['en']);
        self::assertStringContainsString($total, $message['body']['en']);
        self::assertStringContainsString('2× ', $message['body']['en']);
        self::assertStringStartsWith('पिकअप', $message['body']['mr']);
        self::assertSame('/owner/?order=' . $order['id'], $message['url']);
        self::assertSame('order-' . $order['id'], $message['tag']);
    }

    public function testTableMessageShowsTheTableNumber(): void
    {
        $token = (string) $this->db()->query("SELECT qr_token FROM dining_tables WHERE code = 'T4'")->fetchColumn();
        $order = (new OrderService($this->db()))->placeTableOrder([
            'table_token' => $token,
            'order_key' => bin2hex(random_bytes(16)),
            'name' => 'Asha',
            'mobile' => '9876543210',
            'note' => '',
            'lines' => [['variant_id' => $this->variant('Badam Shake', 'Full'), 'qty' => 1]],
        ], $this->now);

        $message = PushService::forOrder($order);

        self::assertSame(['mr' => 'टेबल 4 · नवीन ऑर्डर', 'en' => 'Table 4 · new order'], $message['title']);
        self::assertStringEndsWith(money(paise_from_decimal((string) $order['total'])), $message['body']['en']);
    }

    public function testBulkAndBillAndTestMessages(): void
    {
        $bulk = PushService::forOrder([
            'id' => 7, 'order_type' => 'bulk', 'customer_name' => 'Sunita', 'pickup_at' => '2026-11-28 18:00:00',
            'total' => '1600.00', 'token_no' => 3, 'items' => [],
        ]);
        self::assertSame(['mr' => 'मोठ्या ऑर्डरची विनंती · Sunita', 'en' => 'Bulk order request · Sunita'], $bulk['title']);
        self::assertStringStartsWith('For 28/11, 6:00', $bulk['body']['en']);
        self::assertStringEndsWith('₹1,600', $bulk['body']['en']);

        $bill = PushService::forBillRequest('T12');
        self::assertSame(['mr' => 'टेबल 12 ला बिल हवे आहे', 'en' => 'Table 12 wants the bill'], $bill['title']);
        self::assertSame('/owner/', $bill['url']);
        self::assertSame('bill-T12', $bill['tag']);

        self::assertSame('Test notification', PushService::forTest()['title']['en']);
    }
}
