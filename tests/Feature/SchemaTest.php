<?php

namespace Tests\Feature;

use App\Enums\GradeLevel;
use App\Enums\UserRole;
use App\Models\Department;
use App\Models\Section;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_core_models_create_and_relate(): void
    {
        $dept = Department::factory()->create(['code' => 'FIL', 'hours_per_section' => 4]);
        $teacher = Teacher::factory()->create(['department_id' => $dept->id]);
        $section = Section::factory()->create(['grade_level' => GradeLevel::G7, 'name' => 'Ignatius']);
        $chair = User::factory()->chair($dept)->create();

        $this->assertTrue($teacher->department->is($dept));
        $this->assertSame('G7', $section->grade_level->value);
        $this->assertSame(UserRole::DepartmentChair, $chair->role);
        $this->assertSame($dept->id, $chair->department_id);
        $this->assertTrue($chair->is_active);
        $this->assertTrue($chair->isChair());
        $this->assertFalse($chair->isAdmin());
    }

    public function test_section_grade_name_unique(): void
    {
        Section::factory()->create(['grade_level' => GradeLevel::G7, 'name' => 'Xavier']);
        $this->expectException(QueryException::class);
        Section::factory()->create(['grade_level' => GradeLevel::G7, 'name' => 'Xavier']);
    }
}
