<?php

namespace Tests\Feature\Admin;

use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_creates_chair(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@jhs.test')->first();
        $dept = Department::where('code', 'FIL')->first();

        $this->actingAs($admin)->post(route('admin.users.store'), [
            'name' => 'New Chair', 'email' => 'new@jhs.test', 'password' => 'secret1234',
            'role' => 'department_chair', 'department_id' => $dept->id,
        ])->assertRedirect(route('admin.users.index'));

        $this->assertDatabaseHas('users', ['email' => 'new@jhs.test', 'department_id' => $dept->id]);
    }

    public function test_validation_requires_department_for_chair(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@jhs.test')->first();

        $this->actingAs($admin)->post(route('admin.users.store'), [
            'name' => 'X', 'email' => 'x@jhs.test', 'password' => 'secret1234',
            'role' => 'department_chair',
        ])->assertSessionHasErrors('department_id');
    }

    public function test_admin_updates_user_without_changing_password(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@jhs.test')->first();
        $chair = User::where('email', 'chair.fil@jhs.test')->first();
        $originalHash = $chair->password;

        $this->actingAs($admin)->put(route('admin.users.update', $chair), [
            'name' => 'Renamed Chair', 'email' => $chair->email,
            'role' => 'department_chair', 'department_id' => $chair->department_id,
        ])->assertRedirect(route('admin.users.index'));

        $chair->refresh();
        $this->assertSame('Renamed Chair', $chair->name);
        $this->assertSame($originalHash, $chair->password);
    }

    public function test_toggle_deactivates(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@jhs.test')->first();
        $chair = User::where('email', 'chair.fil@jhs.test')->first();

        $this->actingAs($admin)->patch(route('admin.users.toggle', $chair));
        $this->assertFalse($chair->fresh()->is_active);
    }
}
