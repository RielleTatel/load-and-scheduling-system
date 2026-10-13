<?php

namespace Tests\Feature\TeacherIdentity;

use App\Enums\TeacherVerification;
use App\Models\Teacher;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The identity migration runs against a populated directory: it must keep
 * every ID and assignment, mark everyone pending without inventing a
 * reviewer, and never merge look-alike records on its own.
 */
class TeacherIdentityMigrationTest extends TestCase
{
    // Not RefreshDatabase: SQLite cannot rebuild a table inside its wrapping transaction.
    use DatabaseMigrations;

    public function test_legacy_teachers_survive_as_pending_without_fabricated_evidence(): void
    {
        $this->seed(\Database\Seeders\DepartmentSeeder::class);
        $this->seed(\Database\Seeders\SectionSeeder::class);
        $this->artisan('migrate:rollback', ['--path' => 'database/migrations/2026_10_13_000001_add_teacher_identity_reconciliation.php'])->assertSuccessful();

        $fil = DB::table('departments')->where('code', 'FIL')->value('id');
        $now = now();
        $plantilla = DB::table('teachers')->insertGetId(['full_name' => 'Abigail Joyce L. Vendola', 'normalized_name' => 'abigail joyce vendola', 'department_id' => $fil, 'source' => 'plantilla', 'created_at' => $now, 'updated_at' => $now]);
        $registrar = DB::table('teachers')->insertGetId(['full_name' => 'Abigail Joyce L. Vendiola', 'normalized_name' => 'abigail joyce vendiola', 'department_id' => null, 'source' => 'registrar', 'created_at' => $now, 'updated_at' => $now]);
        $section = DB::table('sections')->value('id');
        DB::table('teacher_section_assignments')->insert(['teacher_id' => $plantilla, 'section_id' => $section, 'department_id' => $fil, 'school_year' => '2026-2027', 'hours' => 4, 'created_at' => $now, 'updated_at' => $now]);

        $this->artisan('migrate')->assertSuccessful();

        $this->assertSame(2, Teacher::count(), 'look-alike legacy records are never merged by the migration');
        foreach ([$plantilla, $registrar] as $id) {
            $teacher = Teacher::findOrFail($id);
            $this->assertSame(TeacherVerification::Pending, $teacher->verification_status);
            $this->assertNull($teacher->verified_at);
            $this->assertNull($teacher->verified_by_user_id);
            $this->assertNull($teacher->merged_into_teacher_id);
        }
        $this->assertSame('registrar', Teacher::find($registrar)->source, 'legacy provenance is kept');
        $this->assertSame(1, Teacher::find($plantilla)->sectionAssignments()->count());
    }
}
