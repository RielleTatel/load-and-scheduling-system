<?php

namespace Tests\Unit;

use App\Services\Scheduling\ScheduleConstraintValidator;
use App\Services\Scheduling\ScheduleTemplateGenerator;
use PHPUnit\Framework\TestCase;

class ScheduleConstraintValidatorTest extends TestCase
{
    public function test_it_reports_a_missing_period_in_a_section_day(): void
    {
        $entries = $this->entriesForTwoSections();
        $entries = array_values(array_filter($entries, fn (array $entry) => ! (
            $entry['section_id'] === 1 && $entry['day'] === 1 && $entry['period'] === 1
        )));

        $issues = $this->validate($entries);

        $this->assertContains('missing_cell', array_column($issues, 'code'));
    }

    public function test_it_reports_teacher_double_booking(): void
    {
        $issues = $this->validate($this->entriesForTwoSections());

        $this->assertContains('teacher_conflict', array_column($issues, 'code'));
    }

    public function test_it_reports_quarter_availability_conflicts(): void
    {
        $entries = $this->entriesForTwoSections();
        $entries = array_values(array_filter($entries, fn (array $entry) => $entry['section_id'] === 1));
        $slot = $entries[0];
        $issues = (new ScheduleConstraintValidator)->validate(
            $this->sections(),
            $entries,
            [['teacher_id' => $slot['teacher_id'], 'day' => $slot['day'], 'period' => $slot['period']]],
        );

        $this->assertContains('teacher_unavailable', array_column($issues, 'code'));
    }

    public function test_it_reports_a_teacher_scheduled_on_both_sides_of_recess(): void
    {
        $template = (new ScheduleTemplateGenerator(12))->build('G7');
        $entries = [];
        foreach ($template as $index => $entry) {
            $entry['section_id'] = 1;
            $entry['teacher_id'] = $entry['day'] === 1 && in_array($entry['period'], [2, 3], true)
                ? 900
                : 1000 + $index;
            $entries[] = $entry;
        }

        $issues = (new ScheduleConstraintValidator)->validate([$this->sections()[0]], $entries);

        $this->assertContains('teacher_break', array_column($issues, 'code'));
    }

    public function test_it_reports_a_subject_that_returns_to_the_same_section_later_that_day(): void
    {
        $template = (new ScheduleTemplateGenerator(12))->build('G7');
        $entries = [];
        foreach ($template as $index => $entry) {
            $entry['section_id'] = 1;
            $entry['teacher_id'] = 1000 + $index;
            $entries[] = $entry;
        }

        $dayOneMath = array_key_first(array_filter(
            $entries,
            fn (array $entry): bool => $entry['day'] === 1 && $entry['department_code'] === 'MATH',
        ));
        $dayTwoScience = array_key_first(array_filter(
            $entries,
            fn (array $entry): bool => $entry['day'] === 2 && $entry['department_code'] === 'SCI',
        ));
        $entries[$dayOneMath]['department_code'] = 'SCI';
        $entries[$dayTwoScience]['department_code'] = 'MATH';

        $issues = (new ScheduleConstraintValidator)->validate([$this->sections()[0]], $entries);
        $repeats = array_values(array_filter(
            $issues,
            fn (array $issue): bool => $issue['code'] === 'subject_repeated_same_day',
        ));

        $this->assertCount(2, $repeats);
        $this->assertContains(
            ['SCI', 1],
            array_map(fn (array $issue): array => [$issue['subject_code'], $issue['day']], $repeats),
        );
        $this->assertContains(
            ['MATH', 2],
            array_map(fn (array $issue): array => [$issue['subject_code'], $issue['day']], $repeats),
        );
    }

    private function validate(array $entries): array
    {
        return (new ScheduleConstraintValidator)->validate($this->sections(), $entries);
    }

    private function sections(): array
    {
        return [
            ['id' => 1, 'grade_level' => 'G7', 'name' => 'Ignatius'],
            ['id' => 2, 'grade_level' => 'G7', 'name' => 'Xavier'],
        ];
    }

    private function entriesForTwoSections(): array
    {
        $template = (new ScheduleTemplateGenerator(12))->build('G7');
        $entries = [];

        foreach ([1, 2] as $sectionId) {
            foreach ($template as $entry) {
                $entry['section_id'] = $sectionId;
                $entry['teacher_id'] = match ($entry['department_code']) {
                    'ENG' => 1,
                    'MATH' => 2,
                    'SCI' => 3,
                    'CLE' => 4,
                    'FIL' => 5,
                    'SOC' => 6,
                    'TLE' => 7,
                    'MAPEH' => 8,
                };
                $entries[] = $entry;
            }
        }

        return $entries;
    }
}
