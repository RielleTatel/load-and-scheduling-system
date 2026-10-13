<?php

namespace Tests\Feature\TeacherIdentity;

use App\Enums\ExtractionRowStatus;
use App\Enums\IdentityCaseStatus;
use App\Enums\TeacherVerification;
use App\Models\PlantillaSubmission;
use App\Models\PlantillaUpload;
use App\Models\RosterImport;
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
 * The plantilla and the registrar's roster name the same people, in either
 * order and often spelled differently. These tests pin the agreed boundary:
 * exact names link in both orders, spelling variants wait for an Admin
 * decision, and nothing is imported twice or onto the wrong person.
 */
class TeacherIdentityReconciliationTest extends TestCase
{
    use RefreshDatabase;

    /** Everything except the registrar staff seeder: an empty teacher directory. */
    private function seedWithoutDirectory(): void
    {
        $this->seed([
            DepartmentSeeder::class,
            SectionSeeder::class,
            OtherAssignmentRoleSeeder::class,
            SystemConstantSeeder::class,
            UserSeeder::class,
        ]);
    }

    private function admin(): User
    {
        return User::where('email', 'admin@jhs.test')->firstOrFail();
    }

    private function chair(string $code = 'fil'): User
    {
        return User::where('email', "chair.{$code}@jhs.test")->firstOrFail();
    }

    private function section(int $offset = 0): Section
    {
        return Section::where('school_year', '2026-2027')->orderBy('id')->skip($offset)->firstOrFail();
    }

    private function sectionLabel(Section $section): string
    {
        return "{$section->grade_level->value}: {$section->name}";
    }

    /** @param  array<int, array<string, mixed>>  $rows */
    private function stagePlantilla(User $chair, array $rows): PlantillaUpload
    {
        $submission = PlantillaSubmission::currentFor($chair->department_id);
        $upload = PlantillaUpload::create([
            'plantilla_submission_id' => $submission->id,
            'file_path' => 'plantillas/test.pdf',
            'original_filename' => 'test.pdf',
            'extraction_status' => 'extracted',
        ]);

        foreach ($rows as $row) {
            $upload->rows()->create([
                'row_json' => array_merge([
                    'teacher_name' => null, 'employment_status' => 'Permanent', 'sections' => null,
                    'cm' => null, 'hc' => null, 'service_load' => null, 'other_assignment' => null,
                    'flagged' => false,
                ], $row),
                'row_status' => ExtractionRowStatus::Extracted,
            ]);
        }

        return $upload;
    }

    private function suffix(int $n): string
    {
        return chr(65 + intdiv($n - 1, 26)).chr(65 + (($n - 1) % 26));
    }

    /** A complete 36-section roster for another year, naming $moderator on the first section. */
    private function stageRoster(string $moderator): RosterImport
    {
        $import = RosterImport::create([
            'school_year' => '2027-2028',
            'file_path' => 'rosters/x.pdf',
            'original_filename' => 'x.pdf',
            'extraction_status' => 'extracted',
        ]);

        $n = 0;
        foreach (['G7', 'G8', 'G9', 'G10'] as $grade) {
            for ($i = 1; $i <= 9; $i++) {
                $n++;
                $import->rows()->create([
                    'row_json' => [
                        'grade_level' => $grade,
                        'full_name' => "Saint Section {$n}",
                        'name' => "Section{$n}",
                        'room' => (string) (100 + $n),
                        'is_magis' => false,
                        'moderator_name' => $n === 1 ? $moderator : 'Moderator '.$this->suffix($n),
                        'teacher_partner_name' => 'Partner '.$this->suffix($n),
                        'flagged' => false,
                        'flags' => [],
                    ],
                    'row_status' => ExtractionRowStatus::Extracted,
                ]);
            }
        }

        return $import;
    }

    private function confirmPlantilla(User $chair)
    {
        return $this->actingAs($chair)->post(route('chair.plantilla.confirm'));
    }

