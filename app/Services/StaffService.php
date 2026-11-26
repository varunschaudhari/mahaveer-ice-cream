<?php

declare(strict_types=1);

namespace App\Services;

use App\Db;
use App\ValidationException;
use PDO;
use PDOException;

/**
 * Staff accounts. Every method takes the acting user ($actor: ['id' => ?int, 'role' => string]);
 * the CLI setup script acts as ['id' => null, 'role' => 'super'].
 *
 * Rules: only a super-admin can create or change super-admin accounts; owner and super-admin
 * accounts need a username + password; the last active owner cannot be removed or demoted;
 * nobody can deactivate their own account. PINs and passwords are never written to the audit log.
 */
final class StaffService
{
    public const ROLES = ['staff', 'owner', 'super'];
    public const MIN_PASSWORD = 8;
    private const USERNAME_PATTERN = '/^[a-z0-9._-]{3,50}$/';

    private readonly PDO $db;
    private readonly AuditService $audit;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Db::pdo();
        $this->audit = new AuditService($this->db);
    }

    public function all(): array
    {
        return $this->db->query(
            "SELECT id, name, role, username, is_active, failed_attempts, locked_until, created_at,
                    pin_hash IS NOT NULL AS has_pin, password_hash IS NOT NULL AS has_password
             FROM staff
             ORDER BY is_active DESC, FIELD(role, 'owner', 'super', 'staff'), name"
        )->fetchAll();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, name, role, username, is_active, failed_attempts, locked_until,
                    pin_hash IS NOT NULL AS has_pin, password_hash IS NOT NULL AS has_password
             FROM staff WHERE id = ?'
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /** @param array{name?: string, role?: string, pin?: string, username?: string, password?: string} $input */
    public function create(array $input, array $actor): int
    {
        $name = trim((string) ($input['name'] ?? ''));
        $role = (string) ($input['role'] ?? 'staff');
        $pin = trim((string) ($input['pin'] ?? ''));
        $username = mb_strtolower(trim((string) ($input['username'] ?? '')));
        $password = (string) ($input['password'] ?? '');

        $errors = [];
        if (!is_valid_name($name, 100)) {
            $errors['name'] = 'staff.error.name';
        }
        if (!in_array($role, self::ROLES, true)) {
            $errors['role'] = 'staff.error.role';
        } elseif (!$this->canManageRole($actor, $role)) {
            $errors['role'] = 'staff.error.role_not_allowed';
        }
        // Staff need a PIN to log in at the counter; for owner/super it is optional (approvals).
        if (($role === 'staff' || $pin !== '') && !is_valid_pin($pin)) {
            $errors['pin'] = 'staff.error.pin';
        }
        if ($role !== 'staff') {
            $errors += $this->loginErrors($username, $password);
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $isAdmin = $role !== 'staff';
        try {
            $this->db->prepare(
                'INSERT INTO staff (name, role, username, password_hash, pin_hash) VALUES (?, ?, ?, ?, ?)'
            )->execute([
                $name,
                $role,
                $isAdmin ? $username : null,
                $isAdmin ? password_hash($password, PASSWORD_DEFAULT) : null,
                $pin !== '' ? password_hash($pin, PASSWORD_DEFAULT) : null,
            ]);
        } catch (PDOException $e) {
            throw $this->isDuplicate($e) ? ValidationException::one('username', 'staff.error.username_taken') : $e;
        }

        $id = (int) $this->db->lastInsertId();
        $this->audit->log($actor['id'], 'staff_create', 'staff', $id, [
            'after' => ['name' => $name, 'role' => $role, 'username' => $isAdmin ? $username : null],
        ]);
        return $id;
    }

    /**
     * Name and role. Promoting to owner/super needs a username + password if the account has none.
     * @param array{name?: string, role?: string, username?: string, password?: string} $input
     */
    public function update(int $id, array $input, array $actor): void
    {
        $staff = $this->findOrFail($id);
        $name = trim((string) ($input['name'] ?? $staff['name']));
        $role = (string) ($input['role'] ?? $staff['role']);
        $username = mb_strtolower(trim((string) ($input['username'] ?? '')));
        $password = (string) ($input['password'] ?? '');

        $errors = [];
        if (!is_valid_name($name, 100)) {
            $errors['name'] = 'staff.error.name';
        }
        if (!in_array($role, self::ROLES, true)) {
            $errors['role'] = 'staff.error.role';
        } elseif (!$this->canManageRole($actor, $staff['role']) || !$this->canManageRole($actor, $role)) {
            $errors['role'] = 'staff.error.role_not_allowed';
        } elseif ($staff['role'] === 'owner' && $role !== 'owner' && $staff['is_active'] && $this->activeOwnerCount() <= 1) {
            $errors['role'] = 'staff.error.last_owner';
        }
        $needsLogin = $role !== 'staff' && !$staff['has_password'];
        if ($needsLogin) {
            $errors += $this->loginErrors($username, $password);
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        try {
            if ($needsLogin) {
                $this->db->prepare('UPDATE staff SET name = ?, role = ?, username = ?, password_hash = ? WHERE id = ?')
                    ->execute([$name, $role, $username, password_hash($password, PASSWORD_DEFAULT), $id]);
            } else {
                $this->db->prepare('UPDATE staff SET name = ?, role = ? WHERE id = ?')->execute([$name, $role, $id]);
            }
        } catch (PDOException $e) {
            throw $this->isDuplicate($e) ? ValidationException::one('username', 'staff.error.username_taken') : $e;
        }

        $before = ['name' => $staff['name'], 'role' => $staff['role']];
        $after = ['name' => $name, 'role' => $role];
        if ($before !== $after || $needsLogin) {
            $this->audit->log($actor['id'], 'staff_update', 'staff', $id, [
                'before' => $before,
                'after' => $after + ($needsLogin ? ['username' => $username] : []),
            ]);
        }
    }

    public function setPin(int $id, string $pin, array $actor): void
    {
        $staff = $this->findOrFail($id);
        $this->assertCanManage($actor, $staff);
        if (!is_valid_pin(trim($pin))) {
            throw ValidationException::one('pin', 'staff.error.pin');
        }
        $this->db->prepare('UPDATE staff SET pin_hash = ?, failed_attempts = 0, locked_until = NULL WHERE id = ?')
            ->execute([password_hash(trim($pin), PASSWORD_DEFAULT), $id]);
        $this->audit->log($actor['id'], 'staff_pin_reset', 'staff', $id, ['name' => $staff['name']]);
    }

    public function setPassword(int $id, string $password, array $actor): void
    {
        $staff = $this->findOrFail($id);
        $this->assertCanManage($actor, $staff);
        if ($staff['role'] === 'staff') {
            throw ValidationException::one('password', 'staff.error.password_not_for_staff');
        }
        if (!$this->isValidPassword($password)) {
            throw ValidationException::one('password', 'staff.error.password');
        }
        $this->db->prepare('UPDATE staff SET password_hash = ?, failed_attempts = 0, locked_until = NULL WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
        $this->audit->log($actor['id'], 'staff_password_change', 'staff', $id, ['name' => $staff['name']]);
    }

    public function setActive(int $id, bool $active, array $actor): void
    {
        $staff = $this->findOrFail($id);
        $this->assertCanManage($actor, $staff);
        if ((bool) $staff['is_active'] === $active) {
            return;
        }
        if (!$active && $actor['id'] === $id) {
            throw ValidationException::one('_', 'staff.error.self');
        }
        if (!$active && $staff['role'] === 'owner' && $this->activeOwnerCount() <= 1) {
            throw ValidationException::one('_', 'staff.error.last_owner');
        }
        $this->db->prepare('UPDATE staff SET is_active = ? WHERE id = ?')->execute([$active ? 1 : 0, $id]);
        $this->audit->log($actor['id'], $active ? 'staff_activate' : 'staff_deactivate', 'staff', $id, ['name' => $staff['name']]);
    }

    public function unlock(int $id, array $actor): void
    {
        $staff = $this->findOrFail($id);
        $this->assertCanManage($actor, $staff);
        $this->db->prepare('UPDATE staff SET failed_attempts = 0, locked_until = NULL WHERE id = ?')->execute([$id]);
        $this->audit->log($actor['id'], 'staff_unlock', 'staff', $id, ['name' => $staff['name']]);
    }

    public function activeOwnerCount(): int
    {
        return (int) $this->db->query("SELECT COUNT(*) FROM staff WHERE role = 'owner' AND is_active = 1")->fetchColumn();
    }

    public function canManageRole(array $actor, string $role): bool
    {
        if ($role === 'super') {
            return $actor['role'] === 'super';
        }
        return in_array($actor['role'], ['owner', 'super'], true);
    }

    private function assertCanManage(array $actor, array $staff): void
    {
        if (!$this->canManageRole($actor, $staff['role'])) {
            throw ValidationException::one('_', 'staff.error.role_not_allowed');
        }
    }

    private function findOrFail(int $id): array
    {
        return $this->find($id) ?? throw ValidationException::one('_', 'staff.error.not_found');
    }

    private function loginErrors(string $username, string $password): array
    {
        $errors = [];
        if (!preg_match(self::USERNAME_PATTERN, $username)) {
            $errors['username'] = 'staff.error.username';
        }
        if (!$this->isValidPassword($password)) {
            $errors['password'] = 'staff.error.password';
        }
        return $errors;
    }

    private function isValidPassword(string $password): bool
    {
        // bcrypt ignores everything after 72 bytes, so longer passwords are refused.
        return mb_strlen($password) >= self::MIN_PASSWORD && strlen($password) <= 72;
    }

    private function isDuplicate(PDOException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062;
    }
}
