<?php

namespace App\Http\Controllers\DepartmentChair;

use App\Http\Controllers\Controller;
use App\Http\Requests\Chair\StoreTeacherRequest;
use App\Models\PlantillaSubmission;
use App\Models\SystemConstant;
use App\Models\Teacher;
use App\Services\Audit\AuditLogService;
use App\Services\Curriculum\LoadCalculationService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class TeacherController extends Controller
{
    public function __construct(
        private LoadCalculationService $loads,
        private AuditLogService $audit,
    ) {}

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

        return view('chair.teachers.index', [
            'teachers' => $teachers,
            'submission' => PlantillaSubmission::currentFor($department->id),
        ]);
    }

    public function create(): View
    {
        return view('chair.teachers.form', ['teacher' => new Teacher()]);
    }

    public function store(StoreTeacherRequest $request): RedirectResponse
    {
        $submission = PlantillaSubmission::currentFor(auth()->user()->department_id);
        $this->authorize('update', $submission);

        $teacher = Teacher::create([
            'full_name' => $request->validated('full_name'),
            'employment_status' => $request->validated('employment_status'),
            'department_id' => auth()->user()->department_id,
        ]);
        $this->audit->log('teacher.created', $teacher, after: $teacher->only('full_name', 'employment_status'));

        return redirect()->route('chair.teachers.index')->with('status', 'Teacher added.');
    }

    public function edit(Teacher $teacher): View
    {
        $this->authorizeTeacher($teacher);

        return view('chair.teachers.form', ['teacher' => $teacher]);
    }

    public function update(StoreTeacherRequest $request, Teacher $teacher): RedirectResponse
    {
        $this->authorizeTeacher($teacher);
        $submission = PlantillaSubmission::currentFor(auth()->user()->department_id);
        $this->authorize('update', $submission);

        $before = $teacher->only('full_name', 'employment_status');
        $teacher->update($request->validated());
        $this->audit->log('teacher.updated', $teacher, $before, $teacher->only('full_name', 'employment_status'));

        return redirect()->route('chair.teachers.index')->with('status', 'Teacher updated.');
    }

    /**
     * 404 if the teacher isn't in the requesting chair's department.
     */
    private function authorizeTeacher(Teacher $teacher): void
    {
        abort_unless($teacher->department_id === auth()->user()->department_id, 404);
    }
}
