<?php

namespace Tests\Unit;

use App\Models\ClassModeratorAssignment;
use App\Models\Department;
use App\Models\OtherAssignmentRole;
use App\Models\Section;
use App\Models\ServiceLoad;
use App\Models\Teacher;
use App\Models\TeacherOtherAssignment;
use App\Models\TeacherSectionAssignment;
use App\Services\Curriculum\LoadCalculationService;
use Database\Seeders\SystemConstantSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoadCalculationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_formula(): void
    {
        $this->seed(SystemConstantSeeder::class);
        $dept = Department::factory()->create(['hours_per_section' => 5]);
        $t = Teacher::factory()->create(['department_id' => $dept->id]);
        $sy = '2026-2027';

        foreach (Section::factory()->count(3)->create() as $s) {
            TeacherSectionAssignment::create(['teacher_id' => $t->id, 'section_id' => $s->id,
                'department_id' => $dept->id, 'school_year' => $sy, 'hours' => 5]);
        }
        ClassModeratorAssignment::create(['teacher_id' => $t->id,
            'section_id' => Section::factory()->create()->id, 'school_year' => $sy, 'hours' => 3]);
        ServiceLoad::create(['teacher_id' => $t->id, 'school_year' => $sy, 'hours' => 3]);

        $gll = OtherAssignmentRole::create(['name' => 'GLL-T', 'equivalent_hours' => 6, 'is_honorarium' => false]);
        $club = OtherAssignmentRole::create(['name' => 'Club-T', 'equivalent_hours' => null, 'is_honorarium' => true]);
        TeacherOtherAssignment::create(['teacher_id' => $t->id, 'other_assignment_role_id' => $gll->id, 'school_year' => $sy]);
        TeacherOtherAssignment::create(['teacher_id' => $t->id, 'other_assignment_role_id' => $club->id, 'school_year' => $sy]);

        $r = app(LoadCalculationService::class)->forTeacher($t, $sy);

        $this->assertEquals(18.0, $r['teaching_hours']);      // 15 + 3
        $this->assertEquals(9.0, $r['nonteaching_hours']);    // 3 + 6, club excluded
        $this->assertEquals(27.0, $r['total_hours']);
        $this->assertEquals(2.0, $r['overload_units']);       // (27-21)/3
        $this->assertSame(3, $r['section_count']);
        $this->assertContains('overloaded', $r['flags']);
    }

    public function test_zero_section_teacher_is_flagged_not_an_error(): void
    {
        $this->seed(SystemConstantSeeder::class);
        $t = Teacher::factory()->create();

        $r = app(LoadCalculationService::class)->forTeacher($t, '2026-2027');

        $this->assertEquals(0.0, $r['teaching_hours']);
        $this->assertContains('zero_sections', $r['flags']);
        $this->assertContains('no_service_load', $r['flags']);
        $this->assertEquals(0.0, $r['overload_units']);
    }
}
