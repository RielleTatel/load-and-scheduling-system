<?php

namespace Tests\Unit;

use App\Enums\TimetableKind;
use App\Services\Scheduling\ScheduleConstraintValidator;
use App\Services\Scheduling\ScheduleInfeasibleException;
use App\Services\Scheduling\ScheduleReadinessService;
use App\Services\Scheduling\SpecialScheduleGenerator;
use App\Services\Scheduling\TimetableLayout;
use PHPUnit\Framework\TestCase;

class SpecialScheduleTest extends TestCase
{
    public function test_it_generates_all_grades_with_shared_teachers_and_identical_daily_order(): void
    {
        $sections = $this->sections(36);
        $result = (new SpecialScheduleGenerator)->generate($sections, [], 73);
        $this->assertCount(1440, $result['entries']);
        $this->assertSame([], $result['issues']);
        $this->assertSame([], (new ScheduleConstraintValidator)->validate($sections, $result['entries'], [], TimetableKind::Special));
        foreach ($sections as $section) {
            $days = [];
            foreach ($result['entries'] as $entry) {
                if ($entry['section_id'] === $section['id']) {
                    $days[$entry['day']][$entry['period']] = $entry['department_code'];
                }
            }
            $this->assertCount(8, array_unique($days[1]));
            foreach (range(2, 5) as $day) {
                $this->assertSame($days[1], $days[$day]);
            }
        }
    }

    public function test_unavailability_on_one_day_applies_to_the_repeating_order(): void
    {
        $sections = $this->sections(4);
        $unavailable = [['teacher_id' => 1, 'day' => 3, 'period' => 8], ['teacher_id' => 1, 'day' => 2, 'period' => 1]];
        $result = (new SpecialScheduleGenerator)->generate($sections, $unavailable, 44);
        foreach ($result['entries'] as $entry) {
            if ($entry['teacher_id'] === 1) {
                $this->assertNotContains($entry['period'], [1, 8]);
            }
        }
        $this->assertSame([], (new ScheduleConstraintValidator)->validate($sections, $result['entries'], $unavailable, TimetableKind::Special));
    }

    public function test_provably_insufficient_available_periods_are_reported(): void
    {
        $slots = array_map(fn ($period) => ['teacher_id' => 1, 'day' => 1, 'period' => $period], range(1, 5));
        $this->expectException(ScheduleInfeasibleException::class);
        (new SpecialScheduleGenerator)->generate($this->sections(4), $slots, 44);
    }

    public function test_special_validation_rejects_repeated_subjects_and_changed_daily_order(): void
    {
        $sections = $this->sections(1);
        $entries = (new SpecialScheduleGenerator)->generate($sections, [], 5)['entries'];
        $entries[0]['department_code'] = $entries[1]['department_code'];
        $issues = (new ScheduleConstraintValidator)->validate($sections, $entries, [], TimetableKind::Special);
        $this->assertContains('special_subject_daily_count', array_column($issues, 'code'));
        $this->assertContains('special_daily_order', array_column($issues, 'code'));
    }

    public function test_special_validation_keeps_teacher_conflicts_but_not_normal_break_rules(): void
    {
        $sections = $this->sections(2);
        $entries = (new SpecialScheduleGenerator)->generate($sections, [], 5)['entries'];
        foreach ($entries as &$entry) {
            $entry['teacher_id'] = 99;
        }
        unset($entry);
        $issues = (new ScheduleConstraintValidator)->validate($sections, $entries, [], TimetableKind::Special);
        $this->assertContains('teacher_conflict', array_column($issues, 'code'));
        $this->assertNotContains('teacher_break', array_column($issues, 'code'));
        $this->assertNotContains('block_pattern', array_column($issues, 'code'));
    }

    public function test_special_bells_match_the_sample_and_have_no_remediation(): void
    {
        $rows = (new TimetableLayout)->rows(1, TimetableKind::Special);
        $this->assertCount(13, $rows);
        $subjects = array_values(array_filter($rows, fn ($row) => $row['type'] === 'subject'));
        $this->assertCount(8, $subjects);
        $this->assertSame('3:00 - 3:50', $subjects[7]['time']);
        $this->assertSame(['3:50 - 3:55', '3:55 - 4:10', '4:10'], array_slice(array_column($rows, 'time'), -3));
        $this->assertSame(['EXAMEN', 'CLASSROOM CLEAN-UP (CC)', 'DISMISSAL'], array_slice(array_column($rows, 'label'), -3));
    }

    private function sections(int $count): array
    {
        $sections = [];
        foreach (range(1, $count) as $id) {
            $assignments = [];
            foreach (ScheduleReadinessService::SUBJECT_CODES as $index => $code) {
                $assignments[$code] = ['department_id' => $index + 1, 'teacher_id' => $index * 100 + (int) ceil($id / 4)];
            }
            $sections[] = ['id' => $id, 'grade_level' => 'G'.(7 + (int) floor(($id - 1) / 9)), 'assignments' => $assignments];
        }

        return $sections;
    }
}
