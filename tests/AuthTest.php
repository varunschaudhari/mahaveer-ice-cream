<?php

declare(strict_types=1);

namespace Tests;

use App\Auth;
use App\Services\StaffService;

final class AuthTest extends DatabaseTestCase
{
    private const SYSTEM = ['id' => null, 'role' => 'super'];

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        $_SERVER['REMOTE_ADDR'] = '203.0.113.' . random_int(1, 250);
        Auth::forget();
    }

    private function staff(string $name = 'Ramesh', string $pin = '1234'): int
    {
        return (new StaffService($this->db()))->create(['name' => $name, 'role' => 'staff', 'pin' => $pin], self::SYSTEM);
    }

    private function owner(): int
    {
        return (new StaffService($this->db()))->create([
            'name' => 'Owner', 'role' => 'owner', 'username' => 'mahavir', 'password' => 'secret-pass', 'pin' => '9999',
        ], self::SYSTEM);
    }

    private function row(int $id): array
    {
        $stmt = $this->db()->prepare('SELECT * FROM staff WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    // ---------- PIN ----------

    public function testCorrectPin(): void
    {
        $id = $this->staff();
        $result = Auth::verifyPin($id, '1234');
        self::assertTrue($result['ok']);
        self::assertSame($id, (int) $result['staff']['id']);
    }

    public function testWrongPinCountsDownThenLocksForFifteenMinutes(): void
    {
        $id = $this->staff();
        foreach ([4, 3, 2, 1] as $left) {
            $result = Auth::verifyPin($id, '0000');
            self::assertSame('auth.wrong_pin', $result['error']);
            self::assertSame($left, $result['attempts_left']);
        }

        $result = Auth::verifyPin($id, '0000');
        self::assertSame('auth.locked', $result['error']);
        $minutes = (strtotime($result['locked_until']) - now()->getTimestamp()) / 60;
        self::assertEqualsWithDelta(15, $minutes, 1);
    }

    public function testLockedAccountRejectsEvenTheCorrectPin(): void
    {
        $id = $this->staff();
        for ($i = 0; $i < 5; $i++) {
            Auth::verifyPin($id, '0000');
        }
        self::assertSame('auth.locked', Auth::verifyPin($id, '1234')['error']);
    }

    public function testExpiredLockAllowsLoginAndRestartsTheCount(): void
    {
        $id = $this->staff();
        $this->db()->prepare("UPDATE staff SET locked_until = NOW() - INTERVAL 1 MINUTE WHERE id = ?")->execute([$id]);

        self::assertSame(4, Auth::verifyPin($id, '0000')['attempts_left']);
        self::assertTrue(Auth::verifyPin($id, '1234')['ok']);
        self::assertSame(0, (int) $this->row($id)['failed_attempts']);
        self::assertNull($this->row($id)['locked_until']);
    }

    public function testSuccessResetsFailedAttempts(): void
    {
        $id = $this->staff();
        Auth::verifyPin($id, '0000');
        Auth::verifyPin($id, '0000');
        Auth::verifyPin($id, '1234');
        self::assertSame(4, Auth::verifyPin($id, '0000')['attempts_left']);
    }

    public function testInactiveStaffCannotLogIn(): void
    {
        $id = $this->staff();
        $this->db()->prepare('UPDATE staff SET is_active = 0 WHERE id = ?')->execute([$id]);
        self::assertSame('auth.unknown_staff', Auth::verifyPin($id, '1234')['error']);
    }

    public function testPinsDoNotNeedToBeUnique(): void
    {
        $a = $this->staff('Ramesh', '1111');
        $b = $this->staff('Suresh', '1111');
        self::assertTrue(Auth::verifyPin($a, '1111')['ok']);
        self::assertTrue(Auth::verifyPin($b, '1111')['ok']);
    }

    public function testCounterStaffListsActiveStaffWithAPin(): void
    {
        $this->staff('Ramesh');
        $inactive = $this->staff('Old');
        $this->db()->prepare('UPDATE staff SET is_active = 0 WHERE id = ?')->execute([$inactive]);
        $this->owner();

        self::assertSame(['Ramesh', 'Owner'], array_column(Auth::counterStaff(), 'name'));
    }

    // ---------- password ----------

    public function testOwnerPasswordLogin(): void
    {
        $this->owner();
        self::assertTrue(Auth::verifyPassword('mahavir', 'secret-pass')['ok']);
        self::assertTrue(Auth::verifyPassword('  Mahavir ', 'secret-pass')['ok'], 'username is case-insensitive');
    }

    public function testWrongPasswordAndUnknownUserGiveTheSameMessage(): void
    {
        $this->owner();
        self::assertSame('auth.wrong_password', Auth::verifyPassword('mahavir', 'nope')['error']);
        self::assertSame('auth.wrong_password', Auth::verifyPassword('nobody', 'nope')['error']);
    }

    public function testDemotedOwnerCannotUsePasswordLogin(): void
    {
        $id = $this->owner();
        $this->db()->prepare("UPDATE staff SET role = 'staff' WHERE id = ?")->execute([$id]);
        self::assertFalse(Auth::verifyPassword('mahavir', 'secret-pass')['ok']);
    }

    // ---------- sessions ----------

    public function testPinLoginStartsACounterSession(): void
    {
        $id = $this->owner();
        self::assertTrue(Auth::loginWithPin($id, '9999')['ok']);

        $user = Auth::user();
        self::assertSame($id, $user['id']);
        self::assertSame('pin', $user['method']);
        self::assertFalse(Auth::isAdmin($user), 'admin needs a password login, even for the owner');
    }

    public function testPasswordLoginStartsAnAdminSession(): void
    {
        $this->owner();
        self::assertTrue(Auth::loginWithPassword('mahavir', 'secret-pass')['ok']);
        self::assertTrue(Auth::isAdmin(Auth::user()));
    }

    public function testCounterSessionExpiresAfterTwelveHoursIdle(): void
    {
        $id = $this->staff();
        Auth::loginWithPin($id, '1234');

        $_SESSION['auth']['last_seen'] = time() - 11 * 3600;
        Auth::forget();
        self::assertNotNull(Auth::user());

        $_SESSION['auth']['last_seen'] = time() - 13 * 3600;
        Auth::forget();
        self::assertNull(Auth::user());
    }

    public function testAdminSessionLastsThirtyDaysIdle(): void
    {
        $this->owner();
        Auth::loginWithPassword('mahavir', 'secret-pass');

        $_SESSION['auth']['last_seen'] = time() - 29 * 86400;
        Auth::forget();
        self::assertNotNull(Auth::user());

        $_SESSION['auth']['last_seen'] = time() - 31 * 86400;
        Auth::forget();
        self::assertNull(Auth::user());
    }

    public function testDeactivatedUserIsLoggedOutOnTheNextRequest(): void
    {
        $id = $this->staff();
        Auth::loginWithPin($id, '1234');
        $this->db()->prepare('UPDATE staff SET is_active = 0 WHERE id = ?')->execute([$id]);
        Auth::forget();
        self::assertNull(Auth::user());
    }

    public function testLogout(): void
    {
        $id = $this->staff();
        Auth::loginWithPin($id, '1234');
        Auth::logout();
        self::assertNull(Auth::user());
    }

    public function testTooManyFailuresFromOneAddressBlockEvenACorrectPin(): void
    {
        $guessed = $this->staff('Ramesh', '1234');
        $other = $this->staff('Suresh', '5678');
        for ($i = 0; $i < Auth::IP_MAX_PIN_FAILURES; $i++) {
            self::assertFalse(Auth::loginWithPin($guessed, '0000')['ok']);
        }
        self::assertSame('auth.too_many', Auth::loginWithPin($other, '5678')['error']);

        $_SERVER['REMOTE_ADDR'] = '198.51.100.7';
        self::assertTrue(Auth::loginWithPin($other, '5678')['ok'], 'another address is not affected');
    }
}
