<?php

namespace Tests\Unit;

use App\Enums\IdentityCaseReason;
use App\Models\Department;
use App\Models\Teacher;
use App\Services\Teachers\IdentityObservation;
use App\Services\Teachers\IdentityOutcome;
use App\Services\Teachers\NameMatch;
use App\Services\Teachers\NameSimilarity;
use App\Services\Teachers\TeacherIdentityService;
use Database\Seeders\DepartmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TeacherIdentityServiceTest extends TestCase
{
    use RefreshDatabase;

    private function dept(string $code = 'FIL'): Department
    {
        if (! Department::exists()) {
            $this->seed(DepartmentSeeder::class);
        }

        return Department::where('code', $code)->firstOrFail();
    }

    private function sheet(string $name, Department $dept, string $key = 'plantilla_row:1'): IdentityObservation
    {
        return new IdentityObservation(IdentityObservation::PLANTILLA, $key, $name, $dept->id);
    }

    private function inspect(IdentityObservation $observation): IdentityOutcome
    {
        return app(TeacherIdentityService::class)->inspect($observation);
    }

    private function commit(IdentityObservation $observation): Teacher
    {
        $service = app(TeacherIdentityService::class);

        return $service->commit($observation, $service->inspect($observation));
    }

    public function test_normalizes_away_honorifics_initials_and_spacing(): void
    {
        $this->assertSame('cristie delos reyes', Teacher::normalize('Bb. Cristie R. Delos Reyes'));
        $this->assertSame('james ryan seneriches', Teacher::normalize('SCH. JAMES RYAN C. SENERICHES, SJ'));
        $this->assertSame('fritzie dealagdon', Teacher::normalize('  Fritzie   Dealagdon '));
    }

    public function test_an_exact_name_in_the_department_reuses_the_teacher(): void
    {
        $dept = $this->dept();
        $first = $this->commit($this->sheet('Fritzie Dealagdon', $dept, 'plantilla_row:1'));
        $again = $this->commit($this->sheet('  Fritzie  Dealagdon ', $dept, 'plantilla_row:2'));

        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, Teacher::count());
    }

    public function test_a_spelling_variant_is_suggested_not_joined(): void
    {
        $dept = $this->dept();
        $existing = $this->commit($this->sheet('Fritzie Dealagdon', $dept, 'plantilla_row:1'));

        $outcome = $this->inspect($this->sheet('Frizie B. Dealagdon', $dept, 'plantilla_row:2'));

        $this->assertTrue($outcome->needsReview());
        $this->assertSame(IdentityCaseReason::PossibleMatch, $outcome->reason);
        $this->assertSame([$existing->id], $outcome->candidateIds);
    }

    public function test_distinct_people_sharing_a_surname_stay_separate(): void
    {
        $dept = $this->dept();

        $a = $this->commit($this->sheet('Cristie R. Delos Reyes', $dept, 'plantilla_row:1'));
        $b = $this->commit($this->sheet('Ivy Q. Delos Reyes', $dept, 'plantilla_row:2'));

        $this->assertNotSame($a->id, $b->id);
        $this->assertSame(2, Teacher::count());
    }

    public function test_an_exact_registrar_name_is_linked_and_given_the_sheets_department(): void
    {
        // The registrar list names people before any plantilla arrives, so they
        // exist with no department until their sheet is imported.
        $dept = $this->dept();
        $seeded = Teacher::create(['full_name' => 'Angelica M Singson', 'source' => 'registrar']);

        $teacher = $this->commit($this->sheet('Angelica M. Singson', $dept));

        $this->assertSame($seeded->id, $teacher->id);
        $this->assertSame($dept->id, $teacher->fresh()->department_id);
        $this->assertSame('registrar', $teacher->fresh()->source, 'the first source is kept as provenance');
        $this->assertSame(1, Teacher::count());
    }

    /**
     * Every one of these pairs is the same person written two ways — the
     * registrar's roster on the left, a plantilla sheet on the right. All were
     * found during an end-to-end import. They used to be joined automatically;
     * now each is a suggestion an Admin confirms once.
     */
    #[DataProvider('samePersonWrittenTwoWays')]
    public function test_registrar_spelling_variants_are_suggested_for_review(string $registrar, string $sheet): void
    {
        $dept = $this->dept();
        $seeded = Teacher::create(['full_name' => $registrar, 'source' => 'registrar']);

        $outcome = $this->inspect($this->sheet($sheet, $dept));

        $this->assertTrue($outcome->needsReview(), "\"{$sheet}\" should be suggested as \"{$registrar}\"");
        $this->assertSame([$seeded->id], $outcome->candidateIds);
        $this->assertNotSame(NameMatch::Different, app(NameSimilarity::class)->compare($registrar, $sheet));
    }

    public static function samePersonWrittenTwoWays(): array
    {
        return [
            'surname variant' => ['Abigail Joyce L. Vendiola', 'Abigail Joyce L. Vendola'],
            'given-name variant' => ['Fhaijah H. Abduraja', 'FHAIJA ABDURAJA'],
            'transposed letters' => ['Frizie B. Dealagdon', 'Fritzie Dealagdon'],
            'compound given name' => ['Mary Cris Asdali', 'Marycris Asdali'],
            'pluralised surname' => ['Nhel Mathew P. Divinagracia', 'NHEL MATHEW DIVINAGRACIAS'],
            'dropped middle name' => ['Mark Brian D. Gumandao', 'MARK GUMANDAO'],
            'dropped middle name 2' => ['Monica Jane S. Bayona', 'Monica Bayona'],
        ];
    }

    public function test_an_uncertain_surname_match_is_suggested(): void
    {
        // "Jolapong" vs "Japalong" is three edits — plausibly the same person.
        $dept = $this->dept();
        $seeded = Teacher::create(['full_name' => 'Gia Nicole F. Jolapong', 'source' => 'registrar']);

        $outcome = $this->inspect($this->sheet('Gia Nicole Japalong', $dept));

        $this->assertTrue($outcome->needsReview());
        $this->assertSame([$seeded->id], $outcome->candidateIds);
    }

    public function test_different_people_who_share_a_surname_are_not_suggested(): void
    {
        $dept = $this->dept();
        Teacher::create(['full_name' => 'Cristie R. Delos Reyes', 'source' => 'registrar']);
        Teacher::create(['full_name' => 'Francheska June Naomi A. Francisco', 'source' => 'registrar']);

        $this->assertSame(IdentityOutcome::NEW, $this->inspect($this->sheet('Ivy Q. Delos Reyes', $dept))->kind);
        $this->assertSame(IdentityOutcome::NEW, $this->inspect($this->sheet('Mary Ann A. Francisco', $dept, 'plantilla_row:2'))->kind);
    }

    public function test_the_same_name_in_another_department_is_a_department_conflict(): void
    {
        $cle = $this->dept('CLE');
        $fil = $this->dept('FIL');
        $teacher = $this->commit($this->sheet('Dave M. Natividad', $cle, 'plantilla_row:1'));

        $outcome = $this->inspect($this->sheet('Dave M. Natividad', $fil, 'plantilla_row:2'));

        $this->assertTrue($outcome->needsReview());
        $this->assertSame(IdentityCaseReason::DepartmentConflict, $outcome->reason);
        $this->assertSame([$teacher->id], $outcome->candidateIds);
    }

    public function test_two_unclaimed_records_with_one_name_are_ambiguous(): void
    {
        $dept = $this->dept();
        // Distinct people the registrar named identically before the ordinal existed.
        $a = Teacher::create(['full_name' => 'Maria Santos', 'source' => 'registrar']);
        $b = Teacher::create(['full_name' => 'Maria Santos', 'source' => 'registrar']);

        $outcome = $this->inspect($this->sheet('Maria Santos', $dept));

        $this->assertSame(IdentityCaseReason::AmbiguousMatch, $outcome->reason);
        $this->assertSame([$a->id, $b->id], $outcome->candidateIds);
    }

    public function test_an_edited_name_does_not_reuse_the_rows_earlier_mapping(): void
    {
        $dept = $this->dept();
        $first = $this->commit($this->sheet('Rodelyn Omega', $dept, 'plantilla_row:9'));

        $renamed = $this->inspect($this->sheet('Leah Angelic C. Bilbar', $dept, 'plantilla_row:9'));

        $this->assertSame(IdentityOutcome::NEW, $renamed->kind, 'a corrected name must not keep the old teacher');
        $this->assertSame($first->id, $this->inspect($this->sheet('Rodelyn Omega', $dept, 'plantilla_row:9'))->teacher->id);
    }
}
