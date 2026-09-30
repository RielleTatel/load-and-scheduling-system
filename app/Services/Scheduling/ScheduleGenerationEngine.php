<?php

namespace App\Services\Scheduling;

use InvalidArgumentException;

final class ScheduleGenerationEngine
{
    private const MAX_TEMPLATES_PER_GRADE = 320;

    private const MIN_TEMPLATES_PER_GRADE = 96;

    private const MRV_LOOKAHEAD = 8;

    private const CANDIDATES_PER_SECTION = 24;

    public function __construct(private readonly ScheduleConstraintValidator $validator) {}

    /**
     * @param list<array{id:int, grade_level:string, name:string, assignments:array<string, array{department_id:int, teacher_id:int}>}> $sections
     * @param list<array{teacher_id:int, day:int, period:int}> $unavailable
     * @return array{entries:list<array<string, int|string>>, issues:list<array<string,mixed>>, score:list<int>, attempts:int}
     */
    public function generate(array $sections, array $unavailable, int $seed, int $seconds = 110): array
    {
        if ($sections === []) {
            throw new InvalidArgumentException('At least one section is required to generate a timetable.');
        }

        $deadline = microtime(true) + max(1, min($seconds, 110));
        $availability = [];
        foreach ($unavailable as $slot) {
            $availability[$slot['teacher_id'].':'.$slot['day'].':'.$slot['period']] = true;
        }

        $this->assertAssignmentsAreComplete($sections);
        $this->assertTeacherLoadsCanFit($sections);

        $teacherSectionCounts = $this->teacherSectionCounts($sections);
        usort($sections, function (array $left, array $right) use ($teacherSectionCounts): int {
            $leftPressure = $this->sectionPressure($left, $teacherSectionCounts);
            $rightPressure = $this->sectionPressure($right, $teacherSectionCounts);

            return [$rightPressure, (int) $left['id']] <=> [$leftPressure, (int) $right['id']];
        });

        $templatePools = $this->buildTemplatePools($sections, $seed, $deadline);
        $teacherSlots = [];
        $entries = [];
        $nodes = 0;
        $checks = 0;
        $remaining = array_keys($sections);

        $result = $this->search(
            $sections,
            $remaining,
            $templatePools,
            $availability,
            $teacherSlots,
            $entries,
            $deadline,
            $nodes,
            $checks,
            $seed,
        );

        if ($result !== null) {
            usort($result, fn (array $left, array $right): int => [
                $left['section_id'], $left['day'], $left['period'],
            ] <=> [
                $right['section_id'], $right['day'], $right['period'],
            ]);

            $issues = $this->validator->validate(array_map(fn (array $section): array => [
                'id' => $section['id'],
                'grade_level' => $section['grade_level'],
                'name' => $section['name'],
            ], $sections), $result, $unavailable);

            if ($issues === []) {
                return [
                    'entries' => $result,
                    'issues' => [],
                    'score' => [0, 0, 0],
                    'attempts' => max(1, $nodes),
                ];
            }
        }

        throw new ScheduleSearchExhaustedException(
            "No conflict-free timetable was found within the configured search limit ({$nodes} placements checked). No draft was created."
        );
    }

