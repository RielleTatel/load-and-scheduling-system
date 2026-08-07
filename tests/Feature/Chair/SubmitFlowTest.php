<?php

namespace Tests\Feature\Chair;

use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubmitFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_submit_locks_all_editing(): void
    {
        $this->seed();
        $chair = User::where('email', 'chair.fil@jhs.test')->first();
        $teacher = Teacher::factory()->create(['department_id' => $chair->department_id]);

        $this->actingAs($chair)->post(route('chair.submission.store'))->assertRedirect();

        // Editing endpoints now refuse the write.
        $this->actingAs($chair)->post(route('chair.teachers.store'), [
            'full_name' => 'Late Addition', 'employment_status' => 'permanent',
        ])->assertForbidden();

        $this->actingAs($chair)->put(route('chair.teachers.update', $teacher), [
            'full_name' => 'Renamed', 'employment_status' => 'permanent',
        ])->assertForbidden();
    }
}
