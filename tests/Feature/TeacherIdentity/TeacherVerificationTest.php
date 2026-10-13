<?php

namespace Tests\Feature\TeacherIdentity;

use App\Enums\ExtractionRowStatus;
use App\Enums\IdentityCaseReason;
use App\Enums\IdentityCaseStatus;
use App\Enums\TeacherVerification;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\PlantillaSubmission;
use App\Models\PlantillaUpload;
use App\Models\Section;
use App\Models\Teacher;
use App\Models\TeacherIdentityCase;
use App\Models\TeacherSectionAssignment;
use App\Models\User;
use App\Services\Teachers\TeacherReconciliationService;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\OtherAssignmentRoleSeeder;
use Database\Seeders\SectionSeeder;
use Database\Seeders\SystemConstantSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Admin verification and reconciliation without an official directory, the
 * Chair's part in it, and the role boundaries around both.
 */
class TeacherVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([DepartmentSeeder::class, SectionSeeder::class, OtherAssignmentRoleSeeder::class, SystemConstantSeeder::class, UserSeeder::class]);
    }

    private function admin(): User
    {
        return User::where('email', 'admin@jhs.test')->firstOrFail();
    }

    private function chair(string $code = 'fil'): User
    {
        return User::where('email', "chair.{$code}@jhs.test")->firstOrFail();
    }

    /** Import one plantilla row for the chair's department and return its teacher. */
    private function importTeacher(User $chair, string $name, ?string $sections = null): Teacher
    {
        $upload = PlantillaUpload::create([
            'plantilla_submission_id' => PlantillaSubmission::currentFor($chair->department_id)->id,
            'file_path' => 'plantillas/test.pdf',
            'original_filename' => 'test.pdf',
            'extraction_status' => 'extracted',
        ]);
        $upload->rows()->create([
            'row_json' => [
                'teacher_name' => $name, 'employment_status' => 'Permanent', 'sections' => $sections,
                'cm' => null, 'hc' => null, 'service_load' => null, 'other_assignment' => null, 'flagged' => false,
            ],
            'row_status' => ExtractionRowStatus::Extracted,
        ]);

        $this->actingAs($chair)->post(route('chair.plantilla.confirm'))->assertRedirect(route('chair.teachers.index'));

        return Teacher::where('full_name', $name)->firstOrFail();
    }

    private function verify(Teacher $teacher, string $basis = 'plantilla')
    {
        return $this->actingAs($this->admin())->post(route('admin.teachers.verify', $teacher), [
            'basis' => $basis,
            'note' => 'Reviewed the Filipino plantilla with the Chair.',
        ]);
    }

    private function resolve(TeacherIdentityCase $case, string $decision, ?Teacher $teacher = null, ?string $version = null)
    {
        return $this->actingAs($this->admin())->post(route('admin.identity-cases.resolve', $case), [
            'decision' => $decision,
            'teacher_id' => $teacher?->id,
            'note' => 'Checked with the Chair.',
            'version' => $version ?? app(TeacherReconciliationService::class)->version($case->fresh()),
        ]);
    }

    public function test_admin_verifies_from_the_plantilla_and_chair_confirmation_without_a_directory(): void
    {
        $chair = $this->chair();
        $teacher = $this->importTeacher($chair, 'Leah Angelic C. Bilbar');

        $this->verify($teacher)->assertSessionHasErrors('basis');
        $this->assertSame(TeacherVerification::Pending, $teacher->fresh()->verification_status);

        $this->actingAs($chair)->post(route('chair.teachers.confirm-identity', $teacher), [
            'note' => 'Teaches Filipino full time; name is as on her appointment.',
        ])->assertSessionHasNoErrors();

        $this->verify($teacher)->assertSessionHasNoErrors();

        $teacher->refresh();
        $this->assertSame(TeacherVerification::Verified, $teacher->verification_status);
        $this->assertSame($this->admin()->id, $teacher->verified_by_user_id);
        $this->assertNotNull($teacher->verified_at);
        $this->assertSame('plantilla', $teacher->verification_evidence['basis']);
        $this->assertSame($chair->id, $teacher->verification_evidence['chair_confirmed_by_user_id']);
        $this->assertNotEmpty($teacher->verification_evidence['observations']);
        $this->assertTrue(AuditLog::where('action', 'teacher.verified')->where('auditable_id', $teacher->id)->exists());

        $this->actingAs($this->admin())->get(route('admin.teachers.index', ['verification' => 'verified']))
            ->assertOk()->assertSee('Leah Angelic C. Bilbar');
    }

    public function test_only_an_admin_verifies_and_only_the_teachers_chair_confirms(): void
    {
        $teacher = $this->importTeacher($this->chair(), 'Leah Angelic C. Bilbar');

        $this->actingAs($this->chair())->post(route('admin.teachers.verify', $teacher), ['basis' => 'other', 'note' => 'x'])->assertForbidden();
        $this->actingAs($this->chair('cle'))->post(route('chair.teachers.confirm-identity', $teacher), ['note' => 'x'])->assertNotFound();

        // Verification fields cannot be posted through an ordinary edit.
        $this->actingAs($this->chair())->put(route('chair.teachers.update', $teacher), [
            'full_name' => 'Leah Angelic C. Bilbar', 'employment_status' => 'permanent', 'verification_status' => 'verified',
        ]);
        $this->assertSame(TeacherVerification::Pending, $teacher->fresh()->verification_status);
    }

    public function test_renaming_a_verified_teacher_returns_them_to_pending(): void
    {
        $chair = $this->chair();
        $teacher = $this->importTeacher($chair, 'Leah Angelic C. Bilbar');
        $this->actingAs($chair)->post(route('chair.teachers.confirm-identity', $teacher), ['note' => 'ok']);
        $this->verify($teacher);

        $this->actingAs($this->admin())->put(route('admin.teachers.update', $teacher), [
            'full_name' => 'Leah Angelica C. Bilbar', 'department_id' => $teacher->department_id,
        ])->assertSessionHasNoErrors();

        $teacher->refresh();
        $this->assertSame(TeacherVerification::Pending, $teacher->verification_status);
        $this->assertNull($teacher->chair_confirmed_at);
        $this->assertSame('Leah Angelic C. Bilbar', $teacher->verification_evidence['full_name'], 'the earlier attestation stays on record');
    }

    public function test_chair_context_is_scoped_to_the_chairs_department(): void
    {
        $registrar = Teacher::create(['full_name' => 'Abigail Joyce L. Vendiola', 'source' => 'registrar']);
        $chair = $this->chair();
        $upload = PlantillaUpload::create([
            'plantilla_submission_id' => PlantillaSubmission::currentFor($chair->department_id)->id,
            'file_path' => 'x', 'original_filename' => 'x.pdf', 'extraction_status' => 'extracted',
        ]);
        $upload->rows()->create(['row_json' => ['teacher_name' => 'Abigail Joyce L. Vendola', 'flagged' => false], 'row_status' => ExtractionRowStatus::Extracted]);
        $this->actingAs($chair)->post(route('chair.plantilla.confirm'));
        $case = TeacherIdentityCase::sole();

        $this->actingAs($chair)->get(route('chair.plantilla.review'))
            ->assertOk()->assertSee('needs an Admin identity decision', false)->assertSee('Abigail Joyce L. Vendiola');

        $this->actingAs($chair)->post(route('chair.identity-cases.context', $case), ['note' => 'Same person; the sheet misspells her surname.'])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->chair('cle'))->post(route('chair.identity-cases.context', $case), ['note' => 'x'])->assertNotFound();

        $this->assertSame('Same person; the sheet misspells her surname.', $case->fresh()->chair_context[0]['note']);
        $this->actingAs($this->admin())->get(route('admin.identity-cases.show', $case))
            ->assertOk()->assertSee('the sheet misspells her surname')->assertSee($registrar->full_name);
    }

    public function test_a_stale_decision_is_refused(): void
    {
        $registrar = Teacher::create(['full_name' => 'Abigail Joyce L. Vendiola', 'source' => 'registrar']);
        $chair = $this->chair();
        $upload = PlantillaUpload::create([
            'plantilla_submission_id' => PlantillaSubmission::currentFor($chair->department_id)->id,
            'file_path' => 'x', 'original_filename' => 'x.pdf', 'extraction_status' => 'extracted',
        ]);
        $upload->rows()->create(['row_json' => ['teacher_name' => 'Abigail Joyce L. Vendola', 'flagged' => false], 'row_status' => ExtractionRowStatus::Extracted]);
        $this->actingAs($chair)->post(route('chair.plantilla.confirm'));
        $case = TeacherIdentityCase::sole();
        $opened = app(TeacherReconciliationService::class)->version($case);

        $this->travel(1)->minutes();
        $registrar->update(['full_name' => 'Abigail Joyce Vendiola Reyes']);

        $this->resolve($case, 'same', $registrar, $opened)->assertSessionHasErrors('decision');
        $this->assertSame(IdentityCaseStatus::Open, $case->fresh()->status);
        $this->assertSame(0, $registrar->aliases()->count());
    }

    public function test_a_department_conflict_suspends_a_verified_identity_until_decided(): void
    {
        $cle = $this->chair('cle');
        $teacher = $this->importTeacher($cle, 'Dave M. Natividad');
        $this->actingAs($cle)->post(route('chair.teachers.confirm-identity', $teacher), ['note' => 'CLE teacher.']);
        $this->verify($teacher)->assertSessionHasNoErrors();

        // The Filipino sheet also lists him.
        $fil = $this->chair();
        $upload = PlantillaUpload::create([
            'plantilla_submission_id' => PlantillaSubmission::currentFor($fil->department_id)->id,
            'file_path' => 'x', 'original_filename' => 'x.pdf', 'extraction_status' => 'extracted',
        ]);
        $upload->rows()->create(['row_json' => ['teacher_name' => 'Dave M. Natividad', 'flagged' => false], 'row_status' => ExtractionRowStatus::Extracted]);
        $this->actingAs($fil)->post(route('chair.plantilla.confirm'))->assertRedirect(route('chair.plantilla.review'));

        $case = TeacherIdentityCase::sole();
        $this->assertSame(IdentityCaseReason::DepartmentConflict, $case->reason);
        $this->assertSame(TeacherVerification::NeedsReview, $teacher->fresh()->verification_status);
        $this->assertSame($cle->department_id, $teacher->fresh()->department_id, 'the canonical department is untouched');
        $this->assertSame(1, Teacher::count());

        $this->resolve($case, 'keep_department', $teacher)->assertSessionHasNoErrors();

        $this->assertSame(TeacherVerification::Verified, $teacher->fresh()->verification_status);
    }

    public function test_a_chair_adding_a_look_alike_teacher_opens_a_case(): void
    {
        $chair = $this->chair();
        $existing = $this->importTeacher($chair, 'Leah Angelic C. Bilbar');

        // Exactly the same person again is refused outright.
        $this->actingAs($chair)->post(route('chair.teachers.store'), ['full_name' => 'Bb. Leah Angelic Bilbar', 'employment_status' => 'permanent'])
            ->assertSessionHasErrors('full_name');

        $this->actingAs($chair)->post(route('chair.teachers.store'), ['full_name' => 'Leah Angelica Bilbar', 'employment_status' => 'permanent'])
            ->assertSessionHasNoErrors();

        $added = Teacher::where('full_name', 'Leah Angelica Bilbar')->firstOrFail();
        $case = TeacherIdentityCase::sole();
        $this->assertSame($added->id, $case->subject_teacher_id);
        $this->assertSame([$existing->id], $case->candidate_ids);

        $this->resolve($case, 'distinct')->assertSessionHasNoErrors();
        $this->assertSame(2, Teacher::where('department_id', $chair->department_id)->count());
    }

    public function test_an_admin_adding_a_look_alike_must_confirm_it_is_someone_else(): void
    {
        $existing = $this->importTeacher($this->chair(), 'Leah Angelic C. Bilbar');
        $fil = Department::where('code', 'FIL')->first();

        $this->actingAs($this->admin())->post(route('admin.teachers.store'), ['full_name' => 'Leah Angelica Bilbar', 'department_id' => $fil->id])
            ->assertSessionHasErrors('full_name');

        $this->actingAs($this->admin())->post(route('admin.teachers.store'), [
            'full_name' => 'Leah Angelica Bilbar', 'department_id' => $fil->id, 'confirm_distinct' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, Teacher::count());
        $this->assertNotSame($existing->id, Teacher::where('full_name', 'Leah Angelica Bilbar')->value('id'));
    }

    public function test_a_late_directory_links_exact_names_and_investigates_absences(): void
    {
        $chair = $this->chair();
        $section = Section::where('school_year', '2026-2027')->orderBy('id')->firstOrFail();
        $present = $this->importTeacher($chair, 'Leah Angelic C. Bilbar');
        $this->actingAs($chair)->post(route('chair.teachers.confirm-identity', $present), ['note' => 'ok']);
        $this->verify($present);
        $absent = $this->importTeacher($chair, 'Rodelyn Omega', "{$section->grade_level->value}: {$section->name}");

        $this->actingAs($this->admin())->post(route('admin.directory-sources.store'), [
            'reference' => 'HR Teacher Directory, 2026-10-12 memo',
            'school_year' => '2026-2027',
            'completeness' => 'complete',
            'department_id' => $chair->department_id,
            'names' => "Leah Angelic C. Bilbar\nSomeone Not Yet Hired",
        ])->assertSessionHasNoErrors();

        $present->refresh();
        $this->assertSame(TeacherVerification::Verified, $present->verification_status, 'a matching late source does not undo verification');
        $this->assertTrue($present->observations()->where('source_type', 'directory')->exists());

        $case = TeacherIdentityCase::open()->sole();
        $this->assertSame(IdentityCaseReason::DirectoryAbsence, $case->reason);
        $this->assertSame($absent->id, $case->subject_teacher_id);
        $this->assertNull($absent->fresh()->merged_into_teacher_id);
        $this->assertSame(1, TeacherSectionAssignment::where('teacher_id', $absent->id)->count(), 'investigation keeps the teacher and loads');
        $this->assertSame(0, Teacher::where('full_name', 'Someone Not Yet Hired')->count());

        $this->resolve($case, 'absence_explained')->assertSessionHasNoErrors();
        $this->assertSame(1, TeacherSectionAssignment::where('teacher_id', $absent->id)->count());
    }

    public function test_a_partial_directory_never_raises_an_absence(): void
    {
        $this->importTeacher($this->chair(), 'Rodelyn Omega');

        $this->actingAs($this->admin())->post(route('admin.directory-sources.store'), [
            'reference' => 'Moderator list excerpt',
            'school_year' => '2026-2027',
            'completeness' => 'partial',
            'names' => 'Leah Angelic C. Bilbar',
        ])->assertSessionHasNoErrors();

        $this->assertSame(0, TeacherIdentityCase::count());
    }
}
