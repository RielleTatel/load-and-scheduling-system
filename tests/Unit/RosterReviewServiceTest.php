<?php

namespace Tests\Unit;

use App\Models\RosterImport;
use App\Models\Section;
use App\Models\Teacher;
use App\Services\Roster\RosterReviewService;
use Database\Seeders\SystemConstantSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RosterReviewServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A distinct, purely alphabetic suffix ("AA".."BJ").
     *
     * Teacher::normalize() strips digits and drops single-character tokens, so
     * "Moderator 1" and "Moderator 2" are the same person as far as the
     * directory is concerned — a numeric fixture would collapse to one teacher.
     */
    private function suffix(int $n): string
    {
        return chr(65 + intdiv($n - 1, 26)) . chr(65 + (($n - 1) % 26));
    }

    /** Build an import of 36 valid rows, 9 per grade. */
    private function importWith(array $mutate = []): RosterImport
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
                    'row_json' => array_merge([
                        'grade_level' => $grade,
                        'full_name' => "Saint Section {$n}",
                        'name' => "Section{$n}",
                        'room' => (string) (100 + $n),
                        'is_magis' => false,
                        'moderator_name' => 'Moderator ' . $this->suffix($n),
                        'teacher_partner_name' => 'Partner ' . $this->suffix($n),
                        'flagged' => false,
                        'flags' => [],
                    ], $mutate[$n] ?? []),
                    'row_status' => \App\Enums\ExtractionRowStatus::Extracted,
                ]);
            }
        }

        return $import;
    }

    public function test_imports_sections_for_the_imports_school_year(): void
    {
        $this->seed(SystemConstantSeeder::class);

        $result = app(RosterReviewService::class)->confirmImport($this->importWith());

        $this->assertSame(36, $result['imported']);
        $this->assertSame([], $result['errors']);
        $this->assertSame(36, Section::where('school_year', '2027-2028')->count());
    }

    public function test_adopts_moderators_and_partners_as_teachers(): void
    {
        $this->seed(SystemConstantSeeder::class);

        app(RosterReviewService::class)->confirmImport($this->importWith());

        $this->assertSame(72, Teacher::count()); // 36 moderators + 36 partners
    }

    public function test_does_not_duplicate_an_existing_teacher(): void
    {
        $this->seed(SystemConstantSeeder::class);
        Teacher::create(['full_name' => 'Moderator AA', 'department_id' => null]);

        app(RosterReviewService::class)->confirmImport($this->importWith());

        $this->assertSame(1, Teacher::where('normalized_name', Teacher::normalize('Moderator AA'))->count());
    }

    public function test_refuses_when_a_grade_does_not_have_nine_sections(): void
    {
        $this->seed(SystemConstantSeeder::class);
        $import = $this->importWith([1 => ['grade_level' => 'G8']]); // G7 now has 8, G8 has 10

        $result = app(RosterReviewService::class)->confirmImport($import);

        $this->assertSame(0, $result['imported']);
        $this->assertNotEmpty($result['errors']);
        $this->assertSame(0, Section::where('school_year', '2027-2028')->count());
    }

    public function test_refuses_on_a_duplicate_short_name(): void
    {
        $this->seed(SystemConstantSeeder::class);
        $import = $this->importWith([2 => ['name' => 'Section1']]);

        $result = app(RosterReviewService::class)->confirmImport($import);

        $this->assertSame(0, $result['imported']);
        $this->assertStringContainsString('Section1', implode(' ', $result['errors']));
    }

    public function test_refuses_on_a_missing_room(): void
    {
        $this->seed(SystemConstantSeeder::class);
        $import = $this->importWith([3 => ['room' => null]]);

        $result = app(RosterReviewService::class)->confirmImport($import);

        $this->assertSame(0, $result['imported']);
        $this->assertNotEmpty($result['errors']);
    }
}
