<?php

namespace Tests\Unit;

use App\Enums\UserRole;
use App\Models\Department;
use App\Models\User;
use App\Services\Auth\UserProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class UserProvisioningServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_chair_requires_department(): void
    {
        $this->actingAs(User::factory()->create());
        $svc = app(UserProvisioningService::class);
        $dept = Department::factory()->create();

        $chair = $svc->create([
            'name' => 'C', 'email' => 'c@x.test', 'password' => 'secret123',
            'role' => UserRole::DepartmentChair, 'department_id' => $dept->id,
        ]);
        $this->assertSame($dept->id, $chair->department_id);

        $this->expectException(InvalidArgumentException::class);
        $svc->create([
            'name' => 'D', 'email' => 'd@x.test', 'password' => 'secret123',
            'role' => UserRole::DepartmentChair,
        ]);
    }

    public function test_admin_department_is_nulled_and_actions_audited(): void
    {
        $this->actingAs(User::factory()->create());
        $svc = app(UserProvisioningService::class);

        $u = $svc->create([
            'name' => 'A', 'email' => 'a@x.test', 'password' => 'secret123',
            'role' => UserRole::SystemAdmin, 'department_id' => Department::factory()->create()->id,
        ]);
        $this->assertNull($u->department_id);

        $svc->setActive($u, false);
        $this->assertFalse($u->fresh()->is_active);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.deactivated', 'auditable_id' => $u->id]);
    }

    public function test_password_is_hashed(): void
    {
        $this->actingAs(User::factory()->create());
        $u = app(UserProvisioningService::class)->create([
            'name' => 'P', 'email' => 'p@x.test', 'password' => 'plaintext123',
            'role' => UserRole::AcademicCoordinator,
        ]);

        $this->assertNotSame('plaintext123', $u->password);
        $this->assertTrue(password_verify('plaintext123', $u->password));
    }
}
