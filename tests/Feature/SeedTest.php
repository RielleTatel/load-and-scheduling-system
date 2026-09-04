<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\OtherAssignmentRole;
use App\Models\Section;
use App\Models\SystemConstant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeded_structure(): void
    {
        $this->seed();

        // English is the 8th department: no plantilla or section list yet
        // (JHS Scheduling Constraints §7.8), hours_per_section=5 inferred from
        // its 5x/cycle load matching Math/Science - unconfirmed like the rest
        // of that department's data until its plantilla arrives.
        $this->assertSame(8, Department::count());
        $this->assertSame(9, User::count()); // 1 admin + 8 chairs
        // 9 per grade, per the registrar's 2026 moderator list - none are English's yet.
        // Scoped: sections are per school year, so a bare count would drift once a
        // second year's roster is imported.
        $this->assertSame(36, Section::where('school_year', SystemConstant::get('current_school_year'))->count());

        $this->assertTrue(Department::where('code', 'SCI')->first()->has_honors_class);
        $this->assertSame(5, Department::where('code', 'MATH')->first()->hours_per_section);
        $this->assertSame(5, Department::where('code', 'ENG')->first()->hours_per_section);
        $this->assertSame('2026-2027', SystemConstant::get('current_school_year'));

        $this->assertEquals(15, OtherAssignmentRole::where('name', 'Department Chair')->first()->equivalent_hours);
        $this->assertTrue((bool) OtherAssignmentRole::where('name', 'Sports Club Moderator')->first()->is_honorarium);
    }

    public function test_each_grade_has_exactly_nine_sections(): void
    {
        $this->seed();

        foreach (['G7', 'G8', 'G9', 'G10'] as $grade) {
            $this->assertSame(9, Section::where('grade_level', $grade)->count(), "{$grade} should have 9 sections");
        }
    }

    public function test_section_names_are_unique_school_wide(): void
    {
        // The name->grade lookup that drives plantilla extraction is only valid
        // while no section name is reused across grades. If a future year breaks
        // this, extraction must fall back to Chair confirmation - fail loudly here.
        $this->seed();

        $names = Section::pluck('name');

        $this->assertSame($names->unique()->count(), $names->count(), 'section names must not repeat across grades');
    }

    public function test_exactly_three_magis_sections_one_per_upper_grade(): void
    {
        $this->seed();

        $magis = Section::where('is_magis', true)->get();

        $this->assertSame(3, $magis->count());
        $this->assertEqualsCanonicalizing(
            ['Ignatius of Loyola', 'Kostka', 'Faber'],
            $magis->pluck('name')->all(),
        );
    }

    public function test_seeding_prunes_sections_left_over_from_the_old_roster(): void
    {
        // A dev database seeded before 2026-09-02 holds 85 sections, 49 of them
        // the same section filed under the wrong grade. Re-seeding must remove
        // them, not just add the 36 correct ones alongside.
        $this->seed();
        Section::create(['grade_level' => 'G7', 'name' => 'Xavier']); // really G8

        $this->seed(\Database\Seeders\SectionSeeder::class);

        $this->assertSame(36, Section::count());
        $this->assertNull(Section::where('grade_level', 'G7')->where('name', 'Xavier')->first());
    }

    public function test_a_stale_section_still_in_use_is_kept_not_silently_deleted(): void
    {
        $this->seed();
        $stale = Section::create(['grade_level' => 'G7', 'name' => 'Xavier']);
        \App\Models\ClassModeratorAssignment::create([
            'teacher_id' => \App\Models\Teacher::factory()->create()->id,
            'section_id' => $stale->id,
            'school_year' => '2026-2027',
            'hours' => 3,
        ]);

        $this->seed(\Database\Seeders\SectionSeeder::class);

        $this->assertNotNull($stale->fresh(), 'a section with assignments must not be dropped');
    }

    public function test_every_section_carries_its_registrar_moderator(): void
    {
        // The plantillas mostly do not record the Class Moderator - CLE, Math,
        // TLE and Social Studies leave the column blank or unnamed. The
        // registrar's roster is the authority for this field.
        $this->seed();

        $this->assertSame(36, Section::whereNotNull('moderator_name')->count());
        $this->assertSame(36, Section::whereNotNull('teacher_partner_name')->count());
        $this->assertSame('Cristie R. Delos Reyes', Section::where('name', 'Rubio')->first()->moderator_name);
        $this->assertSame('Mhuammar A. Magasa', Section::where('name', 'Ignatius of Loyola')->first()->moderator_name);
    }

    public function test_registrar_named_staff_exist_before_their_plantilla_arrives(): void
    {
        // Moderators and teacher-partners are known from the registrar's list.
        // Twelve of them have no plantilla row yet (the English sheet is still
        // outstanding), so they must exist without a department.
        $this->seed();

        $singson = \App\Models\Teacher::where('full_name', 'Angelica M Singson')->first();
        $this->assertNotNull($singson, 'a registrar-named moderator should be seeded');
        $this->assertNull($singson->department_id);
        $this->assertSame('registrar', $singson->source);

        // Every section's moderator and partner is represented.
        foreach (Section::all() as $section) {
            foreach ([$section->moderator_name, $section->teacher_partner_name] as $name) {
                $this->assertNotNull(
                    \App\Models\Teacher::where('normalized_name', \App\Models\Teacher::normalize($name))->first(),
                    "{$name} should be in the teacher directory",
                );
            }
        }
    }

    public function test_demo_accounts_exist(): void
    {
        $this->seed();

        $admin = User::where('email', 'admin@jhs.test')->first();
        $this->assertNotNull($admin);
        $this->assertTrue($admin->isAdmin());

        $chair = User::where('email', 'chair.fil@jhs.test')->first();
        $this->assertNotNull($chair);
        $this->assertTrue($chair->isChair());
        $this->assertSame('FIL', $chair->department->code);
    }
}
