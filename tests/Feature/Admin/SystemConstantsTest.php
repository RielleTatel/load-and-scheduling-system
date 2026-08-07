<?php

namespace Tests\Feature\Admin;

use App\Models\SystemConstant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SystemConstantsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_updates_constant_and_is_audited(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@jhs.test')->first();
        $const = SystemConstant::where('key', 'overload_divisor')->first();

        $this->actingAs($admin)->get(route('admin.constants.index'))
            ->assertOk()->assertSee('overload_divisor')->assertSee('UNCONFIRMED');

        $this->actingAs($admin)->patch(route('admin.constants.update', $const), ['value' => '4'])
            ->assertRedirect();

        $this->assertSame('4', SystemConstant::get('overload_divisor'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'constant.updated']);
    }

    public function test_chair_cannot_edit_constants(): void
    {
        $this->seed();
        $chair = User::where('email', 'chair.fil@jhs.test')->first();
        $this->actingAs($chair)->get(route('admin.constants.index'))->assertForbidden();
    }
}
