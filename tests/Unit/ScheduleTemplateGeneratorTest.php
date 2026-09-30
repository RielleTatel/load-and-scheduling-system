<?php

namespace Tests\Unit;

use App\Services\Scheduling\ScheduleConstraintValidator;
use App\Services\Scheduling\ScheduleTemplateGenerator;
use PHPUnit\Framework\TestCase;

class ScheduleTemplateGeneratorTest extends TestCase
{
    public function test_it_builds_a_complete_normal_cycle_for_grades_seven_to_nine(): void
    {
        $entries = (new ScheduleTemplateGenerator(20260930))->build('G7');

        $this->assertScheduleShape($entries, 'G7');
        $issues = (new ScheduleConstraintValidator)->validate(
            [['id' => 1, 'grade_level' => 'G7', 'name' => 'Ignatius']],
            $this->withTeachers($entries),
        );

        $this->assertSame([], $issues);
    }

    public function test_it_builds_grade_ten_english_with_two_doubles_and_one_single(): void
    {
        $entries = (new ScheduleTemplateGenerator(42))->build('G10');

        $this->assertScheduleShape($entries, 'G10');
        $englishByDay = $this->periodsByDay($entries, 'ENG');
        $englishDays = array_values(array_filter($englishByDay));

        $this->assertCount(3, $englishDays);
        $this->assertEqualsCanonicalizing([1, 2, 2], array_map('count', $englishDays));

        $issues = (new ScheduleConstraintValidator)->validate(
            [['id' => 1, 'grade_level' => 'G10', 'name' => 'Ignatius']],
            $this->withTeachers($entries),
        );

        $this->assertSame([], $issues);
    }

    public function test_it_can_build_a_template_around_required_teacher_slots(): void
    {
        $entries = (new ScheduleTemplateGenerator(91))->buildMatching(
            'G7',
            fn (string $code, int $day, int $period): bool => ! ($code === 'ENG' && $day === 1 && $period === 1),
            20,
        );

        $this->assertNotNull($entries);
        $this->assertScheduleShape($entries, 'G7');
        $this->assertNotSame(1, $this->periodsByDay($entries, 'ENG')[1][0]);
    }

    private function assertScheduleShape(array $entries, string $grade): void
    {
        $this->assertCount(35, $entries);
        $this->assertCount(35, array_unique(array_map(
            fn (array $entry) => $entry['day'].'-'.$entry['period'],
            $entries,
        )));

        $expected = [
            'ENG' => 5,
            'MATH' => 5,
            'SCI' => 5,
            'CLE' => 4,
            'FIL' => 4,
            'SOC' => 4,
            'TLE' => 4,
            'MAPEH' => 4,
        ];

        foreach ($expected as $code => $count) {
            $this->assertCount($count, array_filter(
                $entries,
                fn (array $entry) => $entry['department_code'] === $code,
            ), "Unexpected {$code} frequency for {$grade}.");
        }

        for ($day = 1; $day <= 5; $day++) {
            $dayPeriods = array_column(array_filter($entries, fn (array $entry) => $entry['day'] === $day), 'period');
            sort($dayPeriods);
            $this->assertSame(range(1, 7), $dayPeriods);
        }
    }

    private function withTeachers(array $entries): array
    {
        $teacherIds = ['ENG' => 1, 'MATH' => 2, 'SCI' => 3, 'CLE' => 4, 'FIL' => 5, 'SOC' => 6, 'TLE' => 7, 'MAPEH' => 8];

        return array_map(function (array $entry) use ($teacherIds): array {
            $entry['section_id'] = 1;
            $entry['teacher_id'] = $teacherIds[$entry['department_code']];

            return $entry;
        }, $entries);
    }

    private function periodsByDay(array $entries, string $code): array
    {
        $periods = [];
        foreach ($entries as $entry) {
            if ($entry['department_code'] === $code) {
                $periods[$entry['day']][] = $entry['period'];
            }
        }

        return $periods;
    }
}