    /**
     * @param list<array{id:int, grade_level:string, name:string, assignments:array<string, array{department_id:int, teacher_id:int}>}> $sections
     * @param list<int> $remaining
     * @param array<string, list<list<array{day:int, period:int, department_code:string}>>> $templatePools
     * @param array<string, bool> $availability
     * @param array<int, array<int, array<int, bool>>> $teacherSlots
     * @param list<array<string, int|string>> $entries
     * @return list<array<string, int|string>>|null
     */
    private function search(
        array $sections,
        array $remaining,
        array $templatePools,
        array $availability,
        array &$teacherSlots,
        array &$entries,
        float $deadline,
        int &$nodes,
        int &$checks,
        int $seed,
    ): ?array {
        if ($remaining === []) {
            return $entries;
        }
        if (microtime(true) >= $deadline) {
            return null;
        }

        $selectedPosition = null;
        $selectedCandidates = [];
        $fewestCandidates = PHP_INT_MAX;
        $lookahead = min(self::MRV_LOOKAHEAD, count($remaining));

        for ($position = 0; $position < $lookahead; $position++) {
            $sectionIndex = $remaining[$position];
            $section = $sections[$sectionIndex];
            $candidates = $this->candidateTemplates(
                $section,
                $templatePools[$section['grade_level']],
                $teacherSlots,
                $availability,
                $seed,
                $nodes,
                $deadline,
                $checks,
            );

            if (count($candidates) < $fewestCandidates) {
                $selectedPosition = $position;
                $selectedCandidates = $candidates;
                $fewestCandidates = count($candidates);
            }
            if ($fewestCandidates === 0) {
                return null;
            }
        }

        if ($selectedPosition === null) {
            return null;
        }

        $sectionIndex = $remaining[$selectedPosition];
        $section = $sections[$sectionIndex];
        $nextRemaining = $remaining;
        array_splice($nextRemaining, $selectedPosition, 1);

        foreach ($selectedCandidates as $template) {
            if (microtime(true) >= $deadline) {
                return null;
            }

            if (! $this->canPlace($section, $template, $teacherSlots, $availability)) {
                continue;
            }

            $sectionEntries = $this->mapTemplate($section, $template);
            $entryOffset = count($entries);
            $this->occupy($sectionEntries, $teacherSlots);
            array_push($entries, ...$sectionEntries);
            $nodes++;

            $result = $this->search(
                $sections,
                $nextRemaining,
                $templatePools,
                $availability,
                $teacherSlots,
                $entries,
                $deadline,
                $nodes,
                $checks,
                $seed,
            );
            if ($result !== null) {
                return $result;
            }

            $this->release($sectionEntries, $teacherSlots);
            array_splice($entries, $entryOffset);
        }

        return null;
    }

    /**
     * @param list<list<array{day:int, period:int, department_code:string}>> $pool
     * @param array<int, array<int, array<int, bool>>> $occupied
     * @param array<string, bool> $availability
     * @return list<list<array{day:int, period:int, department_code:string}>>
     */
    private function candidateTemplates(
        array $section,
        array $pool,
        array $occupied,
        array $availability,
        int $seed,
        int $nodes,
        float $deadline,
        int &$checks,
    ): array {
        $candidates = [];
        $unique = [];

        foreach ($pool as $template) {
            $checks++;
            if ($this->canPlace($section, $template, $occupied, $availability)) {
                $key = implode('|', array_column($template, 'department_code'));
                $unique[$key] = true;
                $candidates[] = $template;
                if (count($candidates) >= self::CANDIDATES_PER_SECTION) {
                    return $candidates;
                }
            }
        }

        $occupancyKey = '';
        foreach ($occupied as $teacherId => $days) {
            foreach ($days as $day => $periods) {
                foreach (array_keys($periods) as $period) {
                    $occupancyKey .= $teacherId.':'.$day.':'.$period.';';
                }
            }
        }
        $derivedSeed = (int) crc32($seed.':'.$section['id'].':'.$nodes.':'.$occupancyKey);
        $generator = new ScheduleTemplateGenerator($derivedSeed);
        $builds = 0;

        while (count($candidates) < self::CANDIDATES_PER_SECTION && $builds < 80 && microtime(true) < $deadline) {
            $template = $generator->buildMatching(
                $section['grade_level'],
                function (string $code, int $day, int $period) use ($section, $occupied, $availability): bool {
                    $teacherId = (int) $section['assignments'][$code]['teacher_id'];
                    if (isset($occupied[$teacherId][$day][$period]) || isset($availability[$teacherId.':'.$day.':'.$period])) {
                        return false;
                    }

                    return ! $this->hasBreakViolation(($occupied[$teacherId][$day] ?? []) + [$period => true]);
                },
                12,
            );
            $builds++;
            if ($template === null || ! $this->canPlace($section, $template, $occupied, $availability)) {
                continue;
            }

            $key = implode('|', array_column($template, 'department_code'));
            if (isset($unique[$key])) {
                continue;
            }
            $unique[$key] = true;
            $candidates[] = $template;
        }

        return $candidates;
    }

    /**
     * @param list<array{id:int, grade_level:string, name:string, assignments:array<string, array{department_id:int, teacher_id:int}>}> $sections
     * @return array<string, list<list<array{day:int, period:int, department_code:string}>>>
     */
    private function buildTemplatePools(array $sections, int $seed, float $deadline): array
    {
        $grades = array_values(array_unique(array_column($sections, 'grade_level')));
        sort($grades);
        $generator = new ScheduleTemplateGenerator($seed);
        $pools = [];
        $limit = self::MAX_TEMPLATES_PER_GRADE;

        foreach ($grades as $grade) {
            $unique = [];
            $attempts = 0;
            while (count($unique) < $limit && $attempts < $limit * 3) {
                if (count($unique) >= self::MIN_TEMPLATES_PER_GRADE && microtime(true) >= $deadline - 0.05) {
                    break;
                }
                $template = $generator->build($grade);
                $key = implode('|', array_column($template, 'department_code'));
                $unique[$key] = $template;
                $attempts++;
            }
            $pools[$grade] = array_values($unique);
        }

        return $pools;
    }

