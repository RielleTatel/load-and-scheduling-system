<?php

namespace Tests\Unit;

use App\Models\Department;
use App\Models\Section;
use App\Models\Teacher;
use App\Services\Curriculum\SectionAssignmentService;
use Database\Seeders\SystemConstantSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SectionAssignmentServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_assign_uses_department_rate_and_uniqueness(): void
    {
        $this->seed(SystemConstantSeeder::class);
        $dept = Department::factory()->create(['hours_per_section' => 5]);
        $t1 = Teacher::factory()->create(['department_id' => $dept->id]);
        $t2 = Teacher::factory()->create(['department_id' => $dept->id]);
        $s = Section::factory()->create();
        $svc = app(SectionAssignmentService::class);

        $a = $svc->assign($t1, $s);
        $this->assertEquals(5.0, (float) $a->hours);

        $this->expectException(DomainException::class);
        $svc->assign($t2, $s);
    }

    public function test_honors_requires_department_flag(): void
    {
        $this->seed(SystemConstantSeeder::class);
        $dept = Department::factory()->create(['has_honors_class' => false]);
        $t = Teacher::factory()->create(['department_id' => $dept->id]);

        $this->expectException(DomainException::class);
        app(SectionAssignmentService::class)->assignHonors($t, Section::factory()->create());
    }

    public function test_one_moderator_per_section_across_departments(): void
    {
        $this->seed(SystemConstantSeeder::class);
        $s = Section::factory()->create();
        $svc = app(SectionAssignmentService::class);

        $svc->assignModerator(Teacher::factory()->create(), $s);

        $this->expectException(DomainException::class);
        $svc->assignModerator(Teacher::factory()->create(), $s);
    }
}
