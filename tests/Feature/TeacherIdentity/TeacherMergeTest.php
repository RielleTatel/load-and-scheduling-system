<?php

namespace Tests\Feature\TeacherIdentity;

use App\Enums\IdentityCaseStatus;
use App\Enums\IdentityDecision;
use App\Models\ClassModeratorAssignment;
use App\Models\Department;
use App\Models\HonorsClassAssignment;
use App\Models\OtherAssignmentRole;
use App\Models\Section;
use App\Models\ServiceLoad;
use App\Models\Teacher;
use App\Models\TeacherIdentityCase;
use App\Models\TeacherOtherAssignment;
use App\Models\TeacherSectionAssignment;
use App\Models\TeacherUnavailability;
use App\Models\TimetableEntry;
use App\Models\TimetableVersion;
use App\Models\User;
use App\Services\Curriculum\LoadCalculationService;
use App\Services\Teachers\IdentityObservation;
use App\Services\Teachers\IdentityOutcome;
use App\Services\Teachers\TeacherIdentityService;
use App\Services\Teachers\TeacherMergeService;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\OtherAssignmentRoleSeeder;
use Database\Seeders\SectionSeeder;
use Database\Seeders\SystemConstantSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Merging a duplicate record through the Admin preview and confirmation.
 */
class TeacherMergeTest extends TestCase
{
    use RefreshDatabase;

    private Department $fil;

    private Teacher $keep;

