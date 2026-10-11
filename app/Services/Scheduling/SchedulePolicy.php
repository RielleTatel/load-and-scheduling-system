<?php

namespace App\Services\Scheduling;

final class SchedulePolicy
{
    public const DAYS = 5;

    public const PERIODS_PER_DAY = 7;

    /** @var list<list<int>> */
    public const DOUBLE_BLOCKS = [[1, 2], [3, 4], [4, 5], [6, 7]];

    /** @return array<string, int> */
    public static function periodCounts(string $gradeLevel): array
    {
        return [
            'ENG' => 5,
            'MATH' => 5,
            'SCI' => 5,
            'CLE' => 4,
            'FIL' => 4,
            'SOC' => 4,
            'TLE' => 4,
            'MAPEH' => 4,
        ];
    }

    /** @return list<string> */
    public static function singlePeriodSubjects(): array
    {
        return ['CLE', 'FIL', 'SOC'];
    }

    public static function allowsDoubleBlock(string $gradeLevel, string $subjectCode): bool
    {
        return in_array($subjectCode, ['TLE', 'MAPEH'], true)
            || ($gradeLevel === 'G10' && $subjectCode === 'ENG');
    }

    /** @return list<int> */
    public static function requiredDailyShape(string $gradeLevel, string $subjectCode): array
    {
        return $gradeLevel === 'G10' && $subjectCode === 'ENG'
            ? [1, 2, 2]
            : [1, 1, 2];
    }

    /** @return list<int> */
    public static function doubleBlockStarts(): array
    {
        return array_map(fn (array $periods) => $periods[0], self::DOUBLE_BLOCKS);
    }
}
