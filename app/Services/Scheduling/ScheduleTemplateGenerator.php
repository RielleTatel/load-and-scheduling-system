<?php

namespace App\Services\Scheduling;

use InvalidArgumentException;
use Random\Engine\Mt19937;
use Random\Randomizer;

/** Builds one locally valid 5-day, 7-period normal timetable for a section. */
final class ScheduleTemplateGenerator
{
    private Randomizer $random;

    /** @var array<string, list<array<string, mixed>>> */
    private array $planCache = [];

    public function __construct(int $seed = 1)
    {
        $this->random = new Randomizer(new Mt19937($seed));
    }

    /**
     * @return list<array{day:int, period:int, department_code:string}>
     */
    public function build(string $gradeLevel): array
    {
        $plans = $this->validatedPlans($gradeLevel);
        $plan = $plans[$this->random->getInt(0, count($plans) - 1)];

        return $this->buildFromPlan($gradeLevel, $plan, null)
            ?? throw new InvalidArgumentException('Unable to place consecutive periods in a daily template.');
    }

    /**
     * Builds a locally valid template whose individual subject cells satisfy the supplied predicate.
     *
     * @param callable(string, int, int): bool $slotAllowed Receives subject code, day, and period.
     * @return list<array{day:int, period:int, department_code:string}>|null
     */
    public function buildMatching(string $gradeLevel, callable $slotAllowed, int $maxAttempts = 100): ?array
    {
        $plans = $this->validatedPlans($gradeLevel);

        for ($attempt = 0; $attempt < max(1, $maxAttempts); $attempt++) {
            $plan = $plans[$this->random->getInt(0, count($plans) - 1)];
            $entries = $this->buildFromPlan($gradeLevel, $plan, $slotAllowed);
            if ($entries !== null) {
                return $entries;
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    private function validatedPlans(string $gradeLevel): array
    {
        if (! in_array($gradeLevel, ['G7', 'G8', 'G9', 'G10'], true)) {
            throw new InvalidArgumentException("Unsupported JHS grade level: {$gradeLevel}");
        }

        $plans = $this->plansFor($gradeLevel);
        if ($plans === []) {
            throw new InvalidArgumentException("No valid normal timetable template exists for {$gradeLevel}.");
        }

        return $plans;
    }

    /**
     * @param array<string, mixed> $plan
     * @param (callable(string, int, int): bool)|null $slotAllowed
     * @return list<array{day:int, period:int, department_code:string}>|null
     */
    private function buildFromPlan(string $gradeLevel, array $plan, ?callable $slotAllowed): ?array
    {
        $offDays = $this->offDaysForSubjects($plan['missing_days']);
        $entries = [];

        for ($day = 1; $day <= SchedulePolicy::DAYS; $day++) {
            $singles = [];
            $doubles = [];

            if ($gradeLevel !== 'G10') {
                $singles[] = 'ENG';
            } else {
                $this->addPlannedOccurrences('ENG', $day, $plan['blocks'], $singles, $doubles);
            }

            $singles[] = 'MATH';
            $singles[] = 'SCI';

            foreach (SchedulePolicy::singlePeriodSubjects() as $code) {
                if ($offDays[$code] !== $day) {
                    $singles[] = $code;
                }
            }

            foreach (['TLE', 'MAPEH'] as $code) {
                $this->addPlannedOccurrences($code, $day, $plan['blocks'], $singles, $doubles);
            }

            $dayEntries = $slotAllowed === null
                ? $this->placeDay($day, $singles, $doubles)
                : $this->placeMatchingDay($day, $singles, $doubles, $slotAllowed);
            if ($dayEntries === null) {
                return null;
            }
            array_push($entries, ...$dayEntries);
        }

        usort($entries, fn (array $a, array $b) => [$a['day'], $a['period']] <=> [$b['day'], $b['period']]);

        return $entries;
    }

    /** @param array<string, mixed> $blocks */
    private function addPlannedOccurrences(string $code, int $day, array $blocks, array &$singles, array &$doubles): void
    {
        $pattern = $blocks[$code];
        if (in_array($day, $pattern['double_days'], true)) {
            $doubles[] = $code;
        } elseif (in_array($day, $pattern['single_days'], true)) {
            $singles[] = $code;
        }
    }

    /** @return list<array{day:int, period:int, department_code:string}> */
    private function placeDay(int $day, array $singles, array $doubles): array
    {
        $this->random->shuffleArray($singles);
        $this->random->shuffleArray($doubles);
        $placements = $this->placeDoubleBlocks($doubles, 0, []);

        if ($placements === null) {
            throw new InvalidArgumentException('Unable to place consecutive periods in a daily template.');
        }

        $openPeriods = array_values(array_diff(range(1, SchedulePolicy::PERIODS_PER_DAY), array_keys($placements)));
        if (count($openPeriods) !== count($singles)) {
            throw new InvalidArgumentException('Daily subject totals do not fill exactly seven periods.');
        }
        $this->random->shuffleArray($openPeriods);

        foreach ($singles as $index => $code) {
            $placements[$openPeriods[$index]] = $code;
        }

        ksort($placements);

        return array_map(fn (int $period, string $code) => [
            'day' => $day,
            'period' => $period,
            'department_code' => $code,
        ], array_keys($placements), array_values($placements));
    }

    /**
     * @param callable(string, int, int): bool $slotAllowed
     * @return list<array{day:int, period:int, department_code:string}>|null
     */
    private function placeMatchingDay(int $day, array $singles, array $doubles, callable $slotAllowed): ?array
    {
        $this->random->shuffleArray($singles);
        $this->random->shuffleArray($doubles);
        $placements = $this->placeMatchingDoubleBlocks($day, $doubles, 0, [], $slotAllowed);
        if ($placements === null) {
            return null;
        }

        $placements = $this->placeMatchingSingles($day, $singles, $placements, $slotAllowed);
        if ($placements === null) {
            return null;
        }

        ksort($placements);

        return array_map(fn (int $period, string $code) => [
            'day' => $day,
            'period' => $period,
            'department_code' => $code,
        ], array_keys($placements), array_values($placements));
    }

    /**
     * @param list<string> $doubles
     * @param array<int, string> $occupied
     * @param callable(string, int, int): bool $slotAllowed
     * @return array<int, string>|null
     */
    private function placeMatchingDoubleBlocks(int $day, array $doubles, int $index, array $occupied, callable $slotAllowed): ?array
    {
        if ($index >= count($doubles)) {
            return $occupied;
        }

        $options = SchedulePolicy::doubleBlockStarts();
        $this->random->shuffleArray($options);
        $code = $doubles[$index];

        foreach ($options as $start) {
            $periods = [$start, $start + 1];
            if (isset($occupied[$periods[0]]) || isset($occupied[$periods[1]])) {
                continue;
            }
            if (! $slotAllowed($code, $day, $periods[0]) || ! $slotAllowed($code, $day, $periods[1])) {
                continue;
            }

            $next = $occupied;
            $next[$periods[0]] = $code;
            $next[$periods[1]] = $code;
            $result = $this->placeMatchingDoubleBlocks($day, $doubles, $index + 1, $next, $slotAllowed);
            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    /**
     * @param list<string> $singles
     * @param array<int, string> $occupied
     * @param callable(string, int, int): bool $slotAllowed
     * @return array<int, string>|null
     */
    private function placeMatchingSingles(int $day, array $singles, array $occupied, callable $slotAllowed): ?array
    {
        if ($singles === []) {
            return $occupied;
        }

        $openPeriods = array_values(array_diff(range(1, SchedulePolicy::PERIODS_PER_DAY), array_keys($occupied)));
        $ranked = [];
        foreach ($singles as $index => $code) {
            $options = array_values(array_filter(
                $openPeriods,
                fn (int $period): bool => $slotAllowed($code, $day, $period),
            ));
            $this->random->shuffleArray($options);
            $ranked[] = ['index' => $index, 'code' => $code, 'options' => $options];
        }
        usort($ranked, fn (array $left, array $right): int => count($left['options']) <=> count($right['options']));
        $choice = $ranked[0];
        if ($choice['options'] === []) {
            return null;
        }

        $remaining = $singles;
        array_splice($remaining, $choice['index'], 1);
        foreach ($choice['options'] as $period) {
            $next = $occupied;
            $next[$period] = $choice['code'];
            $result = $this->placeMatchingSingles($day, $remaining, $next, $slotAllowed);
            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    /**
     * @param list<string> $doubles
     * @param array<int, string> $occupied
     * @return array<int, string>|null
     */
    private function placeDoubleBlocks(array $doubles, int $index, array $occupied): ?array
    {
        if ($index >= count($doubles)) {
            return $occupied;
        }

        $options = SchedulePolicy::doubleBlockStarts();
        $this->random->shuffleArray($options);

        foreach ($options as $start) {
            $periods = [$start, $start + 1];
            if (isset($occupied[$periods[0]]) || isset($occupied[$periods[1]])) {
                continue;
            }

            $next = $occupied;
            $next[$periods[0]] = $doubles[$index];
            $next[$periods[1]] = $doubles[$index];
            $result = $this->placeDoubleBlocks($doubles, $index + 1, $next);
            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    private function plansFor(string $gradeLevel): array
    {
        if (isset($this->planCache[$gradeLevel])) {
            return $this->planCache[$gradeLevel];
        }

        $tleOptions = $this->fourPeriodOptions('TLE');
        $mapehOptions = $this->fourPeriodOptions('MAPEH');
        $plans = [];

        if ($gradeLevel !== 'G10') {
            foreach ($tleOptions as $tle) {
                foreach ($mapehOptions as $mapeh) {
                    $blocks = ['TLE' => $tle, 'MAPEH' => $mapeh];
                    $counts = $this->combinedDayCounts($blocks);
                    $missingDays = $this->missingDaysFor($counts, 1);
                    if ($missingDays !== null) {
                        $plans[] = ['blocks' => $blocks, 'missing_days' => $missingDays];
                    }
                }
            }
        } else {
            foreach ($this->gradeTenEnglishOptions() as $english) {
                foreach ($tleOptions as $tle) {
                    foreach ($mapehOptions as $mapeh) {
                        $blocks = ['ENG' => $english, 'TLE' => $tle, 'MAPEH' => $mapeh];
                        $counts = $this->combinedDayCounts($blocks);
                        $missingDays = $this->missingDaysFor($counts, 2);
                        if ($missingDays !== null) {
                            $plans[] = ['blocks' => $blocks, 'missing_days' => $missingDays];
                        }
                    }
                }
            }
        }

        return $this->planCache[$gradeLevel] = $plans;
    }

    /** @return list<array{double_days:list<int>, single_days:list<int>}> */
    private function fourPeriodOptions(string $code): array
    {
        $options = [];
        for ($doubleDay = 1; $doubleDay <= SchedulePolicy::DAYS; $doubleDay++) {
            $otherDays = array_values(array_diff(range(1, SchedulePolicy::DAYS), [$doubleDay]));
            foreach ($this->combinations($otherDays, 2) as $singleDays) {
                $options[] = ['double_days' => [$doubleDay], 'single_days' => $singleDays];
            }
        }

        return $options;
    }

    /** @return list<array{double_days:list<int>, single_days:list<int>}> */
    private function gradeTenEnglishOptions(): array
    {
        $options = [];
        foreach ($this->combinations(range(1, SchedulePolicy::DAYS), 2) as $doubleDays) {
            $otherDays = array_values(array_diff(range(1, SchedulePolicy::DAYS), $doubleDays));
            foreach ($otherDays as $singleDay) {
                $options[] = ['double_days' => $doubleDays, 'single_days' => [$singleDay]];
            }
        }

        return $options;
    }

    /** @param array<string, array{double_days:list<int>, single_days:list<int>}> $blocks
     *  @return array<int, int>
     */
    private function combinedDayCounts(array $blocks): array
    {
        $counts = array_fill(1, SchedulePolicy::DAYS, 0);
        foreach ($blocks as $pattern) {
            foreach ($pattern['double_days'] as $day) {
                $counts[$day] += 2;
            }
            foreach ($pattern['single_days'] as $day) {
                $counts[$day]++;
            }
        }

        return $counts;
    }

    /** @param array<int, int> $counts
     *  @return list<int>|null
     */
    private function missingDaysFor(array $counts, int $base): ?array
    {
        $missingDays = [];
        foreach ($counts as $day => $count) {
            $missing = $count - $base;
            if ($missing < 0 || $missing > 3) {
                return null;
            }
            for ($i = 0; $i < $missing; $i++) {
                $missingDays[] = $day;
            }
        }

        return count($missingDays) === 3 ? $missingDays : null;
    }

    /** @param list<int> $missingDays
     *  @return array<string, int>
     */
    private function offDaysForSubjects(array $missingDays): array
    {
        $this->random->shuffleArray($missingDays);

        return array_combine(SchedulePolicy::singlePeriodSubjects(), $missingDays);
    }

    /** @param list<int> $items
     *  @return list<list<int>>
     */
    private function combinations(array $items, int $size): array
    {
        if ($size === 0) {
            return [[]];
        }

        $result = [];
        $this->combineInto($items, $size, 0, [], $result);

        return $result;
    }

    private function combineInto(array $items, int $size, int $offset, array $current, array &$result): void
    {
        if (count($current) === $size) {
            $result[] = $current;
            return;
        }

        for ($index = $offset; $index <= count($items) - ($size - count($current)); $index++) {
            $next = $current;
            $next[] = $items[$index];
            $this->combineInto($items, $size, $index + 1, $next, $result);
        }
    }
}
