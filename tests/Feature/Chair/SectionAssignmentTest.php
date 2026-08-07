<?php

namespace Tests\Feature\Chair;

use App\Models\Section;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SectionAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_chair_assigns_own_teacher_to_section(): void
    {
        $this->seed();
        $chair = User::where('email', 'chair.fil@jhs.test')->first();
        $teacher = Teacher::factory()->create(['department_id' => $chair->department_id]);
        $section = Section::where('grade_level', 'G7')->first();

        $this->actingAs($chair)->post(route('chair.assignments.store'), [
            'teacher_id' => $teacher->id, 'section_id' => $section->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('teacher_section_assignments', [
            'teacher_id' => $teacher->id, 'section_id' => $section->id,
            'department_id' => $chair->department_id,
        ]);
    }

    public function test_cannot_assign_teacher_from_another_department(): void
    {
        $this->seed();
        $chair = User::where('email', 'chair.fil@jhs.test')->first();
        $foreign = Teacher::factory()->create(); // different department
        $section = Section::where('grade_level', 'G7')->first();

        $this->actingAs($chair)->post(route('chair.assignments.store'), [
            'teacher_id' => $foreign->id, 'section_id' => $section->id,
        ])->assertSessionHasErrors('teacher_id');
    }
}
