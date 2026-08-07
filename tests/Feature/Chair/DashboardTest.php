<?php

namespace Tests\Feature\Chair;

use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_shows_submission_status_and_flags(): void
    {
        $this->seed();
        $chair = User::where('email', 'chair.fil@jhs.test')->first();
        Teacher::factory()->create([
            'department_id' => $chair->department_id, 'full_name' => 'Zero Section Teacher',
        ]);

        $this->actingAs($chair)->get(route('chair.dashboard'))
            ->assertOk()
            ->assertSee('Draft')
            ->assertSee('Zero Section Teacher')
            ->assertSee('No sections');
    }
}
