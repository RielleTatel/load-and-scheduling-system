<?php

namespace App\Services\Scheduling;

use InvalidArgumentException;
use Random\Engine\Mt19937;
use Random\Randomizer;

final class ScheduleGenerationEngine implements TimetableGenerator
{
    private int $bestRepairPenalty = PHP_INT_MAX;

    /** @var list<array<string, int|string>> */
    private array $bestRepairEntries = [];

    private ?\Closure $progress = null;

    private ?\Closure $checkpoint = null;

    private float $searchStarted = 0;

    private float $searchSeconds = 110;

    private float $lastProgress = 0;

    private const MAX_TEMPLATES_PER_GRADE = 320;

    private const MIN_TEMPLATES_PER_GRADE = 96;

    private const MRV_LOOKAHEAD = 8;

    private const CANDIDATES_PER_SECTION = 24;

    private const SLOT_CONFLICT_WEIGHT = 100;

    public function __construct(private readonly ScheduleConstraintValidator $validator) {}

    /**
     * @param  list<array{id:int, grade_level:string, name:string, assignments:array<string, array{department_id:int, teacher_id:int}>}>  $sections
     * @param  list<array{teacher_id:int, day:int, period:int}>  $unavailable
     * @return array{entries:list<array<string, int|string>>, issues:list<array<string,mixed>>, score:list<int>, attempts:int}
     */
    public function generate(array $sections, array $unavailable, int $seed, int $seconds = 110, ?\Closure $progress = null, array $startingEntries = [], ?\Closure $checkpoint = null, array $fallbackEntries = []): array
    {
        $this->progress = $progress;
        $this->checkpoint = $checkpoint;
        $this->searchStarted = microtime(true);
        $this->searchSeconds = max(1, min($seconds, 110));
        $this->lastProgress = 0;
        $this->reportProgress();
        $this->bestRepairPenalty = PHP_INT_MAX;
        $this->bestRepairEntries = [];
        if ($sections === []) {
            throw new InvalidArgumentException('At least one section is required to generate a timetable.');
        }

        $deadline = microtime(true) + max(1, min($seconds, 110));
        $availability = [];
        foreach ($unavailable as $slot) {
            $availability[$slot['teacher_id'].':'.$slot['day'].':'.$slot['period']] = true;
        }

        $this->assertAssignmentsAreComplete($sections);
        $this->assertTeacherLoadsCanFit($sections, $availability);

        $teacherSectionCounts = $this->teacherSectionCounts($sections);
        usort($sections, function (array $left, array $right) use ($teacherSectionCounts): int {
            $leftPressure = $this->sectionPressure($left, $teacherSectionCounts);
            $rightPressure = $this->sectionPressure($right, $teacherSectionCounts);

            $pressureOrder = $rightPressure <=> $leftPressure;

            return $pressureOrder !== 0
                ? $pressureOrder
                : (int) $left['id'] <=> (int) $right['id'];
        });

        $startingTemplates = $this->startingTemplates($sections, $startingEntries);
        if (count($startingTemplates) === count($sections)) {
            $candidate = $this->mappedAssignments($sections, $startingTemplates);
            if ($this->validator->validate($sections, $candidate, $unavailable) === []) {
                return ['entries' => $candidate, 'issues' => [], 'score' => [0, 0, 0], 'attempts' => 1];
            }
        }

        $templatePools = $this->buildTemplatePools($sections, $seed, $deadline);
        $nodes = 0;
        $checks = 0;
        if (count($sections) >= 3 || $startingTemplates !== []) {
            $result = $this->repairSearch(
                $sections,
                $templatePools,
                $availability,
                $seed,
                $deadline,
                $nodes,
                $startingTemplates,
            );
        } else {
            $teacherSlots = [];
            $entries = [];
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
        }

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

        // Prefer adjusting the reference first. A currently valid prior schedule is
        // a safe fallback, never an exception to the rules or teacher assignments.
        $fallback = $this->startingTemplates($sections, $fallbackEntries);
        if (count($fallback) === count($sections)) {
            $candidate = $this->mappedAssignments($sections, $fallback);
            if ($this->validator->validate($sections, $candidate, $unavailable) === []) {
                return ['entries' => $candidate, 'issues' => [], 'score' => [0, 0, 0], 'attempts' => max(1, $nodes), 'used_fallback' => true];
            }
        }

        $remainingSummary = '';
        $bestIssues = [];
        if ($this->bestRepairEntries !== []) {
            $bestIssues = $this->validator->validate(array_map(fn (array $section): array => [
                'id' => $section['id'],
                'grade_level' => $section['grade_level'],
                'name' => $section['name'],
            ], $sections), $this->bestRepairEntries, $unavailable);
            $remaining = array_count_values(array_column($bestIssues, 'code'));
            $remainingSummary = '; remaining '.implode(', ', array_map(
                fn (string $code, int $count): string => $code.'='.$count,
                array_keys($remaining),
                array_values($remaining),
            ));
            $locations = array_values(array_filter(array_map(
                fn (array $issue): ?string => isset($issue['teacher_id'], $issue['day'])
                    ? $issue['code'].' teacher '.$issue['teacher_id'].' day '.$issue['day']
                    : null,
                $bestIssues,
            )));
            if ($locations !== []) {
                $remainingSummary .= ' ['.implode('; ', array_slice($locations, 0, 8)).']';
            }
        }

        throw new ScheduleSearchExhaustedException(
            'No conflict-free timetable was found within the configured search limit '
            ."({$nodes} placements checked"
            .($this->bestRepairPenalty === PHP_INT_MAX ? '' : "; best remaining penalty {$this->bestRepairPenalty}")
            .$remainingSummary
            .'). No draft was created.',
            $this->bestRepairEntries,
            $bestIssues,
            $this->bestRepairPenalty === PHP_INT_MAX ? null : $this->bestRepairPenalty,
        );
    }

