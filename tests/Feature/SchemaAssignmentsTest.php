<?php

namespace Tests\Feature;

use App\Enums\SubmissionStatus;
use App\Models\Department;
use App\Models\PlantillaSubmission;
use App\Models\Section;
use App\Models\SystemConstant;
use App\Models\Teacher;
use App\Models\TeacherSectionAssignment;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchemaAssignmentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_assignment_uniqueness_and_constant_lookup(): void
    {
        $dept = Department::factory()->create(['hours_per_section' => 5]);
        $t = Teacher::factory()->create(['department_id' => $dept->id]);
        $s = Section::factory()->create();

        TeacherSectionAssignment::create([
            'teacher_id' => $t->id, 'section_id' => $s->id,
            'department_id' => $dept->id, 'school_year' => '2026-2027', 'hours' => 5,
        ]);

        $this->expectException(QueryException::class);
        TeacherSectionAssignment::create([
            'teacher_id' => Teacher::factory()->create(['department_id' => $dept->id])->id,
            'section_id' => $s->id, 'department_id' => $dept->id, 'school_year' => '2026-2027', 'hours' => 5,
        ]);
    }

    public function test_system_constant_get_set(): void
    {
        SystemConstant::set('full_load_hours', '21');
        $this->assertSame('21', SystemConstant::get('full_load_hours'));
        $this->assertSame('x', SystemConstant::get('missing', 'x'));
    }

    public function test_submission_current_for_creates_draft(): void
    {
        SystemConstant::set('current_school_year', '2026-2027');
        $dept = Department::factory()->create();

        $sub = PlantillaSubmission::currentFor($dept->id);
        $this->assertSame(SubmissionStatus::Draft, $sub->status);
        $this->assertSame($sub->id, PlantillaSubmission::currentFor($dept->id)->id);
    }
}
