<?php

declare(strict_types=1);

namespace Tests;

use App\Services\StaffService;
use App\ValidationException;

final class StaffServiceTest extends DatabaseTestCase
{
    private const SYSTEM = ['id' => null, 'role' => 'super'];

    private StaffService $service;
    private array $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new StaffService($this->db());
        $id = $this->service->create([
            'name' => 'Owner', 'role' => 'owner', 'username' => 'mahavir', 'password' => 'secret-pass', 'pin' => '9999',
        ], self::SYSTEM);
        $this->owner = ['id' => $id, 'role' => 'owner'];
    }

    private function errorsOf(callable $fn): array
    {
        try {
            $fn();
        } catch (ValidationException $e) {
            return $e->errors;
        }
        self::fail('Expected a ValidationException');
    }

    private function lastAudit(): array
    {
        $row = $this->db()->query('SELECT * FROM audit_log ORDER BY id DESC LIMIT 1')->fetch();
        $row['details'] = json_decode((string) $row['details'], true);
        return $row;
    }

    public function testCreateStaffWithPinStoresOnlyAHash(): void
    {
        $id = $this->service->create(['name' => 'Ramesh', 'role' => 'staff', 'pin' => '1234'], $this->owner);
        $row = $this->db()->query("SELECT * FROM staff WHERE id = {$id}")->fetch();

        self::assertNotSame('1234', $row['pin_hash']);
        self::assertTrue(password_verify('1234', $row['pin_hash']));
        self::assertNull($row['username']);
        self::assertNull($row['password_hash']);

        $audit = $this->lastAudit();
        self::assertSame('staff_create', $audit['action']);
        self::assertStringNotContainsString('1234', json_encode($audit['details']));
    }

    public function testStaffNeedANameAndFourDigitPin(): void
    {
        $errors = $this->errorsOf(fn () => $this->service->create(['name' => '', 'role' => 'staff', 'pin' => '12'], $this->owner));
        self::assertSame(['name' => 'staff.error.name', 'pin' => 'staff.error.pin'], $errors);
    }

    public function testOwnerAccountsNeedUsernameAndPassword(): void
    {
        $errors = $this->errorsOf(fn () => $this->service->create(['name' => 'Son', 'role' => 'owner', 'username' => 'A', 'password' => 'short'], $this->owner));
        self::assertSame('staff.error.username', $errors['username']);
        self::assertSame('staff.error.password', $errors['password']);
    }

    public function testUsernamesAreUnique(): void
    {
        $errors = $this->errorsOf(fn () => $this->service->create([
            'name' => 'Other', 'role' => 'owner', 'username' => 'mahavir', 'password' => 'another-pass',
        ], $this->owner));
        self::assertSame(['username' => 'staff.error.username_taken'], $errors);
    }

    public function testOnlySuperAdminCanCreateSuperAdmin(): void
    {
        $input = ['name' => 'Dev', 'role' => 'super', 'username' => 'dev', 'password' => 'developer-pass'];
        self::assertSame('staff.error.role_not_allowed', $this->errorsOf(fn () => $this->service->create($input, $this->owner))['role']);
        self::assertGreaterThan(0, $this->service->create($input, self::SYSTEM));
    }

    public function testPromotingToOwnerNeedsLoginDetails(): void
    {
        $id = $this->service->create(['name' => 'Son', 'role' => 'staff', 'pin' => '2222'], $this->owner);

        $errors = $this->errorsOf(fn () => $this->service->update($id, ['name' => 'Son', 'role' => 'owner'], $this->owner));
        self::assertArrayHasKey('username', $errors);

        $this->service->update($id, ['name' => 'Son', 'role' => 'owner', 'username' => 'son', 'password' => 'son-password'], $this->owner);
        self::assertSame('owner', $this->service->find($id)['role']);
        self::assertSame('staff_update', $this->lastAudit()['action']);
    }

    public function testLastOwnerCannotBeDemotedOrDeactivated(): void
    {
        $other = $this->service->create(['name' => 'Ramesh', 'role' => 'staff', 'pin' => '1234'], self::SYSTEM);
        $otherOwner = ['id' => $other, 'role' => 'owner']; // acting as a second admin

        self::assertSame('staff.error.last_owner', $this->errorsOf(
            fn () => $this->service->update($this->owner['id'], ['name' => 'Owner', 'role' => 'staff'], $otherOwner)
        )['role']);
        self::assertSame('staff.error.last_owner', $this->errorsOf(
            fn () => $this->service->setActive($this->owner['id'], false, $otherOwner)
        )['_']);
    }

    public function testNobodyCanDeactivateThemselves(): void
    {
        $this->service->create(['name' => 'Son', 'role' => 'owner', 'username' => 'son', 'password' => 'son-password'], $this->owner);
        self::assertSame('staff.error.self', $this->errorsOf(
            fn () => $this->service->setActive($this->owner['id'], false, $this->owner)
        )['_']);
    }

    public function testDeactivateAndReactivate(): void
    {
        $id = $this->service->create(['name' => 'Ramesh', 'role' => 'staff', 'pin' => '1234'], $this->owner);
        $this->service->setActive($id, false, $this->owner);
        self::assertSame(0, (int) $this->service->find($id)['is_active']);
        self::assertSame('staff_deactivate', $this->lastAudit()['action']);

        $this->service->setActive($id, true, $this->owner);
        self::assertSame(1, (int) $this->service->find($id)['is_active']);
    }

    public function testResetPinAndUnlockClearTheLock(): void
    {
        $id = $this->service->create(['name' => 'Ramesh', 'role' => 'staff', 'pin' => '1234'], $this->owner);
        $lock = $this->db()->prepare("UPDATE staff SET failed_attempts = 3, locked_until = NOW() + INTERVAL 10 MINUTE WHERE id = ?");

        $lock->execute([$id]);
        $this->service->unlock($id, $this->owner);
        self::assertNull($this->service->find($id)['locked_until']);
        self::assertSame('staff_unlock', $this->lastAudit()['action']);

        $lock->execute([$id]);
        $this->service->setPin($id, '4321', $this->owner);
        $row = $this->db()->query("SELECT * FROM staff WHERE id = {$id}")->fetch();
        self::assertNull($row['locked_until']);
        self::assertTrue(password_verify('4321', $row['pin_hash']));
        self::assertStringNotContainsString('4321', json_encode($this->lastAudit()['details']));
    }

    public function testStaffCannotGetAPassword(): void
    {
        $id = $this->service->create(['name' => 'Ramesh', 'role' => 'staff', 'pin' => '1234'], $this->owner);
        self::assertSame('staff.error.password_not_for_staff', $this->errorsOf(
            fn () => $this->service->setPassword($id, 'long-enough', $this->owner)
        )['password']);
    }

    public function testOwnerCannotChangeASuperAdmin(): void
    {
        $dev = $this->service->create(['name' => 'Dev', 'role' => 'super', 'username' => 'dev', 'password' => 'developer-pass'], self::SYSTEM);
        self::assertSame('staff.error.role_not_allowed', $this->errorsOf(
            fn () => $this->service->setPin($dev, '1111', $this->owner)
        )['_']);
    }
}
