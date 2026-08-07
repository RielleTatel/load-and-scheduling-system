<?php

namespace App\Services\Curriculum;

use App\Models\SystemConstant;
use App\Models\Teacher;

class LoadCalculationService
{
    /**
     * Compute a teacher's load per the formula in the constraints doc §3,
     * reading all rates/divisors from system constants (never hard-coded).
     *
     * @return array{teaching_hours:float,nonteaching_hours:float,total_hours:float,overload_units:float,section_count:int,flags:string[]}
     */
    public function forTeacher(Teacher $teacher, string $schoolYear): array
    {
        $fullLoad = (float) SystemConstant::get('full_load_hours', 21);
        $divisor = (float) SystemConstant::get('overload_divisor', 3);

        $sectionAssignments = $teacher->sectionAssignments()->where('school_year', $schoolYear)->get();
        $sectionCount = $sectionAssignments->count();

        $teaching = (float) $sectionAssignments->sum('hours')
            + (float) $teacher->moderatorAssignments()->where('school_year', $schoolYear)->sum('hours')
            + (float) $teacher->honorsAssignments()->where('school_year', $schoolYear)->sum('hours');

        $serviceLoad = (float) $teacher->serviceLoads()->where('school_year', $schoolYear)->sum('hours');
        $hasServiceLoad = $teacher->serviceLoads()->where('school_year', $schoolYear)->exists();

        $equivalentHours = (float) $teacher->otherAssignments()
            ->where('school_year', $schoolYear)
            ->with('role')
            ->get()
            ->reject(fn ($assignment) => $assignment->role->is_honorarium)
            ->sum(fn ($assignment) => (float) $assignment->role->equivalent_hours);

        $nonteaching = $serviceLoad + $equivalentHours;
        $total = $teaching + $nonteaching;
        $overload = $divisor > 0 ? max(0, round(($total - $fullLoad) / $divisor, 2)) : 0.0;

        $flags = [];
        if ($sectionCount === 0) {
            $flags[] = 'zero_sections';
        }
        if (! $hasServiceLoad) {
            $flags[] = 'no_service_load';
        }
        if ($total > $fullLoad) {
            $flags[] = 'overloaded';
        } elseif ($total < $fullLoad) {
            $flags[] = 'below_full_load';
        }

        return [
            'teaching_hours' => round($teaching, 1),
            'nonteaching_hours' => round($nonteaching, 1),
            'total_hours' => round($total, 1),
            'overload_units' => $overload,
            'section_count' => $sectionCount,
            'flags' => $flags,
        ];
    }
}
