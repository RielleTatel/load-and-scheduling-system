<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreTeacherRequest;
use App\Models\Department;
use App\Models\Teacher;
use App\Services\Audit\AuditLogService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * The school-wide teacher directory.
 *
 * Chairs only ever see their own department (SRS 3.2), so this is the only place
 * the full staff list exists. It is what plantilla imports are matched against:
 * without it, a re-uploaded sheet with a corrected spelling created a second
 * teacher and moved their load onto it.
 *
 * Teachers with no department are named on the registrar's roster but have no
 * plantilla yet — currently the English department, whose sheet is outstanding.
 */
class TeacherDirectoryController extends Controller
{
    public function __construct(private AuditLogService $audit) {}

    public function index(): View
    {
        $teachers = Teacher::with('department')
            ->withCount(['sectionAssignments', 'moderatorAssignments'])
            ->orderBy('full_name')->get();

        return view('admin.teachers.index', [
            'teachers' => $teachers,
            'unclaimed' => $teachers->whereNull('department_id'),
            'departments' => Department::orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        return view('admin.teachers.form', [
            'teacher' => new Teacher(),
            'departments' => Department::orderBy('name')->get(),
        ]);
    }

    public function store(StoreTeacherRequest $request): RedirectResponse
    {
        $teacher = Teacher::create($request->validated() + ['source' => 'manual']);
        $this->audit->log('teacher.created', $teacher, after: $teacher->only('full_name', 'department_id'));

        return redirect()->route('admin.teachers.index')->with('status', "{$teacher->full_name} added to the directory.");
    }

    public function edit(Teacher $teacher): View
    {
        return view('admin.teachers.form', [
            'teacher' => $teacher,
            'departments' => Department::orderBy('name')->get(),
        ]);
    }

    public function update(StoreTeacherRequest $request, Teacher $teacher): RedirectResponse
    {
        $before = $teacher->only('full_name', 'department_id', 'employment_status');
        $teacher->update($request->validated());
        $this->audit->log('teacher.updated', $teacher, before: $before, after: $teacher->only('full_name', 'department_id', 'employment_status'));

        return redirect()->route('admin.teachers.index')->with('status', "{$teacher->full_name} updated.");
    }
}
