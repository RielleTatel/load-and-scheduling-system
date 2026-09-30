<?php

namespace Tests\Unit;

use App\Services\Scheduling\ScheduleConstraintValidator;
use App\Services\Scheduling\ScheduleGenerationEngine;
use App\Services\Scheduling\ScheduleReadinessService;
use PHPUnit\Framework\TestCase;

class ScheduleGenerationEngineTest extends TestCase
{
    public function test_it_returns_a_complete_zero_issue_candidate_for_feasible_sections(): void
    {
        $result = (new ScheduleGenerationEngine(new ScheduleConstraintValidator))
            ->generate($this->sections(), [], 12345, 1);

        $this->assertCount(70, $result['entries']);
        $this->assertGreaterThanOrEqual(1, $result['attempts']);
        $this->assertSame([], $result['issues']);
        $this->assertSame([0, 0, 0], $result['score']);
    }

    public function test_a_saved_seed_reproduces_the_same_candidate_for_the_same_inputs(): void
    {
        $engine = new ScheduleGenerationEngine(new ScheduleConstraintValidator);
        $sections = [$this->sections()[0]];

        $first = $engine->generate($sections, [], 839, 1);
        $second = $engine->generate($sections, [], 839, 1);

        $this->assertSame($first['entries'], $second['entries']);
        $this->assertSame([], $first['issues']);
    }

    public function test_it_rejects_an_impossible_assignment_instead_of_returning_conflicts(): void
    {
        $assignments = [];
        foreach (ScheduleReadinessService::SUBJECT_CODES as $index => $code) {
            $assignments[$code] = ['department_id' => $index + 1, 'teacher_id' => 1];
        }
        $sections = [
            ['id' => 1, 'grade_level' => 'G7', 'name' => 'Ignatius', 'assignments' => $assignments],
            ['id' => 2, 'grade_level' => 'G7', 'name' => 'Xavier', 'assignments' => $assignments],
        ];

        try {
            (new ScheduleGenerationEngine(new ScheduleConstraintValidator))->generate($sections, [], 44, 1);
            $this->fail('Impossible inputs must not return a conflicted timetable.');
        } catch (\Throwable $exception) {
            $this->assertSame(
                'App\\Services\\Scheduling\\ScheduleSearchExhaustedException',
                $exception::class,
            );
        }
    }

    public function test_it_respects_teacher_unavailability_without_introducing_other_hard_errors(): void
    {
        $section = $this->sections()[0];
        $result = (new ScheduleGenerationEngine(new ScheduleConstraintValidator))->generate(
            [$section],
            [['teacher_id' => 1, 'day' => 1, 'period' => 1]],
            451,
            1,
        );

        $this->assertSame([], $result['issues']);
        $this->assertNotContains(
            [1, 1, 1],
            array_map(fn (array $entry) => [$entry['teacher_id'], $entry['day'], $entry['period']], $result['entries']),
        );
    }

    private function sections(): array
    {
        $firstAssignments = [];
        $secondAssignments = [];
        foreach (ScheduleReadinessService::SUBJECT_CODES as $index => $code) {
            $firstAssignments[$code] = ['department_id' => $index + 1, 'teacher_id' => $index + 1];
            $secondAssignments[$code] = ['department_id' => $index + 1, 'teacher_id' => $index + 101];
        }
        $secondAssignments['ENG']['teacher_id'] = $firstAssignments['ENG']['teacher_id'];

        return [
            ['id' => 1, 'grade_level' => 'G7', 'name' => 'Ignatius', 'assignments' => $firstAssignments],
            ['id' => 2, 'grade_level' => 'G10', 'name' => 'Xavier', 'assignments' => $secondAssignments],
        ];
    }
}
