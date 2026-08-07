<?php

namespace App\Services\Auth;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;

class UserProvisioningService
{
    public function __construct(private AuditLogService $audit) {}

    /**
     * Create an account. Chairs must carry a department; every other role
     * is forced department-less. Passwords are hashed; the action is audited.
     *
     * @param  array{name:string,email:string,password:string,role:UserRole,department_id?:int|null}  $data
     */
    public function create(array $data): User
    {
        $user = User::create($this->normalize($data));
        $this->audit->log('user.created', $user, after: $this->snapshot($user));

        return $user;
    }

    /**
     * Update an account. Password is optional; role/department rules are re-applied.
     */
    public function update(User $user, array $data): User
    {
        $before = $this->snapshot($user);
        $user->update($this->normalize($data, requirePassword: false));
        $this->audit->log('user.updated', $user, $before, $this->snapshot($user->fresh()));

        return $user;
    }

    public function setActive(User $user, bool $active): User
    {
        $before = $this->snapshot($user);
        $user->update(['is_active' => $active]);
        $this->audit->log(
            $active ? 'user.reactivated' : 'user.deactivated',
            $user,
            $before,
            $this->snapshot($user->fresh()),
        );

        return $user;
    }

    /**
     * Apply role/department/password rules to a data array.
     */
    private function normalize(array $data, bool $requirePassword = true): array
    {
        $role = $data['role'] instanceof UserRole ? $data['role'] : UserRole::from($data['role']);

        $departmentId = null;
        if ($role === UserRole::DepartmentChair) {
            $departmentId = $data['department_id'] ?? null;
            if (! $departmentId) {
                throw new InvalidArgumentException('A Department Chair must be assigned a department.');
            }
        }

        $attributes = [
            'name' => $data['name'],
            'email' => $data['email'],
            'role' => $role,
            'department_id' => $departmentId,
        ];

        if ($requirePassword || ! empty($data['password'])) {
            $attributes['password'] = Hash::make($data['password']);
        }

        return $attributes;
    }

    private function snapshot(User $user): array
    {
        return [
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role->value,
            'department_id' => $user->department_id,
            'is_active' => $user->is_active,
        ];
    }
}
