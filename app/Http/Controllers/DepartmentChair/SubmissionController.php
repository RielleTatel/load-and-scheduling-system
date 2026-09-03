<?php

namespace App\Http\Controllers\DepartmentChair;

use App\Http\Controllers\Controller;
use App\Models\PlantillaSubmission;
use App\Models\SystemConstant;
use App\Models\Teacher;
use App\Services\Curriculum\LoadCalculationService;
use App\Services\Plantilla\SubmissionService;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class SubmissionController extends Controller
{
    public function show(LoadCalculationService $loads): View
    {
        $department = auth()->user()->department;
        $schoolYear = SystemConstant::get('current_school_year');

        $teachers = Teacher::where('department_id', $department->id)
            ->orderBy('full_name')->get()
            ->map(fn ($teacher) => [
                'model' => $teacher,
                'load' => $loads->forTeacher($teacher, $schoolYear),
            ]);

        return view('chair.submission.show', [
            'submission' => PlantillaSubmission::currentFor($department->id)->load('returnedBy'),
            'teachers' => $teachers,
        ]);
    }

    public function store(SubmissionService $submissions): RedirectResponse
    {
        $submission = PlantillaSubmission::currentFor(auth()->user()->department_id);
        $this->authorize('update', $submission);

        try {
            $submissions->submit($submission, auth()->user());
        } catch (DomainException $e) {
            return back()->with('warning', $e->getMessage());
        }

        return redirect()->route('chair.dashboard')
            ->with('status', 'Dataset submitted for review. Editing is now locked.');
    }
}
