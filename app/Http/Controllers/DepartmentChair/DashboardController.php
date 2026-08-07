<?php

namespace App\Http\Controllers\DepartmentChair;

use App\Http\Controllers\Controller;
use App\Models\PlantillaSubmission;
use App\Models\Section;
use App\Models\SystemConstant;
use App\Models\Teacher;
use App\Services\Curriculum\LoadCalculationService;
use Illuminate\Contracts\View\View;

class DashboardController extends Controller
{
    public function index(LoadCalculationService $loads): View
    {
        $department = auth()->user()->department;
        $schoolYear = SystemConstant::get('current_school_year');

        $teachers = Teacher::where('department_id', $department->id)
            ->orderBy('full_name')->get()
            ->map(fn ($teacher) => [
                'model' => $teacher,
                'load' => $loads->forTeacher($teacher, $schoolYear),
            ]);

        $coveredSectionIds = $department->teachers()
            ->join('teacher_section_assignments', 'teachers.id', '=', 'teacher_section_assignments.teacher_id')
            ->where('teacher_section_assignments.school_year', $schoolYear)
            ->distinct()->pluck('teacher_section_assignments.section_id');

        return view('chair.dashboard', [
            'submission' => PlantillaSubmission::currentFor($department->id),
            'teachers' => $teachers,
            'teacherCount' => $teachers->count(),
            'sectionsCovered' => $coveredSectionIds->count(),
            'totalSections' => Section::count(),
            'flaggedCount' => $teachers->filter(fn ($t) => ! empty($t['load']['flags']))->count(),
        ]);
    }
}