    /**
     * Min-conflicts repair is substantially more effective than chronological backtracking for
     * a school-wide graph where every section connects eight independent teacher groups.
     *
     * @param  list<array{id:int, grade_level:string, name:string, assignments:array<string, array{department_id:int, teacher_id:int}>}>  $sections
     * @param  array<string, list<list<array{day:int, period:int, department_code:string}>>>  $templatePools
     * @param  array<string, bool>  $availability
     * @return list<array<string, int|string>>|null
     */
    private function repairSearch(
        array $sections,
        array $templatePools,
        array $availability,
        int $seed,
        float $deadline,
        int &$nodes,
        array $startingTemplates = [],
    ): ?array {
        $random = new Randomizer(new Mt19937($seed));
        $restart = 0;
        $globalBestScore = PHP_INT_MAX;
        $globalBestAssigned = null;

        while (microtime(true) < $deadline) {
            $assigned = [];
            $slots = [];
            if ($globalBestAssigned !== null && ($restart % 2) === 1) {
                $assigned = $globalBestAssigned;
                foreach ($assigned as $sectionIndex => $template) {
                    $this->addTemplateToState($sectionIndex, $sections[$sectionIndex], $template, $slots);
                }
                [, $perturbCandidates] = $this->statePenalty($slots, $availability);
                if ($perturbCandidates === []) {
                    $perturbCandidates = array_keys($sections);
                }
                $perturbCandidates = $random->shuffleArray($perturbCandidates);
                $perturbCount = min(count($perturbCandidates), max(2, intdiv(count($sections), 10)));
                foreach (array_slice($perturbCandidates, 0, $perturbCount) as $sectionIndex) {
                    $section = $sections[$sectionIndex];
                    $this->removeTemplateFromState($sectionIndex, $section, $assigned[$sectionIndex], $slots);
                    $pool = $templatePools[$section['grade_level']];
                    $assigned[$sectionIndex] = $pool[$random->getInt(0, count($pool) - 1)];
                    $this->addTemplateToState($sectionIndex, $section, $assigned[$sectionIndex], $slots);
                    $nodes++;
                }
            } else {
                $initialOrder = array_keys($sections);
                if ($restart > 0) {
                    $initialOrder = $random->shuffleArray($initialOrder);
                }
                foreach ($initialOrder as $sectionIndex) {
                    $section = $sections[$sectionIndex];
                    if ($restart === 0 && isset($startingTemplates[$sectionIndex])) {
                        $assigned[$sectionIndex] = $startingTemplates[$sectionIndex];
                        $this->addTemplateToState($sectionIndex, $section, $assigned[$sectionIndex], $slots);
                        $nodes++;

                        continue;
                    }
                    $pool = $templatePools[$section['grade_level']];
                    [$baseScore] = $this->statePenalty($slots, $availability);
                    $bestScore = PHP_INT_MAX;
                    $bestTemplates = [];
                    foreach ($pool as $template) {
                        $candidateScore = $this->penaltyAfterAdding($section, $template, $slots, $availability, $baseScore);
                        if ($candidateScore < $bestScore) {
                            $bestScore = $candidateScore;
                            $bestTemplates = [$template];
                        } elseif ($candidateScore === $bestScore && count($bestTemplates) < 32) {
                            $bestTemplates[] = $template;
                        }
                    }
                    $template = $bestTemplates[$random->getInt(0, count($bestTemplates) - 1)];
                    $assigned[$sectionIndex] = $template;
                    $this->addTemplateToState($sectionIndex, $section, $template, $slots);
                    $nodes++;
                }
            }
            ksort($assigned);

            $bestRestartScore = PHP_INT_MAX;
            $stagnant = 0;
            $iterationLimit = max(800, count($sections) * 120);

            for ($iteration = 0; $iteration < $iterationLimit && microtime(true) < $deadline; $iteration++) {
                [$score, $conflictedSections] = $this->statePenalty($slots, $availability);
                // Coordinated reversals preserve section patterns and teacher non-overlap.
                // They can repair break conflicts that isolated section moves cannot escape.
                if ($score > 0 && $score < self::SLOT_CONFLICT_WEIGHT && $iteration % 20 === 0) {
                    $this->repairDayReversals($sections, $assigned, $slots, $availability, $score);
                    [$score, $conflictedSections] = $this->statePenalty($slots, $availability);
                }
                $this->bestRepairPenalty = min($this->bestRepairPenalty, $score);
                if ($score < $globalBestScore) {
                    $globalBestScore = $score;
                    $globalBestAssigned = $assigned;
                    $this->bestRepairEntries = $this->mappedAssignments($sections, $assigned);
                    if ($this->checkpoint) {
                        ($this->checkpoint)($this->bestRepairEntries, $score);
                    }
                }
                if ($score === 0) {
                    return $this->mappedAssignments($sections, $assigned);
                }
                if ($conflictedSections === []) {
                    break;
                }

                if ($score < $bestRestartScore) {
                    $bestRestartScore = $score;
                    $stagnant = 0;
                } else {
                    $stagnant++;
                }
                if ($stagnant > max(300, count($sections) * 12)) {
                    break;
                }

                $sectionIndex = $conflictedSections[$random->getInt(0, count($conflictedSections) - 1)];
                $section = $sections[$sectionIndex];
                $currentTemplate = $assigned[$sectionIndex];
                $this->removeTemplateFromState($sectionIndex, $section, $currentTemplate, $slots);
                [$baseScore] = $this->statePenalty($slots, $availability);

                $pool = $templatePools[$section['grade_level']];
                $candidatePool = array_merge(
                    [$currentTemplate],
                    $pool,
                    $this->mutatedTemplates($currentTemplate, $section['grade_level'], $random, 160),
                    $score < self::SLOT_CONFLICT_WEIGHT
                        ? array_merge(
                            $this->singleSwapTemplates($currentTemplate, $section['grade_level']),
                            $this->blockMoveTemplates($currentTemplate, $section['grade_level']),
                        )
                        : [],
                );
                $candidatePool = array_values(array_filter(
                    $candidatePool,
                    fn (array $template): bool => $this->hasValidDailyDistribution(
                        $template,
                        $section['grade_level'],
                    ),
                ));
                $bestScore = PHP_INT_MAX;
                $bestDistance = PHP_INT_MAX;
                $bestTemplates = [];
                $scoredTemplates = [];
                foreach ($candidatePool as $template) {
                    $candidateScore = $this->penaltyAfterAdding($section, $template, $slots, $availability, $baseScore);
                    $distance = isset($startingTemplates[$sectionIndex])
                        ? count(array_filter(array_keys($template), fn (int $index): bool => $template[$index]['department_code'] !== $startingTemplates[$sectionIndex][$index]['department_code']))
                        : 0;
                    $scoredTemplates[] = [$candidateScore, $template];
                    if ($candidateScore < $bestScore || ($candidateScore === $bestScore && $distance < $bestDistance)) {
                        $bestScore = $candidateScore;
                        $bestDistance = $distance;
                        $bestTemplates = [$template];
                    } elseif ($candidateScore === $bestScore && $distance === $bestDistance && count($bestTemplates) < 32) {
                        $bestTemplates[] = $template;
                    }
                }

                if ($random->getInt(1, 100) <= 12) {
                    $nearBest = array_values(array_filter(
                        $scoredTemplates,
                        fn (array $scored): bool => $scored[0] <= $bestScore + self::SLOT_CONFLICT_WEIGHT,
                    ));
                    $chosen = $nearBest[$random->getInt(0, count($nearBest) - 1)][1];
                } else {
                    $chosen = $bestTemplates[$random->getInt(0, count($bestTemplates) - 1)];
                }
                $assigned[$sectionIndex] = $chosen;
                $this->addTemplateToState($sectionIndex, $section, $chosen, $slots);
                $nodes++;
            }

            $restart++;
            if (($restart % 2) === 0 && microtime(true) < $deadline - 0.1) {
                $this->extendTemplatePools($templatePools, $seed + $restart, 80);
            }
        }

        return null;
    }

