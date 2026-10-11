<?php

namespace App\Services\Scheduling;

use App\Enums\TimetableKind;

final class ScheduleConstraintValidator
{
    /**
     * @param  list<array{id:int, grade_level:string, name?:string}>  $sections
     * @param  list<array{section_id:int, department_code?:string, teacher_id?:int|null, day:int, period:int}>  $entries
     * @param  list<array{teacher_id:int, day:int, period:int}>  $unavailable
     * @return list<array<string, mixed>>
     */
    public function validate(array $sections, array $entries, array $unavailable = [], TimetableKind $kind = TimetableKind::Normal): array
    {
        $issues = [];
        $sectionsById = [];
        foreach ($sections as $section) {
            $sectionsById[(int) $section['id']] = $section;
        }

        $cells = [];
        $subjectPeriods = [];
        $teacherSlots = [];
        $teacherDays = [];
        $availability = [];

        foreach ($unavailable as $slot) {
            $availability[$slot['teacher_id'].':'.$slot['day'].':'.$slot['period']] = true;
        }

        foreach ($entries as $entry) {
            $sectionId = (int) $entry['section_id'];
            $day = (int) $entry['day'];
            $period = (int) $entry['period'];
            $code = $entry['department_code'] ?? null;
            $teacherId = isset($entry['teacher_id']) ? (int) $entry['teacher_id'] : null;

            if (! isset($sectionsById[$sectionId])) {
                $issues[] = $this->issue('unknown_section', 'The timetable contains a section outside this school year.', ['section_id' => $sectionId]);

                continue;
            }
            if ($day < 1 || $day > SchedulePolicy::DAYS || $period < 1 || $period > $kind->periodsPerDay()) {
                $issues[] = $this->issue('invalid_cell', 'The timetable contains an invalid cycle day or period.', ['section_id' => $sectionId, 'day' => $day, 'period' => $period]);

                continue;
            }

            $cellKey = $sectionId.':'.$day.':'.$period;
            $cells[$cellKey][] = $entry;
            if (! is_string($code) || ! array_key_exists($code, SchedulePolicy::periodCounts((string) $sectionsById[$sectionId]['grade_level']))) {
                $issues[] = $this->issue('unexpected_subject', 'The timetable contains a subject not configured for JHS.', ['section_id' => $sectionId, 'day' => $day, 'period' => $period]);
            } else {
                $subjectPeriods[$sectionId][$code][] = ['day' => $day, 'period' => $period];
            }

            if ($teacherId === null || $teacherId < 1) {
                $issues[] = $this->issue('unassigned_teacher', 'A scheduled subject has no assigned teacher.', ['section_id' => $sectionId, 'day' => $day, 'period' => $period]);
            } else {
                $teacherSlots[$teacherId][$day][$period][] = $sectionId;
                $teacherDays[$teacherId][$day][$period] = true;

                if (isset($availability[$teacherId.':'.$day.':'.$period])) {
                    $issues[] = $this->issue('teacher_unavailable', 'A teacher is scheduled during a slot marked unavailable.', [
                        'teacher_id' => $teacherId, 'section_id' => $sectionId, 'day' => $day, 'period' => $period,
                        'category' => 'availability',
                    ]);
                }
            }
        }

        foreach ($sectionsById as $sectionId => $section) {
            $gradeLevel = (string) $section['grade_level'];
            $periodCounts = $kind === TimetableKind::Special
                ? array_fill_keys(ScheduleReadinessService::SUBJECT_CODES, SchedulePolicy::DAYS)
                : SchedulePolicy::periodCounts($gradeLevel);

            for ($day = 1; $day <= SchedulePolicy::DAYS; $day++) {
                for ($period = 1; $period <= $kind->periodsPerDay(); $period++) {
                    $cellKey = $sectionId.':'.$day.':'.$period;
                    $cellCount = count($cells[$cellKey] ?? []);
                    if ($cellCount === 0) {
                        $slots = $kind->periodsPerDay() * SchedulePolicy::DAYS;
                        $issues[] = $this->issue('missing_cell', "Every section must have a subject in each of its {$slots} cycle slots.", ['section_id' => $sectionId, 'day' => $day, 'period' => $period]);
                    } elseif ($cellCount > 1) {
                        $issues[] = $this->issue('section_slot_conflict', 'A section has more than one subject in the same cycle slot.', ['section_id' => $sectionId, 'day' => $day, 'period' => $period]);
                    }
                }
            }

            foreach ($periodCounts as $code => $expectedCount) {
                $actual = $subjectPeriods[$sectionId][$code] ?? [];
                if (count($actual) !== $expectedCount) {
                    $issues[] = $this->issue('subject_period_count', "{$code} must occupy {$expectedCount} periods per cycle for this grade.", [
                        'section_id' => $sectionId, 'subject_code' => $code, 'expected' => $expectedCount, 'actual' => count($actual),
                    ]);
                }
                if ($kind === TimetableKind::Special) {
                    $dailyCounts = array_count_values(array_column($actual, 'day'));
                    foreach (range(1, SchedulePolicy::DAYS) as $day) {
                        if (($dailyCounts[$day] ?? 0) !== 1) {
                            $issues[] = $this->issue('special_subject_daily_count', "{$code} must meet each section exactly once per day in a special schedule.", ['section_id' => $sectionId, 'subject_code' => $code, 'day' => $day]);
                        }
                    }
                } elseif (SchedulePolicy::allowsDoubleBlock($gradeLevel, $code)) {
                    $issues = array_merge($issues, $this->validateBlockPattern($sectionId, $gradeLevel, $code, $actual));
                } else {
                    $dailyCounts = array_count_values(array_column($actual, 'day'));
                    foreach ($dailyCounts as $day => $count) {
                        if ($count <= 1) {
                            continue;
                        }
                        $issues[] = $this->issue(
                            'subject_repeated_same_day',
                            "{$code} may meet a section only once per cycle day.",
                            [
                                'section_id' => $sectionId,
                                'subject_code' => $code,
                                'day' => (int) $day,
                                'actual' => $count,
                            ],
                        );
                    }
                }
            }
            if ($kind === TimetableKind::Special) {
                foreach (range(2, SchedulePolicy::DAYS) as $day) {
                    foreach (range(1, $kind->periodsPerDay()) as $period) {
                        if (($cells[$sectionId.':1:'.$period][0]['department_code'] ?? null) !== ($cells[$sectionId.':'.$day.':'.$period][0]['department_code'] ?? null)) {
                            $issues[] = $this->issue('special_daily_order', 'The special subject order must be the same on Day 1–5.', ['section_id' => $sectionId, 'day' => $day]);
                            break;
                        }
                    }
                }
            }
        }

        foreach ($teacherSlots as $teacherId => $days) {
            foreach ($days as $day => $periods) {
                foreach ($periods as $period => $sectionIds) {
                    if (count($sectionIds) > 1) {
                        $issues[] = $this->issue('teacher_conflict', 'A teacher is scheduled to teach more than one section at the same time.', [
                            'teacher_id' => (int) $teacherId, 'section_ids' => array_values(array_unique($sectionIds)),
                            'day' => (int) $day, 'period' => (int) $period, 'category' => 'teacher_conflict',
                        ]);
                    }
                }
            }

            foreach ($kind === TimetableKind::Normal ? $teacherDays : [] as $id => $days) {
                if ((int) $id !== (int) $teacherId) {
                    continue;
                }
                foreach ($days as $day => $periods) {
                    if (isset($periods[2], $periods[3]) || isset($periods[5], $periods[6]) || isset($periods[3], $periods[4], $periods[5])) {
                        $issues[] = $this->issue('teacher_break', 'A teacher must have a free period next to recess and lunch and may not teach three consecutive periods.', [
                            'teacher_id' => (int) $teacherId, 'day' => (int) $day, 'category' => 'teacher_rule',
                        ]);
                    }
                }
            }
        }

        return $issues;
    }

