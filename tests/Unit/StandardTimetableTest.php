<?php

namespace Tests\Unit;

use App\Services\Scheduling\ScheduleConstraintValidator;
use App\Services\Scheduling\ScheduleGenerationEngine;
use App\Services\Scheduling\SchedulePolicy;
use App\Services\Scheduling\StandardTimetable;
use Tests\TestCase;

class StandardTimetableTest extends TestCase
{
    public function test_all_workbook_section_patterns_satisfy_the_agreed_subject_rules(): void
    {
        $reference = json_decode(file_get_contents(resource_path('data/jhs-standard-timetable.json')), true);
        $sections = [];
        foreach ($reference['grades'] as $grade => $names) {
            foreach ($names as $name => $days) {
                $id = count($sections) + 1;
                $assignments = [];
                foreach (array_keys(SchedulePolicy::periodCounts($grade)) as $index => $code) {
                    $assignments[$code] = ['teacher_id' => $id * 100 + $index, 'department_id' => $index + 1];
                }
                $sections[] = ['id' => $id, 'grade_level' => $grade, 'name' => $name, 'assignments' => $assignments];
            }
        }
        $this->assertCount(36, $sections);
        $entries = app(StandardTimetable::class)->entries($sections);
        $this->assertCount(1260, $entries);
        $result = app(ScheduleGenerationEngine::class)->generate($sections, [], 7, 1, null, $entries);
        $this->assertSame([], app(ScheduleConstraintValidator::class)->validate($sections, $result['entries'], []));
        $this->assertSame(1, $result['attempts']);
        $this->assertSame('TLE', $result['entries'][0]['department_code']);
        $this->assertSame(106, $result['entries'][0]['teacher_id']);
    }

    public function test_only_explicit_section_aliases_are_mapped_and_unknown_sections_are_not_guessed(): void
    {
        $sections = [
            ['id' => 1, 'grade_level' => 'G7', 'name' => 'De Britto'],
            ['id' => 2, 'grade_level' => 'G8', 'name' => 'Ignatius of Loyola'],
            ['id' => 3, 'grade_level' => 'G7', 'name' => 'Unknown'],
        ];
        $entries = app(StandardTimetable::class)->entries($sections);
        $this->assertCount(70, $entries);
        $this->assertSame([1, 2], array_values(array_unique(array_column($entries, 'section_id'))));
    }
}
