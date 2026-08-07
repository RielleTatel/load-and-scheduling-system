<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RbacRoutingTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_reaches_admin_dashboard_and_not_chair(): void
    {
        $admin = User::factory()->create(); // default role system_admin
        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk();
        $this->actingAs($admin)->get(route('chair.dashboard'))->assertForbidden();
    }

    public function test_chair_reaches_chair_dashboard_and_not_admin(): void
    {
        $chair = User::factory()->chair()->create();
        $this->actingAs($chair)->get(route('chair.dashboard'))->assertOk();
        $this->actingAs($chair)->get(route('admin.dashboard'))->assertForbidden();
    }

    public function test_dashboard_redirects_by_role(): void
    {
        $chair = User::factory()->chair()->create();
        $this->actingAs($chair)->get('/dashboard')->assertRedirect(route('chair.dashboard'));
    }

    public function test_inactive_user_is_blocked(): void
    {
        $chair = User::factory()->chair()->create(['is_active' => false]);
        $this->actingAs($chair)->get(route('chair.dashboard'))->assertForbidden();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
    }
}