    /** @param list<array{day:int, period:int}> $periods
     * @return list<array<string, mixed>>
     */
    private function validateBlockPattern(int $sectionId, string $gradeLevel, string $code, array $periods): array
    {
        $byDay = [];
        foreach ($periods as $entry) {
            $byDay[$entry['day']][] = $entry['period'];
        }
        foreach ($byDay as &$dayPeriods) {
            sort($dayPeriods);
        }
        unset($dayPeriods);

        $expectedShape = SchedulePolicy::requiredDailyShape($gradeLevel, $code);
        $actualShape = array_map('count', array_values($byDay));
        sort($expectedShape);
        sort($actualShape);

        if (count($byDay) !== 3 || $actualShape !== $expectedShape) {
            return [$this->issue('block_pattern', "{$code} must use the required double-block and single-period pattern across three distinct days.", [
                'section_id' => $sectionId, 'subject_code' => $code,
            ])];
        }

        foreach ($byDay as $dayPeriods) {
            if (count($dayPeriods) !== 2) {
                continue;
            }
            $valid = false;
            foreach (SchedulePolicy::DOUBLE_BLOCKS as $block) {
                if ($dayPeriods === $block) {
                    $valid = true;
                    break;
                }
            }
            if (! $valid) {
                return [$this->issue('invalid_double_block', "{$code} double periods must be adjacent and may not cross recess or lunch.", [
                    'section_id' => $sectionId, 'subject_code' => $code,
                ])];
            }
        }

        return [];
    }

    private function issue(string $code, string $message, array $context = []): array
    {
        $category = $context['category'] ?? 'section_rule';
        unset($context['category']);

        return [
            'code' => $code,
            'severity' => 'hard',
            'category' => $category,
            'message' => $message,
        ] + $context;
    }
}