    /**
     * Swapping non-block subject cells preserves all frequency and consecutive-block rules while
     * providing a fine-grained neighborhood that whole random templates cannot express efficiently.
     *
     * @return list<list<array{day:int, period:int, department_code:string}>>
     */
    private function mutatedTemplates(array $template, string $gradeLevel, Randomizer $random, int $count): array
    {
        $mutableCodes = ['MATH', 'SCI', 'CLE', 'FIL', 'SOC'];
        if ($gradeLevel !== 'G10') {
            $mutableCodes[] = 'ENG';
        }
        $mutableIndexes = array_keys(array_filter(
            $template,
            fn (array $entry): bool => in_array($entry['department_code'], $mutableCodes, true),
        ));
        $result = [];
        $unique = [];

        for ($attempt = 0; $attempt < $count * 4 && count($result) < $count; $attempt++) {
            $candidate = $template;
            $swaps = $random->getInt(1, 3);
            for ($swap = 0; $swap < $swaps; $swap++) {
                $left = $mutableIndexes[$random->getInt(0, count($mutableIndexes) - 1)];
                $right = $mutableIndexes[$random->getInt(0, count($mutableIndexes) - 1)];
                if ($left === $right || $candidate[$left]['department_code'] === $candidate[$right]['department_code']) {
                    continue;
                }
                [$candidate[$left]['department_code'], $candidate[$right]['department_code']] = [
                    $candidate[$right]['department_code'],
                    $candidate[$left]['department_code'],
                ];
            }
            if ($random->getInt(1, 100) <= 65) {
                $this->mutateBlockPlacement($candidate, $gradeLevel, $mutableCodes, $random);
            }
            $key = implode('|', array_column($candidate, 'department_code'));
            if (isset($unique[$key])) {
                continue;
            }
            $unique[$key] = true;
            $result[] = $candidate;
        }

        return $result;
    }

