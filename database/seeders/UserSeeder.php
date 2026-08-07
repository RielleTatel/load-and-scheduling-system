<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // Demo password for every seeded account.
        $password = Hash::make('password');

        User::updateOrCreate(
            ['email' => 'admin@jhs.test'],
            [
                'name' => 'System Administrator',
                'password' => $password,
                'role' => UserRole::SystemAdmin,
                'department_id' => null,
                'is_active' => true,
                'email_verified_at' => now(),
            ],
        );

        // One chair per department: chair.<lowercase code>@jhs.test
        foreach (Department::all() as $department) {
            User::updateOrCreate(
                ['email' => 'chair.' . strtolower($department->code) . '@jhs.test'],
                [
                    'name' => $department->name . ' Chair',
                    'password' => $password,
                    'role' => UserRole::DepartmentChair,
                    'department_id' => $department->id,
                    'is_active' => true,
                    'email_verified_at' => now(),
                ],
            );
        }
    }
}
