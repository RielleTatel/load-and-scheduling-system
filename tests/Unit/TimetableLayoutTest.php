<?php

namespace Tests\Unit;

use App\Enums\GradeLevel;
use App\Models\Section;
use App\Services\Scheduling\TimetableLayout;
use PHPUnit\Framework\TestCase;

class TimetableLayoutTest extends TestCase
{
    public function test_it_exposes_the_workbook_grade_order_and_full_subject_names(): void
    {
        $layout = $this->layout();

        $this->assertSame(['G7', 'G8', 'G9', 'G10'], $layout->gradeOrder());
        $this->assertSame('English', $layout->subjectName('ENG'));
        $this->assertSame('Mathematics', $layout->subjectName('MATH'));
        $this->assertSame('Science and Technology', $layout->subjectName('SCI'));
        $this->assertSame('CLE', $layout->subjectName('CLE', 'Christian Life Education'));
        $this->assertSame('TLE', $layout->subjectName('TLE', 'Technology and Livelihood Education'));
        $this->assertSame('Music, Arts, Physical Education and Health', $layout->subjectName('MAPEH'));
        $this->assertSame('Fallback Subject', $layout->subjectName('OTHER', 'Fallback Subject'));
    }

    public function test_it_places_breaks_and_post_class_rows_around_time_only_subject_rows(): void
    {
        $rows = $this->layout()->rows(1);

        $this->assertCount(12, $rows);
        $this->assertSame(
            ['subject', 'subject', 'break', 'subject', 'subject', 'subject', 'break', 'subject', 'subject', 'post_class', 'post_class', 'post_class'],
            array_column($rows, 'type'),
        );
        $this->assertSame(
            ['7:30 - 8:20', '8:20 - 9:10', '9:10 - 9:50', '9:50 - 10:40', '10:40 - 11:30', '11:30 - 12:20', '12:20 - 1:20', '1:20 - 2:10', '2:10 - 3:00', '3:00 - 3:05', '3:05 - 3:20', '3:20 - 4:10'],
            array_column($rows, 'time'),
        );
        $this->assertSame(['RECESS', 'LUNCH'], array_values(array_map(
            fn (array $row) => $row['label'],
            array_filter($rows, fn (array $row) => $row['type'] === 'break'),
        )));
        $this->assertSame(['EXAMEN', 'CLASSROOM CLEAN-UP (CC)', 'REMEDIATION (Science)'], array_slice(array_column($rows, 'label'), -3));
        $this->assertSame([1, 2, null, 3, 4, 5, null, 6, 7, null, null, null], array_column($rows, 'period'));
        foreach ($rows as $row) {
            $this->assertSame(['type', 'time', 'label', 'period'], array_keys($row));
        }
    }

    public function test_it_groups_only_present_grades_and_sorts_sections_by_grade_then_name(): void
    {
        $g10 = new Section(['grade_level' => GradeLevel::G10, 'name' => 'Xavier']);
        $g7B = new Section(['grade_level' => GradeLevel::G7, 'name' => 'Bellarmine']);
        $g7A = new Section(['grade_level' => GradeLevel::G7, 'name' => 'Arrowsmith']);

        $groups = $this->layout()->groupSections(collect([$g10, $g7B, $g7A]));

        $this->assertSame(['G7', 'G10'], array_keys($groups));
        $this->assertSame(['Arrowsmith', 'Bellarmine'], $groups['G7']->pluck('name')->all());
        $this->assertSame(['Xavier'], $groups['G10']->pluck('name')->all());
    }

    private function layout(): TimetableLayout
    {
        if (! class_exists(TimetableLayout::class)) {
            $this->fail('TimetableLayout has not been implemented.');
        }

        return new TimetableLayout;
    }
}