    private function confirmRoster()
    {
        return $this->actingAs($this->admin())->post(route('admin.roster.confirm'));
    }

    private function resolveSamePerson(TeacherIdentityCase $case, Teacher $teacher)
    {
        return $this->actingAs($this->admin())->post(route('admin.identity-cases.resolve', $case), [
            'decision' => 'same',
            'teacher_id' => $teacher->id,
            'note' => 'Chair confirmed both spellings name the same teacher.',
            'version' => app(TeacherReconciliationService::class)->version($case),
        ]);
    }

    public function test_a_reviewed_plantilla_creates_pending_teachers_without_a_teacher_directory(): void
    {
        $this->seedWithoutDirectory();
        $this->assertSame(0, Teacher::count());
        $chair = $this->chair();
        $section = $this->section();
        $this->stagePlantilla($chair, [
            ['teacher_name' => 'Leah Angelic C. Bilbar', 'sections' => $this->sectionLabel($section)],
            ['teacher_name' => 'Rodelyn Omega'],
        ]);

        $this->confirmPlantilla($chair)->assertRedirect(route('chair.teachers.index'));

        $this->assertSame(2, Teacher::count());
        $leah = Teacher::where('full_name', 'Leah Angelic C. Bilbar')->firstOrFail();
        $this->assertSame(TeacherVerification::Pending, $leah->verification_status);
        $this->assertSame($chair->department_id, $leah->department_id);
        $this->assertNull($leah->verified_at);
        $this->assertSame(1, $leah->sectionAssignments()->count());

        // Retrying the same confirmation reuses the same identities.
        $this->confirmPlantilla($chair)->assertRedirect(route('chair.teachers.index'));
        $this->assertSame(2, Teacher::count());
        $this->assertSame($leah->id, TeacherSectionAssignment::where('section_id', $section->id)->value('teacher_id'));

        // Pending verification never stops ordinary load encoding.
        $this->actingAs($chair)->post(route('chair.assignments.store'), [
            'teacher_id' => $leah->id,
            'section_id' => $this->section(1)->id,
        ])->assertSessionHasNoErrors();
        $this->assertSame(2, $leah->sectionAssignments()->count());
    }

    public function test_an_exact_name_links_when_the_registrar_names_the_teacher_first(): void
    {
        $this->seedWithoutDirectory();
        $this->stageRoster('Abigail Joyce L. Vendiola');
        $this->confirmRoster()->assertRedirect(route('admin.sections.index'));
        $registrar = Teacher::where('full_name', 'Abigail Joyce L. Vendiola')->firstOrFail();

        $chair = $this->chair();
        $this->stagePlantilla($chair, [['teacher_name' => 'Abigail Joyce L. Vendiola', 'sections' => $this->sectionLabel($this->section())]]);
        $this->confirmPlantilla($chair)->assertRedirect(route('chair.teachers.index'));

        $this->assertSame(1, Teacher::where('normalized_name', Teacher::normalize('Abigail Joyce L. Vendiola'))->count());
        $this->assertSame($chair->department_id, $registrar->fresh()->department_id);
        $this->assertSame(1, $registrar->sectionAssignments()->count());
        $this->assertSame(0, TeacherIdentityCase::count());
    }

    public function test_an_exact_name_links_when_the_plantilla_names_the_teacher_first(): void
    {
        $this->seedWithoutDirectory();
        $chair = $this->chair();
        $this->stagePlantilla($chair, [['teacher_name' => 'Abigail Joyce L. Vendiola', 'sections' => $this->sectionLabel($this->section())]]);
        $this->confirmPlantilla($chair);
        $teacher = Teacher::where('full_name', 'Abigail Joyce L. Vendiola')->firstOrFail();

        $this->stageRoster('Abigail Joyce L. Vendiola');
        $this->confirmRoster()->assertRedirect(route('admin.sections.index'));

        $this->assertSame(1, Teacher::where('normalized_name', Teacher::normalize('Abigail Joyce L. Vendiola'))->count());
        $this->assertSame($chair->department_id, $teacher->fresh()->department_id);
        $this->assertSame(1, $teacher->sectionAssignments()->count());
        $this->assertSame(0, TeacherIdentityCase::count());
    }

