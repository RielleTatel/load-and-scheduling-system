<?php

namespace Tests\Feature\Chair;

use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeacherRosterTest extends TestCase
{
    use RefreshDatabase;

    public function test_roster_scoped_to_own_department(): void
    {
        $this->seed();
        $fil = User::where('email', 'chair.fil@jhs.test')->first();
        Teacher::factory()->create(['department_id' => $fil->department_id, 'full_name' => 'Mine Person']);
        $other = Teacher::factory()->create(['full_name' => 'Other Person']);

        $this->actingAs($fil)->get(route('chair.teachers.index'))
            ->assertOk()->assertSee('Mine Person')->assertDontSee('Other Person');

        $this->actingAs($fil)->get(route('chair.teachers.edit', $other))->assertNotFound();
    }

    public function test_create_teacher_lands_in_own_department(): void
    {
        $this->seed();
        $fil = User::where('email', 'chair.fil@jhs.test')->first();

        $this->actingAs($fil)->post(route('chair.teachers.store'), [
            'full_name' => 'New Teacher Name', 'employment_status' => 'permanent',
        ])->assertRedirect(route('chair.teachers.index'));

        $this->assertDatabaseHas('teachers', [
            'full_name' => 'New Teacher Name', 'department_id' => $fil->department_id,
        ]);
    }
}
