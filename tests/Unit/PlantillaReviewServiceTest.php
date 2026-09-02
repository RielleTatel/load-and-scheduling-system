<?php

namespace Tests\Unit;

use App\Enums\EmploymentStatus;
use App\Models\PlantillaSubmission;
use App\Models\PlantillaUpload;
use App\Models\Teacher;
use App\Models\TeacherOtherAssignment;
use App\Models\User;
use App\Services\Plantilla\PlantillaReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlantillaReviewServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeUploadFor(User $chair, array $rows): PlantillaUpload
    {
        $submission = PlantillaSubmission::currentFor($chair->department_id);
        $upload = PlantillaUpload::create([
            'plantilla_submission_id' => $submission->id,
            'file_path' => 'plantillas/test.pdf',
            'original_filename' => 'test.pdf',
            'extraction_status' => 'extracted',
        ]);

        foreach ($rows as $row) {
            $upload->rows()->create(['row_json' => $row, 'row_status' => 'extracted']);
        }

        return $upload;
    }

    /**
     * @dataProvider abbreviatedRoles
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('abbreviatedRoles')]
    public function test_common_abbreviations_match_a_role(string $written, string $expected): void
    {
        $this->seed();
        $chair = User::where('email', 'chair.fil@jhs.test')->first();
        $this->actingAs($chair);

        $upload = $this->makeUploadFor($chair, [[
            'teacher_name' => 'Test Teacher', 'employment_status' => 'Permanent',
            'sections' => 'Arrowsmith', 'cm' => null, 'hc' => null, 'service_load' => '3',
            'other_assignment' => $written, 'flagged' => false,
        ]]);

        $result = app(PlantillaReviewService::class)->confirmImport($upload);

        $this->assertSame([], $result['skipped'], "\"{$written}\" should match {$expected}");
        $this->assertSame($expected, TeacherOtherAssignment::first()->role->name);
    }

    public static function abbreviatedRoles(): array
    {
        return [
            'Chairperson' => ['Chairperson', 'Department Chair'],
            'GLL' => ['GLL', 'Grade Level Leader'],
            'grade-qualified GLL' => ['G9 GLL', 'Grade Level Leader'],
            'FDP' => ['FDP', 'Faculty Development'],
        ];
    }

    public function test_role_matches_when_the_sheet_prefixes_the_name(): void
    {
        // CLE writes "Compania Musica de Aguilas Club Moderator".
        $this->seed();
        $chair = User::where('email', 'chair.fil@jhs.test')->first();
        $this->actingAs($chair);

        $upload = $this->makeUploadFor($chair, [[
            'teacher_name' => 'Test Teacher', 'employment_status' => 'Permanent',
            'sections' => 'Arrowsmith', 'cm' => null, 'hc' => null, 'service_load' => '3',
            'other_assignment' => 'Compania Musica de Aguilas Club Moderator', 'flagged' => false,
        ]]);

        $result = app(PlantillaReviewService::class)->confirmImport($upload);

        $this->assertSame([], $result['skipped']);
        $this->assertSame('Musica de Aguilas Club Moderator', TeacherOtherAssignment::first()->role->name);
    }

    public function test_role_matches_despite_an_honorarium_suffix(): void
    {
        $this->seed();
        $chair = User::where('email', 'chair.fil@jhs.test')->first();
        $this->actingAs($chair);

        $upload = $this->makeUploadFor($chair, [[
            'teacher_name' => 'Test Teacher', 'employment_status' => 'Permanent',
            'sections' => 'Arrowsmith', 'cm' => null, 'hc' => null, 'service_load' => '3',
            'other_assignment' => 'Youth for Christ (Honorarium only)', 'flagged' => false,
        ]]);

        $result = app(PlantillaReviewService::class)->confirmImport($upload);

        $this->assertSame([], $result['skipped']);
        $this->assertSame(1, TeacherOtherAssignment::count());
    }

    public function test_role_matches_an_abbreviated_name(): void
    {
        // The sheet writes "Faculty Dev. (Thesis Writing)"; the role is
        // "Faculty Development".
        $this->seed();
        $chair = User::where('email', 'chair.fil@jhs.test')->first();
        $this->actingAs($chair);

        $upload = $this->makeUploadFor($chair, [[
            'teacher_name' => 'Test Teacher', 'employment_status' => 'Permanent',
            'sections' => 'Arrowsmith', 'cm' => null, 'hc' => null, 'service_load' => '3',
            'other_assignment' => 'Faculty Dev. (Thesis Writing)', 'flagged' => false,
        ]]);

        $result = app(PlantillaReviewService::class)->confirmImport($upload);

        $this->assertSame([], $result['skipped']);
    }

    public function test_moderator_is_derived_from_the_registrar_roster(): void
    {
        // The Filipino sheet does name Delos Reyes as Rubio's moderator, but CLE,
        // Math, TLE and Social Studies never record one. Matching the imported
        // teacher against the roster fills them in regardless.
        $this->seed();
        $chair = User::where('email', 'chair.fil@jhs.test')->first();
        $this->actingAs($chair);

        $upload = $this->makeUploadFor($chair, [[
            'teacher_name' => 'Cristie R. Delos Reyes', 'employment_status' => 'Permanent',
            'sections' => 'G7: Arrowsmith', 'cm' => null, 'hc' => null,
            'service_load' => '3', 'other_assignment' => null, 'flagged' => false,
        ]]);

        app(PlantillaReviewService::class)->confirmImport($upload);

        $teacher = Teacher::where('full_name', 'Cristie R. Delos Reyes')->first();
        $cm = \App\Models\ClassModeratorAssignment::where('teacher_id', $teacher->id)->first();
        $this->assertNotNull($cm, 'the roster moderator should be assigned on import');
        $this->assertSame('Rubio', $cm->section->name);
    }

    public function test_plantilla_moderator_conflicting_with_the_roster_is_flagged(): void
    {
        $this->seed();
        $chair = User::where('email', 'chair.fil@jhs.test')->first();
        $this->actingAs($chair);

        $upload = $this->makeUploadFor($chair, [[
            'teacher_name' => 'Cristie R. Delos Reyes', 'employment_status' => 'Permanent',
            'sections' => 'G7: Arrowsmith', 'cm' => 'G7: Campion', 'hc' => null,
            'service_load' => '3', 'other_assignment' => null, 'flagged' => false,
        ]]);

        $result = app(PlantillaReviewService::class)->confirmImport($upload);

        $this->assertStringContainsString('Campion', implode(' ', $result['skipped']));
        $this->assertStringContainsString('Rubio', implode(' ', $result['skipped']));
    }

    public function test_scholastic_honorific_does_not_block_the_roster_match(): void
    {
        // Science writes "SCH. JAMES RYAN C. SENERICHES, SJ"; the roster has
        // "James Ryan C. Seneriches, SJ".
        $this->seed();
        $chair = User::where('email', 'chair.sci@jhs.test')->first() ?? User::where('email', 'chair.fil@jhs.test')->first();
        $this->actingAs($chair);

        $upload = $this->makeUploadFor($chair, [[
            'teacher_name' => 'SCH. JAMES RYAN C. SENERICHES, SJ', 'employment_status' => 'FT Probationary 2',
            'sections' => 'G10: Chabanel', 'cm' => null, 'hc' => null,
            'service_load' => '3', 'other_assignment' => null, 'flagged' => false,
        ]]);

        app(PlantillaReviewService::class)->confirmImport($upload);

        $cm = \App\Models\ClassModeratorAssignment::first();
        $this->assertNotNull($cm);
        $this->assertSame('Colombiere', $cm->section->name);
    }

    public function test_row_without_an_employment_status_still_imports(): void
    {
        // No Social Studies row states a status. Discarding the row throws away
        // the teacher's whole load over a field the Chair can fill in.
        $this->seed();
        $chair = User::where('email', 'chair.fil@jhs.test')->first();
        $this->actingAs($chair);

        $upload = $this->makeUploadFor($chair, [[
            'teacher_name' => 'Rodelyn Omega', 'employment_status' => null,
            'sections' => 'G7: Rubio', 'cm' => null, 'hc' => null,
            'service_load' => '3', 'other_assignment' => null, 'flagged' => false,
        ]]);

        $result = app(PlantillaReviewService::class)->confirmImport($upload);

        $this->assertSame(1, $result['imported']);
        $teacher = Teacher::where('full_name', 'Rodelyn Omega')->first();
        $this->assertNotNull($teacher);
        $this->assertNull($teacher->employment_status);
        $this->assertSame(1, $teacher->sectionAssignments()->count());
        $this->assertStringContainsString('employment status', implode(' ', $result['skipped']));
    }

    public function test_compound_first_name_still_matches_the_roster(): void
    {
        // CLE writes "Marycris Asdali"; the roster has "Mary Cris Asdali".
        $this->seed();
        $chair = User::where('email', 'chair.fil@jhs.test')->first();
        $this->actingAs($chair);

        $upload = $this->makeUploadFor($chair, [[
            'teacher_name' => 'Marycris Asdali', 'employment_status' => 'FT Probationary 2',
            'sections' => 'G7: Jogues', 'cm' => null, 'hc' => null,
            'service_load' => '3', 'other_assignment' => null, 'flagged' => false,
        ]]);

        app(PlantillaReviewService::class)->confirmImport($upload);

        $cm = \App\Models\ClassModeratorAssignment::first();
        $this->assertNotNull($cm);
        $this->assertSame('Regis', $cm->section->name);
    }

    public function test_two_teachers_sharing_a_surname_are_not_confused(): void
    {
        // Cristie R. Delos Reyes moderates G7 Rubio; Ivy Q. Delos Reyes moderates
        // G10 Southwell. Matching on surname alone would swap them.
        $this->seed();
        $chair = User::where('email', 'chair.fil@jhs.test')->first();
        $this->actingAs($chair);

        $upload = $this->makeUploadFor($chair, [[
            'teacher_name' => 'Ivy Q. Delos Reyes', 'employment_status' => 'Permanent',
            'sections' => 'G10: Southwell', 'cm' => null, 'hc' => null,
            'service_load' => '3', 'other_assignment' => null, 'flagged' => false,
        ]]);

        app(PlantillaReviewService::class)->confirmImport($upload);

        $this->assertSame('Southwell', \App\Models\ClassModeratorAssignment::first()->section->name);
    }

    public function test_unknown_section_is_skipped_not_created(): void
    {
        // Section::firstOrCreate() used to mint a new section for every name the
        // extractor could not place - that is how 85 sections appeared in a
        // 36-section school. Unknown names must be refused.
        $this->seed();
        $chair = User::where('email', 'chair.fil@jhs.test')->first();
        $this->actingAs($chair);
        $before = \App\Models\Section::count();

        $upload = $this->makeUploadFor($chair, [[
            'teacher_name' => 'Test Teacher', 'employment_status' => 'Permanent',
            'sections' => 'G7: Nonexistent', 'cm' => null, 'hc' => null,
            'service_load' => '3', 'other_assignment' => null, 'flagged' => false,
        ]]);

        $result = app(PlantillaReviewService::class)->confirmImport($upload);

        $this->assertSame($before, \App\Models\Section::count(), 'no section may be invented on import');
        $this->assertNotEmpty($result['skipped']);
        $this->assertStringContainsString('Nonexistent', implode(' ', $result['skipped']));
    }

    public function test_grade_comes_from_the_roster_not_from_the_row(): void
    {
        // The row claims G9; the roster says Xavier is G8. The roster wins.
        $this->seed();
        $chair = User::where('email', 'chair.fil@jhs.test')->first();
        $this->actingAs($chair);

        $upload = $this->makeUploadFor($chair, [[
            'teacher_name' => 'Test Teacher', 'employment_status' => 'Permanent',
            'sections' => 'G9: Xavier', 'cm' => null, 'hc' => null,
            'service_load' => '3', 'other_assignment' => null, 'flagged' => false,
        ]]);

        app(PlantillaReviewService::class)->confirmImport($upload);

        $teacher = Teacher::where('full_name', 'Test Teacher')->first();
        $section = $teacher->sectionAssignments()->first()->section;
        $this->assertSame('Xavier', $section->name);
        $this->assertSame('G8', $section->grade_level->value);
    }

    public function test_bare_section_name_without_grade_prefix_imports(): void
    {
        $this->seed();
        $chair = User::where('email', 'chair.fil@jhs.test')->first();
        $this->actingAs($chair);

        $upload = $this->makeUploadFor($chair, [[
            'teacher_name' => 'Test Teacher', 'employment_status' => 'Permanent',
            'sections' => 'Arrowsmith, Jogues', 'cm' => null, 'hc' => null,
            'service_load' => '3', 'other_assignment' => null, 'flagged' => false,
        ]]);

        app(PlantillaReviewService::class)->confirmImport($upload);

        $this->assertSame(2, Teacher::where('full_name', 'Test Teacher')->first()->sectionAssignments()->count());
    }

    public function test_confirm_import_creates_authoritative_records(): void
    {
        $this->seed();
        $chair = User::where('email', 'chair.fil@jhs.test')->first();
        $this->actingAs($chair);

        $upload = $this->makeUploadFor($chair, [[
            'teacher_name' => 'Leah Angelic C. Bilbar', 'employment_status' => 'Permanent',
            'sections' => 'G7: Ignatius', 'cm' => null, 'hc' => null,
            'service_load' => '3', 'other_assignment' => 'Department Chair', 'flagged' => false,
        ]]);

        $result = app(PlantillaReviewService::class)->confirmImport($upload);

        $this->assertSame(1, $result['imported']);

        $teacher = Teacher::where('full_name', 'Leah Angelic C. Bilbar')->first();
        $this->assertNotNull($teacher);
        $this->assertSame(EmploymentStatus::Permanent, $teacher->employment_status);
        $this->assertSame(1, $teacher->sectionAssignments()->count());
        $this->assertEquals(4.0, (float) $teacher->sectionAssignments()->first()->hours); // FIL = 4h
        $this->assertDatabaseHas('service_loads', ['teacher_id' => $teacher->id, 'hours' => 3]);
        $this->assertSame(1, TeacherOtherAssignment::count());
        $this->assertSame('reviewed', $upload->fresh()->extraction_status);
    }

    public function test_unknown_status_is_reported_but_the_teacher_still_imports(): void
    {
        // Changed 2026-09-02: an unreadable status used to discard the whole row.
        // It now imports with a null status and is reported for the Chair, so a
        // sheet that omits the column (Social Studies) does not lose its load.
        $this->seed();
        $chair = User::where('email', 'chair.fil@jhs.test')->first();
        $this->actingAs($chair);

        $upload = $this->makeUploadFor($chair, [[
            'teacher_name' => 'Mystery Person', 'employment_status' => 'Freelance',
            'sections' => null, 'cm' => null, 'hc' => null, 'service_load' => null,
            'other_assignment' => null, 'flagged' => false,
        ]]);

        $result = app(PlantillaReviewService::class)->confirmImport($upload);

        $this->assertSame(1, $result['imported']);
        $this->assertNotEmpty($result['skipped']);
        $this->assertStringContainsString('Freelance', implode(' ', $result['skipped']));

        $teacher = Teacher::where('full_name', 'Mystery Person')->first();
        $this->assertNotNull($teacher);
        $this->assertNull($teacher->employment_status);
    }

    public function test_nameless_rows_are_skipped(): void
    {
        $this->seed();
        $chair = User::where('email', 'chair.fil@jhs.test')->first();
        $this->actingAs($chair);

        $upload = $this->makeUploadFor($chair, [[
            'teacher_name' => null, 'employment_status' => 'Permanent',
            'sections' => 'G7: Ignatius', 'flagged' => true,
        ]]);

        $result = app(PlantillaReviewService::class)->confirmImport($upload);

        $this->assertSame(0, $result['imported']);
        // The registrar's staff are seeded up front, so assert nothing was added
        // to this department rather than that no teacher exists at all.
        $this->assertSame(0, Teacher::where('department_id', $chair->department_id)->count());
    }
}