    private Teacher $duplicate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([DepartmentSeeder::class, SectionSeeder::class, OtherAssignmentRoleSeeder::class, SystemConstantSeeder::class, UserSeeder::class]);
        $this->fil = Department::where('code', 'FIL')->firstOrFail();
        $this->keep = Teacher::create(['full_name' => 'Abigail Joyce L. Vendiola', 'department_id' => $this->fil->id, 'source' => 'plantilla']);
        $this->duplicate = Teacher::create(['full_name' => 'Abigail Joyce L. Vendola', 'department_id' => null, 'source' => 'registrar']);
    }

    private function admin(): User
    {
        return User::where('email', 'admin@jhs.test')->firstOrFail();
    }

    private function sections(): \Illuminate\Support\Collection
    {
        return Section::where('school_year', '2026-2027')->orderBy('id')->get();
    }

    /** Records in every assignment family, two school years, and the scheduling tables. */
    private function populate(): array
    {
        [$a, $b, $c, $d] = $this->sections()->take(4)->all();
        $nonHonorarium = OtherAssignmentRole::where('is_honorarium', false)->orderBy('id')->get();

        foreach ([[$this->keep, $a, '2026-2027'], [$this->duplicate, $b, '2026-2027'], [$this->duplicate, $a, '2025-2026']] as [$teacher, $section, $year]) {
            TeacherSectionAssignment::create(['teacher_id' => $teacher->id, 'section_id' => $section->id, 'department_id' => $this->fil->id, 'school_year' => $year, 'hours' => 4]);
        }
        ClassModeratorAssignment::create(['teacher_id' => $this->duplicate->id, 'section_id' => $c->id, 'school_year' => '2026-2027', 'hours' => 4]);
        HonorsClassAssignment::create(['teacher_id' => $this->duplicate->id, 'section_id' => $d->id, 'school_year' => '2026-2027', 'hours' => 8]);

        ServiceLoad::create(['teacher_id' => $this->keep->id, 'school_year' => '2026-2027', 'hours' => 3]);
        ServiceLoad::create(['teacher_id' => $this->duplicate->id, 'school_year' => '2026-2027', 'hours' => 4]);
        ServiceLoad::create(['teacher_id' => $this->keep->id, 'school_year' => '2025-2026', 'hours' => 3]);
        ServiceLoad::create(['teacher_id' => $this->duplicate->id, 'school_year' => '2025-2026', 'hours' => 3]);

        // The same role on both records must count once; a different role moves.
        foreach ([$this->keep, $this->duplicate] as $teacher) {
            TeacherOtherAssignment::create(['teacher_id' => $teacher->id, 'other_assignment_role_id' => $nonHonorarium[0]->id, 'school_year' => '2026-2027']);
        }
        TeacherOtherAssignment::create(['teacher_id' => $this->duplicate->id, 'other_assignment_role_id' => $nonHonorarium[1]->id, 'school_year' => '2026-2027']);

        $version = TimetableVersion::create(['school_year' => '2026-2027', 'quarter' => 1, 'version' => 1, 'status' => 'draft', 'created_by_user_id' => $this->admin()->id, 'generation_seed' => 1]);
        $version->entries()->create(['section_id' => $a->id, 'department_id' => $this->fil->id, 'teacher_id' => $this->keep->id, 'day' => 1, 'period' => 1]);
        $version->entries()->create(['section_id' => $b->id, 'department_id' => $this->fil->id, 'teacher_id' => $this->duplicate->id, 'day' => 1, 'period' => 2]);
        foreach ([$this->keep, $this->duplicate] as $teacher) {
            TeacherUnavailability::create(['teacher_id' => $teacher->id, 'school_year' => '2026-2027', 'quarter' => 1, 'day' => 2, 'period' => 3]);
        }
        TeacherUnavailability::create(['teacher_id' => $this->duplicate->id, 'school_year' => '2026-2027', 'quarter' => 1, 'day' => 3, 'period' => 3]);

        return compact('version', 'nonHonorarium');
    }

    private function token(): string
    {
        return app(TeacherMergeService::class)->preview($this->duplicate->fresh(), $this->keep->fresh())['token'];
    }

    private function merge(array $overrides = [])
    {
        return $this->actingAs($this->admin())->post(route('admin.teachers.merge', $this->duplicate), $overrides + [
            'survivor_id' => $this->keep->id,
            'token' => $this->token(),
            'service' => ['2026-2027' => 'redundant'],
            'note' => 'Registrar misspelling of the same Filipino teacher.',
        ]);
    }

    public function test_the_preview_lists_what_moves_and_what_needs_a_choice(): void
    {
        $this->populate();

        $this->actingAs($this->admin())->get(route('admin.teachers.merge.preview', ['teacher' => $this->duplicate, 'survivor' => $this->keep->id]))
            ->assertOk()
            ->assertSee('Section loads moving across')
            ->assertSee('2026-2027 service load differs')
            ->assertSee('Timetable entries moving across');
    }

    public function test_a_merge_moves_every_reference_without_double_counting(): void
    {
        $this->populate();
        $expected = app(LoadCalculationService::class)->forTeacher($this->keep, '2026-2027');

        // Different service values cannot be merged without a choice.
        $this->merge(['service' => []])->assertSessionHasErrors('service');
        $this->assertNull($this->duplicate->fresh()->merged_into_teacher_id);

        $this->merge()->assertSessionHasNoErrors()->assertRedirect(route('admin.teachers.edit', $this->keep));

        $this->assertSame(0, TeacherSectionAssignment::where('teacher_id', $this->duplicate->id)->count());
        $this->assertSame(3, TeacherSectionAssignment::where('teacher_id', $this->keep->id)->count(), 'loads from both years move');
        $this->assertSame(1, ClassModeratorAssignment::where('teacher_id', $this->keep->id)->count());
        $this->assertSame(1, HonorsClassAssignment::where('teacher_id', $this->keep->id)->count());
        $this->assertEquals(4.0, ServiceLoad::where('teacher_id', $this->keep->id)->where('school_year', '2026-2027')->value('hours'), 'the chosen value, not a sum');
        $this->assertSame(2, ServiceLoad::where('teacher_id', $this->keep->id)->count(), 'identical years collapse');
        $this->assertSame(2, TeacherOtherAssignment::where('teacher_id', $this->keep->id)->count(), 'a shared role counts once');
        $this->assertSame(2, TimetableEntry::where('teacher_id', $this->keep->id)->count());
        $this->assertSame(2, TeacherUnavailability::where('teacher_id', $this->keep->id)->count(), 'identical slots collapse');
        $this->assertSame(0, ServiceLoad::where('teacher_id', $this->duplicate->id)->count());

        // The calculated workload is the survivor's plus what only the duplicate held.
        $after = app(LoadCalculationService::class)->forTeacher($this->keep->fresh(), '2026-2027');
        $this->assertSame(2, $after['section_count']);
        $this->assertEqualsWithDelta($expected['teaching_hours'] + 4 + 4 + 8, $after['teaching_hours'], 0.001);

        $duplicate = $this->duplicate->fresh();
        $this->assertSame($this->keep->id, $duplicate->merged_into_teacher_id);
        $this->assertSame('Abigail Joyce L. Vendola', $duplicate->full_name, 'the redundant record is kept as history');
        $this->assertTrue($this->keep->aliases()->where('alias_name', 'Abigail Joyce L. Vendola')->exists());

        $this->actingAs($this->admin())->get(route('admin.teachers.index'))
            ->assertViewHas('teachers', fn ($teachers) => ! $teachers->contains('id', $this->duplicate->id));
    }

    public function test_a_repeated_confirmation_returns_the_recorded_outcome(): void
    {
        $this->populate();
        $token = $this->token();
        $request = ['survivor_id' => $this->keep->id, 'token' => $token, 'service' => ['2026-2027' => 'survivor'], 'note' => 'Same person.'];

        $this->actingAs($this->admin())->post(route('admin.teachers.merge', $this->duplicate), $request)->assertSessionHasNoErrors();
        $this->actingAs($this->admin())->post(route('admin.teachers.merge', $this->duplicate), $request)->assertSessionHasNoErrors();

        $this->assertSame(3, TeacherSectionAssignment::where('teacher_id', $this->keep->id)->count());
        $this->assertEquals(3.0, ServiceLoad::where('teacher_id', $this->keep->id)->where('school_year', '2026-2027')->value('hours'));
    }

    public function test_a_stale_preview_changes_nothing(): void
    {
        $this->populate();
        $token = $this->token();
        TeacherSectionAssignment::create(['teacher_id' => $this->duplicate->id, 'section_id' => $this->sections()[5]->id, 'department_id' => $this->fil->id, 'school_year' => '2026-2027', 'hours' => 4]);

        $this->merge(['token' => $token])->assertSessionHasErrors('token');

        $this->assertNull($this->duplicate->fresh()->merged_into_teacher_id);
        $this->assertSame(3, TeacherSectionAssignment::where('teacher_id', $this->duplicate->id)->count());
    }

    public function test_departments_must_be_settled_before_a_merge(): void
    {
        $this->duplicate->update(['department_id' => Department::where('code', 'CLE')->value('id')]);

        $this->merge()->assertSessionHasErrors('token');
        $this->assertNull($this->duplicate->fresh()->merged_into_teacher_id);
    }

    public function test_a_double_booked_pair_cannot_be_merged(): void
    {
        ['version' => $version] = $this->populate();
        $version->entries()->create(['section_id' => $this->sections()[6]->id, 'department_id' => $this->fil->id, 'teacher_id' => $this->duplicate->id, 'day' => 1, 'period' => 1]);

        $this->merge()->assertSessionHasErrors('token');
        $this->assertNull($this->duplicate->fresh()->merged_into_teacher_id);
    }

    public function test_a_merged_record_takes_no_new_loads_and_is_never_matched_again(): void
    {
        $this->duplicate->update(['department_id' => $this->fil->id]);
        $this->merge(['service' => []])->assertSessionHasNoErrors();

        $chair = User::where('email', 'chair.fil@jhs.test')->firstOrFail();
        $this->actingAs($chair)->post(route('chair.assignments.store'), [
            'teacher_id' => $this->duplicate->id, 'section_id' => $this->sections()[0]->id,
        ])->assertSessionHasErrors('teacher_id');

        // The duplicate's spelling now names the survivor.
        $outcome = app(TeacherIdentityService::class)->inspect(
            new IdentityObservation(IdentityObservation::PLANTILLA, 'plantilla_row:99', 'Abigail Joyce L. Vendola', $this->fil->id),
        );
        $this->assertSame(IdentityOutcome::EXISTING, $outcome->kind);
        $this->assertSame($this->keep->id, $outcome->teacher->id);
    }

    public function test_merging_closes_the_look_alike_case_that_asked_about_the_pair(): void
    {
        $identities = app(TeacherIdentityService::class);
        $case = $identities->openCase(
            new IdentityObservation(IdentityObservation::MANUAL, "manual:teacher:{$this->duplicate->id}", $this->duplicate->full_name, null),
            IdentityOutcome::review(\App\Enums\IdentityCaseReason::PossibleMatch, [$this->keep->id]),
            $this->duplicate,
        );

        $this->merge(['service' => []])->assertSessionHasNoErrors();

        $case->refresh();
        $this->assertSame(IdentityCaseStatus::Resolved, $case->status);
        $this->assertSame(IdentityDecision::Same, $case->decision);
        $this->assertSame($this->keep->id, $case->resolution_teacher_id);
        $this->assertSame(0, TeacherIdentityCase::open()->count());
    }
}