    /** @param array<int, array<int, array<int, bool>>> $occupied */
    private function canPlace(array $section, array $template, array $occupied, array $availability): bool
    {
        $newSlots = [];
        foreach ($template as $entry) {
            $assignment = $section['assignments'][$entry['department_code']];
            $teacherId = (int) $assignment['teacher_id'];
            $day = (int) $entry['day'];
            $period = (int) $entry['period'];

            if (isset($occupied[$teacherId][$day][$period]) || isset($availability[$teacherId.':'.$day.':'.$period])) {
                return false;
            }
            $newSlots[$teacherId][$day][$period] = true;
        }

        foreach ($newSlots as $teacherId => $days) {
            foreach ($days as $day => $periods) {
                if ($this->hasBreakViolation(($occupied[$teacherId][$day] ?? []) + $periods)) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @param list<array<string, int|string>> $entries
     *  @param array<int, array<int, array<int, bool>>> $occupied
     */
    private function occupy(array $entries, array &$occupied): void
    {
        foreach ($entries as $entry) {
            $occupied[$entry['teacher_id']][$entry['day']][$entry['period']] = true;
        }
    }

    /** @param list<array<string, int|string>> $entries
     *  @param array<int, array<int, array<int, bool>>> $occupied
     */
    private function release(array $entries, array &$occupied): void
    {
        foreach ($entries as $entry) {
            unset($occupied[$entry['teacher_id']][$entry['day']][$entry['period']]);
        }
    }

    private function assertAssignmentsAreComplete(array $sections): void
    {
        foreach ($sections as $section) {
            foreach (array_keys(SchedulePolicy::periodCounts($section['grade_level'])) as $code) {
                if (! isset($section['assignments'][$code])) {
                    throw new InvalidArgumentException("Section {$section['name']} has no {$code} assignment.");
                }
            }
        }
    }

    private function assertTeacherLoadsCanFit(array $sections): void
    {
        $loads = [];
        foreach ($sections as $section) {
            foreach (SchedulePolicy::periodCounts($section['grade_level']) as $code => $periods) {
                $teacherId = (int) $section['assignments'][$code]['teacher_id'];
                $loads[$teacherId] = ($loads[$teacherId] ?? 0) + $periods;
            }
        }

        foreach ($loads as $teacherId => $periods) {
            if ($periods > 25) {
                throw new ScheduleSearchExhaustedException(
                    "Teacher {$teacherId} requires {$periods} periods, exceeding the maximum compatible with the teacher-break rule. No draft was created."
                );
            }
        }
    }

    private function teacherSectionCounts(array $sections): array
    {
        $counts = [];
        foreach ($sections as $section) {
            foreach (array_unique(array_column($section['assignments'], 'teacher_id')) as $teacherId) {
                $counts[(int) $teacherId] = ($counts[(int) $teacherId] ?? 0) + 1;
            }
        }

        return $counts;
    }

    private function sectionPressure(array $section, array $teacherSectionCounts): int
    {
        $pressure = 0;
        foreach (SchedulePolicy::periodCounts($section['grade_level']) as $code => $periods) {
            $teacherId = (int) $section['assignments'][$code]['teacher_id'];
            $pressure += $periods * ($teacherSectionCounts[$teacherId] ?? 1);
        }

        return $pressure;
    }

    /** @return list<array<string, int|string>> */
    private function mapTemplate(array $section, array $template): array
    {
        $entries = [];
        foreach ($template as $entry) {
            $assignment = $section['assignments'][$entry['department_code']] ?? null;
            if (! $assignment) {
                throw new InvalidArgumentException("Section {$section['name']} has no {$entry['department_code']} assignment.");
            }

            $entries[] = [
                'section_id' => (int) $section['id'],
                'department_code' => $entry['department_code'],
                'department_id' => (int) $assignment['department_id'],
                'teacher_id' => (int) $assignment['teacher_id'],
                'day' => $entry['day'],
                'period' => $entry['period'],
            ];
        }

        return $entries;
    }

    private function hasBreakViolation(array $periods): bool
    {
        return (isset($periods[2], $periods[3])
            || isset($periods[5], $periods[6])
            || isset($periods[3], $periods[4], $periods[5]));
    }

}
