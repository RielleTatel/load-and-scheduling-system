<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssignmentRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_crud_role_lookup(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@jhs.test')->first();

        $this->actingAs($admin)->post(route('admin.roles.store'), [
            'name' => 'Test Coordinator', 'equivalent_hours' => 12, 'is_honorarium' => 0,
        ])->assertRedirect(route('admin.roles.index'));
        $this->assertDatabaseHas('other_assignment_roles', ['name' => 'Test Coordinator']);

        // Honorarium roles must not carry equivalent hours (SRS FR-7).
        $this->actingAs($admin)->post(route('admin.roles.store'), [
            'name' => 'Bad Club', 'equivalent_hours' => 5, 'is_honorarium' => 1,
        ])->assertSessionHasErrors('equivalent_hours');
    }

    public function test_index_lists_seeded_roles(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@jhs.test')->first();

        $this->actingAs($admin)->get(route('admin.roles.index'))
            ->assertOk()
            ->assertSee('Department Chair')
            ->assertSee('Sports Club Moderator');
    }
}
