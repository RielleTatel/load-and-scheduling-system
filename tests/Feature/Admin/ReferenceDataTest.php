<?php

namespace Tests\Feature\Admin;

use App\Models\Section;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReferenceDataTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed();

        return User::where('email', 'admin@jhs.test')->firstOrFail();
    }

    public function test_admin_sees_the_section_roster(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.sections.index'))
            ->assertOk()
            ->assertSee('Ignatius of Loyola')
            ->assertSee('Cristie R. Delos Reyes');
    }

    public function test_admin_sees_the_teacher_directory(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.teachers.index'))
            ->assertOk()
            ->assertSee('Angelica M Singson');
    }

    public function test_a_chair_cannot_reach_registrar_reference_data(): void
    {
        $this->seed();
        $chair = User::where('email', 'chair.fil@jhs.test')->firstOrFail();

        $this->actingAs($chair)->get(route('admin.sections.index'))->assertForbidden();
        $this->actingAs($chair)->get(route('admin.teachers.index'))->assertForbidden();
    }

    public function test_admin_can_edit_a_section(): void
    {
        $admin = $this->admin();
        $section = Section::where('name', 'Xavier')->firstOrFail();

        $this->actingAs($admin)->put(route('admin.sections.update', $section), [
            'grade_level' => 'G8', 'name' => 'Xavier', 'full_name' => 'Saint Francis Xavier',
            'room' => '420', 'is_magis' => 0,
            'moderator_name' => 'Alex Julianne C. Bernabe', 'teacher_partner_name' => 'Anthony Dave M. Alibasa',
        ])->assertRedirect(route('admin.sections.index'));

        $this->assertSame('420', $section->fresh()->room);
    }

    public function test_a_section_name_may_not_be_reused_in_another_grade(): void
    {
        // Extraction recovers a section's grade from its name, so duplicates
        // would make that ambiguous.
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.sections.store'), [
            'grade_level' => 'G7', 'name' => 'Xavier', 'room' => '999', 'is_magis' => 0,
        ])->assertSessionHasErrors('name');

        $this->assertSame(36, Section::count());
    }

    public function test_a_teacher_may_not_be_added_twice_under_a_different_spelling(): void
    {
        $admin = $this->admin();
        $before = Teacher::count();

        $this->actingAs($admin)->post(route('admin.teachers.store'), [
            'full_name' => 'Bb. Angelica M. Singson',
        ])->assertSessionHasErrors('full_name');

        $this->assertSame($before, Teacher::count());
    }

    public function test_admin_can_assign_a_department_to_registrar_named_staff(): void
    {
        $admin = $this->admin();
        $teacher = Teacher::where('full_name', 'Angelica M Singson')->firstOrFail();
        $dept = \App\Models\Department::where('code', 'SCI')->firstOrFail();

        $this->actingAs($admin)->put(route('admin.teachers.update', $teacher), [
            'full_name' => 'Angelica M Singson', 'department_id' => $dept->id,
            'employment_status' => 'permanent',
        ])->assertRedirect(route('admin.teachers.index'));

        $this->assertSame($dept->id, $teacher->fresh()->department_id);
    }
}
