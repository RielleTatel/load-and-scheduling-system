<?php

namespace App\Services\Scheduling;

final class StandardTimetable
{
    public function entries(array $sections): array
    {
        $reference = json_decode(file_get_contents(resource_path('data/jhs-standard-timetable.json')), true, flags: JSON_THROW_ON_ERROR);
        $entries = [];
        foreach ($sections as $section) {
            $name = match ($section['grade_level'].':'.$section['name']) {
                'G7:De Britto' => 'De Brito',
                'G8:Ignatius of Loyola' => 'Ignatius',
                default => $section['name'],
            };
            $days = $reference['grades'][$section['grade_level']][$name] ?? [];
            foreach ($days as $day => $subjects) {
                foreach ($subjects as $period => $code) {
                    $entries[] = [
                        'section_id' => $section['id'], 'department_code' => $code,
                        'day' => $day + 1, 'period' => $period + 1,
                    ];
                }
            }
        }

        return $entries;
    }
}