    /** @param list<array{day:int, period:int, department_code:string}> $template */
    private function hasValidDailyDistribution(array $template, string $gradeLevel): bool
    {
        $bySubject = [];
        foreach ($template as $entry) {
            $bySubject[$entry['department_code']][$entry['day']][] = (int) $entry['period'];
        }

        foreach (SchedulePolicy::periodCounts($gradeLevel) as $code => $expectedCount) {
            $days = $bySubject[$code] ?? [];
            if (array_sum(array_map('count', $days)) !== $expectedCount) {
                return false;
            }

            if (! SchedulePolicy::allowsDoubleBlock($gradeLevel, $code)) {
                if (array_filter($days, fn (array $periods): bool => count($periods) > 1) !== []) {
                    return false;
                }

                continue;
            }

            $shape = array_map('count', array_values($days));
            sort($shape);
            $expectedShape = SchedulePolicy::requiredDailyShape($gradeLevel, $code);
            sort($expectedShape);
            if ($shape !== $expectedShape) {
                return false;
            }

            foreach ($days as $periods) {
                sort($periods);
                if (count($periods) === 2 && ! in_array($periods, SchedulePolicy::DOUBLE_BLOCKS, true)) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @return list<list<array{day:int, period:int, department_code:string}>> */
    private function blockMoveTemplates(array $template, string $gradeLevel): array
    {
        $mutableCodes = ['MATH', 'SCI', 'CLE', 'FIL', 'SOC'];
        if ($gradeLevel !== 'G10') {
            $mutableCodes[] = 'ENG';
        }
        $blockCodes = $gradeLevel === 'G10' ? ['ENG', 'TLE', 'MAPEH'] : ['TLE', 'MAPEH'];
        $result = [];

        $swapped = $template;
        foreach ($swapped as &$entry) {
            if ($entry['department_code'] === 'TLE') {
                $entry['department_code'] = 'MAPEH';
            } elseif ($entry['department_code'] === 'MAPEH') {
                $entry['department_code'] = 'TLE';
            }
        }
        unset($entry);
        $result[] = $swapped;

        foreach ($blockCodes as $code) {
            $byDay = [];
            foreach ($template as $index => $entry) {
                if ($entry['department_code'] === $code) {
                    $byDay[$entry['day']][] = $index;
                }
            }

            foreach ($byDay as $day => $sourceIndexes) {
                if (count($sourceIndexes) === 1) {
                    foreach ($template as $target => $entry) {
                        if ($entry['day'] !== $day || ! in_array($entry['department_code'], $mutableCodes, true)) {
                            continue;
                        }
                        $candidate = $template;
                        [$candidate[$sourceIndexes[0]]['department_code'], $candidate[$target]['department_code']] = [
                            $candidate[$target]['department_code'],
                            $candidate[$sourceIndexes[0]]['department_code'],
                        ];
                        $result[] = $candidate;
                    }

                    continue;
                }

                foreach (SchedulePolicy::DOUBLE_BLOCKS as $periods) {
                    $targetIndexes = [];
                    foreach ($periods as $period) {
                        foreach ($template as $index => $entry) {
                            if ($entry['day'] === $day && $entry['period'] === $period) {
                                $targetIndexes[] = $index;
                                break;
                            }
                        }
                    }
                    $newIndexes = array_values(array_diff($targetIndexes, $sourceIndexes));
                    $oldIndexes = array_values(array_diff($sourceIndexes, $targetIndexes));
                    if (count($targetIndexes) !== 2
                        || $newIndexes === []
                        || count($newIndexes) !== count($oldIndexes)
                        || array_filter($newIndexes, fn (int $index): bool => ! in_array(
                            $template[$index]['department_code'],
                            $mutableCodes,
                            true,
                        )) !== []) {
                        continue;
                    }
                    $candidate = $template;
                    $displaced = array_map(fn (int $index): string => $candidate[$index]['department_code'], $newIndexes);
                    foreach ($targetIndexes as $index) {
                        $candidate[$index]['department_code'] = $code;
                    }
                    foreach ($oldIndexes as $position => $index) {
                        $candidate[$index]['department_code'] = $displaced[$position];
                    }
                    $result[] = $candidate;
                }
            }
        }

        return $result;
    }

    /** @return list<list<array{day:int, period:int, department_code:string}>> */
    private function singleSwapTemplates(array $template, string $gradeLevel): array
    {
        $mutableCodes = ['MATH', 'SCI', 'CLE', 'FIL', 'SOC'];
        if ($gradeLevel !== 'G10') {
            $mutableCodes[] = 'ENG';
        }
        $indexes = array_keys(array_filter(
            $template,
            fn (array $entry): bool => in_array($entry['department_code'], $mutableCodes, true),
        ));
        $result = [];

        for ($leftPosition = 0; $leftPosition < count($indexes); $leftPosition++) {
            for ($rightPosition = $leftPosition + 1; $rightPosition < count($indexes); $rightPosition++) {
                $left = $indexes[$leftPosition];
                $right = $indexes[$rightPosition];
                if ($template[$left]['department_code'] === $template[$right]['department_code']) {
                    continue;
                }
                $candidate = $template;
                [$candidate[$left]['department_code'], $candidate[$right]['department_code']] = [
                    $candidate[$right]['department_code'],
                    $candidate[$left]['department_code'],
                ];
                $result[] = $candidate;
            }
        }

        return $result;
    }

    /** @param list<array{day:int, period:int, department_code:string}> $template
     * @param  list<string>  $mutableCodes
     */
    private function mutateBlockPlacement(array &$template, string $gradeLevel, array $mutableCodes, Randomizer $random): void
    {
        if ($random->getInt(1, 10) === 1) {
            foreach ($template as &$entry) {
                if ($entry['department_code'] === 'TLE') {
                    $entry['department_code'] = 'MAPEH';
                } elseif ($entry['department_code'] === 'MAPEH') {
                    $entry['department_code'] = 'TLE';
                }
            }
            unset($entry);

            return;
        }

        $blockCodes = $gradeLevel === 'G10' ? ['ENG', 'TLE', 'MAPEH'] : ['TLE', 'MAPEH'];
        $code = $blockCodes[$random->getInt(0, count($blockCodes) - 1)];
        $byDay = [];
        foreach ($template as $index => $entry) {
            if ($entry['department_code'] === $code) {
                $byDay[$entry['day']][] = $index;
            }
        }
        $days = array_keys($byDay);
        $day = $days[$random->getInt(0, count($days) - 1)];
        $sourceIndexes = $byDay[$day];

        if (count($sourceIndexes) === 1) {
            $targets = array_keys(array_filter(
                $template,
                fn (array $entry): bool => $entry['day'] === $day
                    && in_array($entry['department_code'], $mutableCodes, true),
            ));
            if ($targets === []) {
                return;
            }
            $target = $targets[$random->getInt(0, count($targets) - 1)];
            [$template[$sourceIndexes[0]]['department_code'], $template[$target]['department_code']] = [
                $template[$target]['department_code'],
                $template[$sourceIndexes[0]]['department_code'],
            ];

            return;
        }

        $blocks = SchedulePolicy::DOUBLE_BLOCKS;
        $blocks = $random->shuffleArray($blocks);
        foreach ($blocks as $periods) {
            $targetIndexes = [];
            foreach ($periods as $period) {
                foreach ($template as $index => $entry) {
                    if ($entry['day'] === $day && $entry['period'] === $period) {
                        $targetIndexes[] = $index;
                        break;
                    }
                }
            }
            $newIndexes = array_values(array_diff($targetIndexes, $sourceIndexes));
            $oldIndexes = array_values(array_diff($sourceIndexes, $targetIndexes));
            if (count($targetIndexes) !== 2
                || $newIndexes === []
                || count($newIndexes) !== count($oldIndexes)
                || array_filter($newIndexes, fn (int $index): bool => ! in_array(
                    $template[$index]['department_code'],
                    $mutableCodes,
                    true,
                )) !== []) {
                continue;
            }

            $displaced = array_map(fn (int $index): string => $template[$index]['department_code'], $newIndexes);
            foreach ($targetIndexes as $index) {
                $template[$index]['department_code'] = $code;
            }
            foreach ($oldIndexes as $position => $index) {
                $template[$index]['department_code'] = $displaced[$position];
            }

            return;
        }
    }

    /** @param array<int, array<int, array<int, array<int, bool>>>> $slots */
    private function addTemplateToState(int $sectionIndex, array $section, array $template, array &$slots): void
    {
        foreach ($template as $entry) {
            $teacherId = (int) $section['assignments'][$entry['department_code']]['teacher_id'];
            $slots[$teacherId][$entry['day']][$entry['period']][$sectionIndex] = true;
        }
    }

    /** @param array<int, array<int, array<int, array<int, bool>>>> $slots */
    private function removeTemplateFromState(int $sectionIndex, array $section, array $template, array &$slots): void
    {
        foreach ($template as $entry) {
            $teacherId = (int) $section['assignments'][$entry['department_code']]['teacher_id'];
            unset($slots[$teacherId][$entry['day']][$entry['period']][$sectionIndex]);
        }
    }

    /**
     * @param  array<int, array<int, array<int, array<int, bool>>>>  $slots
     * @param  array<string, bool>  $availability
     * @return array{int, list<int>}
     */
    private function statePenalty(array $slots, array $availability): array
    {
        $this->reportProgress();
        $score = 0;
        $conflicted = [];

        foreach ($slots as $teacherId => $days) {
            foreach ($days as $day => $periods) {
                $occupiedPeriods = [];
                $daySections = [];
                foreach ($periods as $period => $sectionSet) {
                    if ($sectionSet === []) {
                        continue;
                    }
                    $occupiedPeriods[$period] = true;
                    foreach (array_keys($sectionSet) as $sectionIndex) {
                        $daySections[$sectionIndex] = true;
                    }
                    $count = count($sectionSet);
                    if ($count > 1) {
                        $score += ($count - 1) * self::SLOT_CONFLICT_WEIGHT;
                        foreach (array_keys($sectionSet) as $sectionIndex) {
                            $conflicted[$sectionIndex] = true;
                        }
                    }
                    if (isset($availability[$teacherId.':'.$day.':'.$period])) {
                        $score += $count * self::SLOT_CONFLICT_WEIGHT;
                        foreach (array_keys($sectionSet) as $sectionIndex) {
                            $conflicted[$sectionIndex] = true;
                        }
                    }
                }

                $breakViolations = $this->breakViolationCount($occupiedPeriods);
                if ($breakViolations > 0) {
                    $score += $breakViolations;
                    foreach (array_keys($daySections) as $sectionIndex) {
                        $conflicted[$sectionIndex] = true;
                    }
                }
            }
        }

        return [$score, array_map('intval', array_keys($conflicted))];
    }

    /** @param array<int, array<int, array<int, array<int, bool>>>> $slots */
    private function penaltyAfterAdding(array $section, array $template, array $slots, array $availability, int $baseScore): int
    {
        $score = $baseScore;
        $newPeriods = [];

        foreach ($template as $entry) {
            $teacherId = (int) $section['assignments'][$entry['department_code']]['teacher_id'];
            $day = (int) $entry['day'];
            $period = (int) $entry['period'];
            $score += count($slots[$teacherId][$day][$period] ?? []) * self::SLOT_CONFLICT_WEIGHT;
            if (isset($availability[$teacherId.':'.$day.':'.$period])) {
                $score += self::SLOT_CONFLICT_WEIGHT;
            }
            $newPeriods[$teacherId][$day][$period] = true;
        }

        foreach ($newPeriods as $teacherId => $days) {
            foreach ($days as $day => $periods) {
                $before = [];
                foreach ($slots[$teacherId][$day] ?? [] as $period => $sectionSet) {
                    if ($sectionSet !== []) {
                        $before[$period] = true;
                    }
                }
                $score += $this->breakViolationCount($before + $periods) - $this->breakViolationCount($before);
            }
        }

        return $score;
    }

    /** @return list<array<string, int|string>> */
    private function mappedAssignments(array $sections, array $assigned): array
    {
        $entries = [];
        foreach ($assigned as $sectionIndex => $template) {
            array_push($entries, ...$this->mapTemplate($sections[$sectionIndex], $template));
        }

        return $entries;
    }

    /**
     * @param  array<string, list<list<array{day:int, period:int, department_code:string}>>>  $pools
     */
    private function extendTemplatePools(array &$pools, int $seed, int $additionalPerGrade): void
    {
        $generator = new ScheduleTemplateGenerator($seed);
        foreach ($pools as $grade => &$pool) {
            $remaining = $additionalPerGrade;
            $unique = [];
            foreach ($pool as $template) {
                $unique[implode('|', array_column($template, 'department_code'))] = true;
            }
            for ($attempt = 0; $attempt < $additionalPerGrade * 3 && $remaining > 0; $attempt++) {
                $template = $generator->build($grade);
                $key = implode('|', array_column($template, 'department_code'));
                if (isset($unique[$key])) {
                    continue;
                }
                $unique[$key] = true;
                $pool[] = $template;
                $remaining--;
            }
        }
        unset($pool);
    }

    /**
     * @param  list<array{id:int, grade_level:string, name:string, assignments:array<string, array{department_id:int, teacher_id:int}>}>  $sections
     * @param  list<int>  $remaining
     * @param  array<string, list<list<array{day:int, period:int, department_code:string}>>>  $templatePools
     * @param  array<string, bool>  $availability
     * @param  array<int, array<int, array<int, bool>>>  $teacherSlots
     * @param  list<array<string, int|string>>  $entries
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
        $this->reportProgress();
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
     * @param  list<list<array{day:int, period:int, department_code:string}>>  $pool
     * @param  array<int, array<int, array<int, bool>>>  $occupied
     * @param  array<string, bool>  $availability
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
     * @param  list<array{id:int, grade_level:string, name:string, assignments:array<string, array{department_id:int, teacher_id:int}>}>  $sections
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
     * @param  array<int, array<int, array<int, bool>>>  $occupied
     */
    private function occupy(array $entries, array &$occupied): void
    {
        foreach ($entries as $entry) {
            $occupied[$entry['teacher_id']][$entry['day']][$entry['period']] = true;
        }
    }

    /** @param list<array<string, int|string>> $entries
     * @param  array<int, array<int, array<int, bool>>>  $occupied
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

    private function assertTeacherLoadsCanFit(array $sections, array $availability): void
    {
        $loads = [];
        $dailyRequired = [];
        $requiredBlocks = [];
        foreach ($sections as $section) {
            foreach (SchedulePolicy::periodCounts($section['grade_level']) as $code => $periods) {
                $teacherId = (int) $section['assignments'][$code]['teacher_id'];
                $loads[$teacherId] = ($loads[$teacherId] ?? 0) + $periods;
                if ($periods === 5 && ! SchedulePolicy::allowsDoubleBlock($section['grade_level'], $code)) {
                    $dailyRequired[$teacherId] = ($dailyRequired[$teacherId] ?? 0) + 1;
                }
                if (SchedulePolicy::allowsDoubleBlock($section['grade_level'], $code)) {
                    $requiredBlocks[$teacherId] = ($requiredBlocks[$teacherId] ?? 0)
                        + count(array_filter(SchedulePolicy::requiredDailyShape($section['grade_level'], $code), fn (int $count): bool => $count === 2));
                }
            }
        }

        foreach ($loads as $teacherId => $periods) {
            $capacity = 0;
            $blockCapacity = 0;
            for ($day = 1; $day <= SchedulePolicy::DAYS; $day++) {
                $dailyCapacity = 0;
                $dailyBlockCapacity = 0;
                for ($mask = 0; $mask < 128; $mask++) {
                    $occupied = [];
                    for ($period = 1; $period <= SchedulePolicy::PERIODS_PER_DAY; $period++) {
                        if (($mask & (1 << ($period - 1))) !== 0) {
                            if (isset($availability[$teacherId.':'.$day.':'.$period])) {
                                continue 2;
                            }
                            $occupied[$period] = true;
                        }
                    }
                    if (! $this->hasBreakViolation($occupied)) {
                        $dailyCapacity = max($dailyCapacity, count($occupied));
                        $blocks = 0;
                        foreach (SchedulePolicy::DOUBLE_BLOCKS as [$left, $right]) {
                            $blocks += (int) isset($occupied[$left], $occupied[$right]);
                        }
                        $dailyBlockCapacity = max($dailyBlockCapacity, $blocks);
                    }
                }
                if ($dailyCapacity < ($dailyRequired[$teacherId] ?? 0)) {
                    throw new ScheduleInfeasibleException("Scheduling cannot fit: teacher {$teacherId} does not have enough available periods on Day {$day} for the subjects required every day. Review teacher availability or assignments.");
                }
                $capacity += $dailyCapacity;
                $blockCapacity += $dailyBlockCapacity;
            }
            if ($periods > $capacity) {
                throw new ScheduleInfeasibleException("Scheduling cannot fit: teacher {$teacherId} needs {$periods} periods, but only {$capacity} fit the availability and teacher-break rules. Review teacher availability or assignments.");
            }
            if (($requiredBlocks[$teacherId] ?? 0) > $blockCapacity) {
                throw new ScheduleInfeasibleException("Scheduling cannot fit: teacher {$teacherId} does not have enough available consecutive periods for the required double classes. Review teacher availability or assignments.");
            }
        }

        foreach ($sections as $section) {
            $dailyCodes = array_values(array_filter(array_keys(SchedulePolicy::periodCounts($section['grade_level'])),
                fn (string $code): bool => SchedulePolicy::periodCounts($section['grade_level'])[$code] === 5
                    && ! SchedulePolicy::allowsDoubleBlock($section['grade_level'], $code)));
            for ($day = 1; $day <= 5; $day++) {
                // Necessary matching check: daily subjects must have distinct class slots.
                for ($subset = 1; $subset < (1 << count($dailyCodes)); $subset++) {
                    $allowed = [];
                    $needed = 0;
                    foreach ($dailyCodes as $bit => $code) {
                        if (($subset & (1 << $bit)) === 0) {
                            continue;
                        }
                        $needed++;
                        $teacher = $section['assignments'][$code]['teacher_id'];
                        for ($period = 1; $period <= 7; $period++) {
                            if (! isset($availability[$teacher.':'.$day.':'.$period])) {
                                $allowed[$period] = true;
                            }
                        }
                    }
                    if (count($allowed) < $needed) {
                        throw new ScheduleInfeasibleException("Scheduling cannot fit: {$section['name']} has daily subjects that cannot fit into separate available periods on Day {$day}. Review teacher availability or assignments.");
                    }
                }
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
        return $this->breakViolationCount($periods) > 0;
    }

    private function breakViolationCount(array $periods): int
    {
        return (int) isset($periods[2], $periods[3])
            + (int) isset($periods[5], $periods[6])
            + (int) isset($periods[3], $periods[4], $periods[5]);
    }

    private function reportProgress(): void
    {
        $now = microtime(true);
        if ($this->progress && $now - $this->lastProgress >= 2) {
            $this->lastProgress = $now;
            if (($this->progress)(min(99, (int) floor(($now - $this->searchStarted) / $this->searchSeconds * 100))) === false) {
                throw new ScheduleGenerationStoppedException('Generation was stopped.');
            }
        }
    }

    private function startingTemplates(array $sections, array $entries): array
    {
        $bySection = [];
        foreach ($entries as $entry) {
            if (isset($entry['section_id'], $entry['department_code'], $entry['day'], $entry['period'])
                && $entry['day'] >= 1 && $entry['day'] <= 5 && $entry['period'] >= 1 && $entry['period'] <= 7) {
                $bySection[$entry['section_id']][$entry['day'].':'.$entry['period']] = [
                    'day' => (int) $entry['day'], 'period' => (int) $entry['period'],
                    'department_code' => $entry['department_code'],
                ];
            }
        }
        $templates = [];
        foreach ($sections as $index => $section) {
            $template = array_values($bySection[$section['id']] ?? []);
            if (count($template) !== 35 || ! $this->hasValidDailyDistribution($template, $section['grade_level'])
                || array_diff(array_column($template, 'department_code'), array_keys($section['assignments'])) !== []) {
                continue;
            }
            usort($template, fn (array $a, array $b): int => [$a['day'], $a['period']] <=> [$b['day'], $b['period']]);
            $templates[$index] = $template;
        }

        return $templates;
    }

    private function repairDayReversals(array $sections, array &$assigned, array &$slots, array $availability, int $score): void
    {
        for ($day = 1; $day <= 5; $day++) {
            $bestScore = $score;
            $bestAssigned = null;
            $bestSlots = null;
            for ($mask = 1; $mask < 8; $mask++) {
                $permutation = [];
                foreach ([[1, 2], [3, 5], [6, 7]] as $bit => [$left, $right]) {
                    if (($mask & (1 << $bit)) !== 0) {
                        $permutation[$left] = $right;
                        $permutation[$right] = $left;
                    }
                }
                $candidate = $assigned;
                $candidateSlots = [];
                foreach ($candidate as $index => &$template) {
                    foreach ($template as &$entry) {
                        if ($entry['day'] === $day) {
                            $entry['period'] = $permutation[$entry['period']] ?? $entry['period'];
                        }
                    }
                    unset($entry);
                    usort($template, fn (array $a, array $b): int => [$a['day'], $a['period']] <=> [$b['day'], $b['period']]);
                    $this->addTemplateToState($index, $sections[$index], $template, $candidateSlots);
                }
                unset($template);
                [$candidateScore] = $this->statePenalty($candidateSlots, $availability);
                if ($candidateScore < $bestScore) {
                    $bestScore = $candidateScore;
                    $bestAssigned = $candidate;
                    $bestSlots = $candidateSlots;
                }
            }
            if ($bestAssigned !== null) {
                $assigned = $bestAssigned;
                $slots = $bestSlots;
                $score = $bestScore;
            }
        }
    }
}
