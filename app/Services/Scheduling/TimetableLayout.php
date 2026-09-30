<?php

namespace App\Services\Scheduling;

use Illuminate\Support\Collection;

final class TimetableLayout
{
    /** @var list<string> */
    private const GRADE_ORDER = ['G7', 'G8', 'G9', 'G10'];

    /** @var array<int, string> */
    private const PERIOD_TIMES = [
        1 => '7:30 - 8:20',
        2 => '8:20 - 9:10',
        3 => '9:50 - 10:40',
        4 => '10:40 - 11:30',
        5 => '11:30 - 12:20',
        6 => '1:20 - 2:10',
        7 => '2:10 - 3:00',
    ];

    /** @var array<int, string> */
    private const REMEDIATION = [
        1 => 'Science',
        2 => 'English',
        3 => 'Mathematics',
        4 => 'CLE & Filipino',
        5 => 'Social Studies & TLE',
    ];

    /** @var array<string, string> */
    private const SUBJECT_NAMES = [
        'ENG' => 'English',
        'MATH' => 'Mathematics',
        'SCI' => 'Science and Technology',
        'CLE' => 'CLE',
        'FIL' => 'Filipino',
        'SOC' => 'Social Studies',
        'TLE' => 'TLE',
        'MAPEH' => 'Music, Arts, Physical Education and Health',
    ];

    /** @return list<string> */
    public function gradeOrder(): array
    {
        return self::GRADE_ORDER;
    }

    /**
     * @return list<array{type:string,time:string,label:string,period:?int}>
     */
    public function rows(int $day): array
    {
        $rows = [];
        foreach (self::PERIOD_TIMES as $period => $time) {
            $rows[] = ['type' => 'subject', 'time' => $time, 'label' => '', 'period' => $period];

            if ($period === 2) {
                $rows[] = ['type' => 'break', 'time' => '9:10 - 9:50', 'label' => 'RECESS', 'period' => null];
            } elseif ($period === 5) {
                $rows[] = ['type' => 'break', 'time' => '12:20 - 1:20', 'label' => 'LUNCH', 'period' => null];
            }
        }

        $rows[] = ['type' => 'post_class', 'time' => '3:00 - 3:05', 'label' => 'EXAMEN', 'period' => null];
        $rows[] = ['type' => 'post_class', 'time' => '3:05 - 3:20', 'label' => 'CLASSROOM CLEAN-UP (CC)', 'period' => null];
        $rows[] = [
            'type' => 'post_class',
            'time' => '3:20 - 4:10',
            'label' => 'REMEDIATION ('.(self::REMEDIATION[$day] ?? 'To be announced').')',
            'period' => null,
        ];

        return $rows;
    }

    public function subjectName(string $code, ?string $fallback = null): string
    {
        return self::SUBJECT_NAMES[strtoupper($code)] ?? $fallback ?? strtoupper($code);
    }

    /**
     * @param Collection<int, mixed> $sections
     * @return array<string, Collection<int, mixed>>
     */
    public function groupSections(Collection $sections): array
    {
        $rank = array_flip(self::GRADE_ORDER);
        $sorted = $sections->sortBy(function ($section) use ($rank): string {
            $grade = is_object($section->grade_level) ? $section->grade_level->value : (string) $section->grade_level;

            return sprintf('%02d|%s', $rank[$grade] ?? 99, mb_strtolower((string) $section->name));
        })->values();

        $groups = [];
        foreach (self::GRADE_ORDER as $grade) {
            $group = $sorted->filter(function ($section) use ($grade): bool {
                $value = is_object($section->grade_level) ? $section->grade_level->value : (string) $section->grade_level;

                return $value === $grade;
            })->values();
            if ($group->isNotEmpty()) {
                $groups[$grade] = $group;
            }
        }

        return $groups;
    }
}