    public function test_a_spelling_variant_after_the_registrar_waits_for_an_admin_decision(): void
    {
        $this->seedWithoutDirectory();
        $this->stageRoster('Abigail Joyce L. Vendiola');
        $this->confirmRoster();
        $registrar = Teacher::where('full_name', 'Abigail Joyce L. Vendiola')->firstOrFail();
        $teachersBefore = Teacher::count();

        $chair = $this->chair();
        $section = $this->section();
        $this->stagePlantilla($chair, [['teacher_name' => 'Abigail Joyce L. Vendola', 'sections' => $this->sectionLabel($section)]]);

        $this->confirmPlantilla($chair)->assertRedirect(route('chair.plantilla.review'))->assertSessionHas('warning');

        // The batch stays staged: no new identity, no loads, no department claimed.
        $this->assertSame($teachersBefore, Teacher::count());
        $this->assertSame(0, TeacherSectionAssignment::count());
        $this->assertNull($registrar->fresh()->department_id);
        $case = TeacherIdentityCase::sole();
        $this->assertSame(IdentityCaseStatus::Open, $case->status);
        $this->assertContains($registrar->id, $case->candidate_ids);

        // A repeated confirmation reuses the case rather than opening another.
        $this->confirmPlantilla($chair);
        $this->assertSame(1, TeacherIdentityCase::count());

        $this->resolveSamePerson($case, $registrar)->assertSessionHasNoErrors();
        $this->confirmPlantilla($chair)->assertRedirect(route('chair.teachers.index'));

        $this->assertSame($teachersBefore, Teacher::count());
        $this->assertSame($chair->department_id, $registrar->fresh()->department_id);
        $this->assertSame($registrar->id, TeacherSectionAssignment::where('section_id', $section->id)->value('teacher_id'));

        // The approved alias links later sheets without another case.
        $this->stagePlantilla($chair, [['teacher_name' => 'Abigail Joyce L. Vendola', 'sections' => $this->sectionLabel($section)]]);
        $this->confirmPlantilla($chair)->assertRedirect(route('chair.teachers.index'));
        $this->assertSame(1, TeacherIdentityCase::count());
        $this->assertSame($teachersBefore, Teacher::count());
    }

    public function test_a_spelling_variant_after_the_plantilla_waits_for_an_admin_decision(): void
    {
        $this->seedWithoutDirectory();
        $chair = $this->chair();
        $section = $this->section();
        $this->stagePlantilla($chair, [['teacher_name' => 'Abigail Joyce L. Vendola', 'sections' => $this->sectionLabel($section)]]);
        $this->confirmPlantilla($chair);
        $teacher = Teacher::where('full_name', 'Abigail Joyce L. Vendola')->firstOrFail();

        $this->stageRoster('Abigail Joyce L. Vendiola');
        $this->confirmRoster()->assertSessionHas('warning');

        // The roster stays staged and nobody is duplicated.
        $this->assertSame(0, Section::where('school_year', '2027-2028')->count());
        $this->assertSame(0, Teacher::where('full_name', 'Abigail Joyce L. Vendiola')->count());
        $case = TeacherIdentityCase::sole();
        $this->assertContains($teacher->id, $case->candidate_ids);

        $this->resolveSamePerson($case, $teacher)->assertSessionHasNoErrors();
        $this->confirmRoster()->assertRedirect(route('admin.sections.index'));

        $this->assertSame(36, Section::where('school_year', '2027-2028')->count());
        $this->assertSame(0, Teacher::where('full_name', 'Abigail Joyce L. Vendiola')->count());
        $this->assertSame($chair->department_id, $teacher->fresh()->department_id);
        $this->assertSame($teacher->id, TeacherSectionAssignment::where('section_id', $section->id)->value('teacher_id'));
    }
}
