<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserDirectoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_directory_lists_and_filters(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@jhs.test')->first();

        $this->actingAs($admin)->get(route('admin.users.index'))
            ->assertOk()->assertSee('chair.fil@jhs.test');

        $this->actingAs($admin)->get(route('admin.users.index', ['q' => 'chair.math']))
            ->assertSee('chair.math@jhs.test')->assertDontSee('chair.fil@jhs.test');

        $this->actingAs($admin)->get(route('admin.users.index', ['role' => 'system_admin']))
            ->assertSee('admin@jhs.test')->assertDontSee('chair.fil@jhs.test');
    }

    public function test_chair_cannot_open_directory(): void
    {
        $this->seed();
        $chair = User::where('email', 'chair.fil@jhs.test')->first();
        $this->actingAs($chair)->get(route('admin.users.index'))->assertForbidden();
    }
}
