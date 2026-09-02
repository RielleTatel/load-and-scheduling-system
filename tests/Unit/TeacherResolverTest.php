<?php

namespace Tests\Unit;

use App\Models\Department;
use App\Models\Teacher;
use App\Services\Plantilla\TeacherResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeacherResolverTest extends TestCase
{
    use RefreshDatabase;

    private function dept(): Department
    {
        $this->seed(\Database\Seeders\DepartmentSeeder::class);

        return Department::where('code', 'FIL')->firstOrFail();
    }

    public function test_normalizes_away_honorifics_initials_and_spacing(): void
    {
        $this->assertSame('cristie delos reyes', Teacher::normalize('Bb. Cristie R. Delos Reyes'));
        $this->assertSame('james ryan seneriches', Teacher::normalize('SCH. JAMES RYAN C. SENERICHES, SJ'));
        $this->assertSame('fritzie dealagdon', Teacher::normalize('  Fritzie   Dealagdon '));
    }

    public function test_reuses_the_same_teacher_across_spelling_variants(): void
    {
        $dept = $this->dept();
        $resolver = app(TeacherResolver::class);

        $first = $resolver->resolve('Fritzie Dealagdon', $dept);
        $second = $resolver->resolve('Frizie B. Dealagdon', $dept);
        $third = $resolver->resolve('  Fritzie  Dealagdon ', $dept);

        $this->assertSame($first->teacher->id, $second->teacher->id, 'a one-letter variant must not fork the teacher');
        $this->assertSame($first->teacher->id, $third->teacher->id);
        $this->assertSame(1, Teacher::count());
    }

    public function test_reports_when_it_reused_a_differently_spelled_record(): void
    {
        $dept = $this->dept();
        $resolver = app(TeacherResolver::class);
        $resolver->resolve('Fritzie Dealagdon', $dept);

        $again = $resolver->resolve('Frizie B. Dealagdon', $dept);

        $this->assertNotNull($again->reason);
        $this->assertStringContainsString('Fritzie Dealagdon', $again->reason);
    }

    public function test_distinct_people_sharing_a_surname_stay_separate(): void
    {
        $dept = $this->dept();
        $resolver = app(TeacherResolver::class);

        $a = $resolver->resolve('Cristie R. Delos Reyes', $dept);
        $b = $resolver->resolve('Ivy Q. Delos Reyes', $dept);

        $this->assertNotSame($a->teacher->id, $b->teacher->id);
        $this->assertSame(2, Teacher::count());
    }

    public function test_adopts_a_registrar_seeded_teacher_and_sets_their_department(): void
    {
        // The registrar list names people before any plantilla arrives, so they
        // exist with no department until their sheet is imported.
        $dept = $this->dept();
        $seeded = Teacher::create(['full_name' => 'Angelica M Singson', 'source' => 'registrar']);

        $resolved = app(TeacherResolver::class)->resolve('Angelica M. Singson', $dept);

        $this->assertSame($seeded->id, $resolved->teacher->id);
        $this->assertSame($dept->id, $resolved->teacher->fresh()->department_id);
        $this->assertSame(1, Teacher::count());
    }

    /**
     * Every one of these pairs is the same person written two ways — the
     * registrar's roster on the left, a plantilla sheet on the right. All were
     * found duplicating during an end-to-end import.
     *
     * @dataProvider samePersonWrittenTwoWays
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('samePersonWrittenTwoWays')]
    public function test_registrar_staff_are_adopted_despite_spelling_differences(string $registrar, string $sheet): void
    {
        $dept = $this->dept();
        Teacher::create(['full_name' => $registrar, 'source' => 'registrar']);

        $resolved = app(TeacherResolver::class)->resolve($sheet, $dept);

        $this->assertSame(1, Teacher::count(), "\"{$sheet}\" should adopt \"{$registrar}\", not duplicate it");
        $this->assertSame($dept->id, $resolved->teacher->fresh()->department_id);
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

    public function test_an_uncertain_surname_match_is_reported_rather_than_merged(): void
    {
        // "Jolapong" vs "Japalong" is three edits — plausibly the same person,
        // but not certain enough to merge two staff records automatically.
        $dept = $this->dept();
        Teacher::create(['full_name' => 'Gia Nicole F. Jolapong', 'source' => 'registrar']);

        $resolved = app(TeacherResolver::class)->resolve('Gia Nicole Japalong', $dept);

        $this->assertSame(2, Teacher::count());
        $this->assertNotNull($resolved->reason);
        $this->assertStringContainsString('Jolapong', $resolved->reason);
    }

    public function test_different_people_who_share_a_surname_are_never_merged(): void
    {
        $dept = $this->dept();
        Teacher::create(['full_name' => 'Cristie R. Delos Reyes', 'source' => 'registrar']);
        Teacher::create(['full_name' => 'Francheska June Naomi A. Francisco', 'source' => 'registrar']);

        app(TeacherResolver::class)->resolve('Ivy Q. Delos Reyes', $dept);
        app(TeacherResolver::class)->resolve('Mary Ann A. Francisco', $dept);

        $this->assertSame(4, Teacher::count());
    }

    public function test_a_teacher_from_another_department_is_not_reused(): void
    {
        $this->seed(\Database\Seeders\DepartmentSeeder::class);
        $fil = Department::where('code', 'FIL')->firstOrFail();
        $cle = Department::where('code', 'CLE')->firstOrFail();
        $resolver = app(TeacherResolver::class);

        $a = $resolver->resolve('Dave M. Natividad', $cle);
        $b = $resolver->resolve('Dave M. Natividad', $fil);

        $this->assertNotSame($a->teacher->id, $b->teacher->id);
        $this->assertNotNull($b->reason, 'a same-named teacher in another department should be reported');
    }
}
