<?php

namespace App\Http\Controllers\Admin;

use App\Enums\TeacherVerification;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreTeacherRequest;
use App\Http\Requests\Admin\VerifyTeacherRequest;
use App\Models\Department;
use App\Models\Teacher;
use App\Models\TeacherIdentityCase;
use App\Services\Audit\AuditLogService;
use App\Services\Teachers\IdentityObservation;
use App\Services\Teachers\TeacherIdentityService;
use App\Services\Teachers\TeacherReconciliationService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The school-wide teacher directory.
 *
 * Chairs only ever see their own department (SRS 3.2), so this is the only place
 * the full staff list exists. It is what plantilla imports are matched against:
 * without it, a re-uploaded sheet with a corrected spelling created a second
 * teacher and moved their load onto it.
 *
 * Teachers with no department are named on the registrar's roster but have no
 * plantilla yet. Every record starts pending verification; an Admin verifies
 * it from a reviewed plantilla and the Chair's confirmation, or another source.
 */
class TeacherDirectoryController extends Controller
{
    public function __construct(
        private AuditLogService $audit,
        private TeacherIdentityService $identities,
        private TeacherReconciliationService $reconciliation,
    ) {}

    public function index(Request $request): View
    {
        $all = Teacher::active()->with('department')
            ->withCount(['sectionAssignments', 'moderatorAssignments'])
            ->orderBy('full_name')->get();

        $filter = TeacherVerification::tryFrom((string) $request->query('verification'));

        return view('admin.teachers.index', [
            'teachers' => $filter ? $all->where('verification_status', $filter)->values() : $all,
            'all' => $all,
            'filter' => $filter,
            'unclaimed' => $all->whereNull('department_id'),
            'openCases' => TeacherIdentityCase::open()->count(),
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
        $teacher = Teacher::create($request->safe()->except('confirm_distinct') + ['source' => 'manual']);
        $this->identities->recordObservation(new IdentityObservation(
            IdentityObservation::MANUAL, "manual:teacher:{$teacher->id}", $teacher->full_name, $teacher->department_id, 'Added by an Admin',
        ), $teacher);
        $this->audit->log('teacher.created', $teacher, after: $teacher->only('full_name', 'department_id') + [
            'confirmed_distinct_from_similar' => $request->boolean('confirm_distinct'),
        ]);

        return redirect()->route('admin.teachers.index')->with('status', "{$teacher->full_name} added to the directory.");
    }

    public function edit(Teacher $teacher): View
    {
        abort_if($teacher->isMerged(), 404);

        return view('admin.teachers.form', [
            'teacher' => $teacher->load(['verifiedBy', 'chairConfirmedBy', 'observations']),
            'departments' => Department::orderBy('name')->get(),
            'openCases' => $this->identities->openCasesFor($teacher->id),
        ]);
    }

    public function update(StoreTeacherRequest $request, Teacher $teacher): RedirectResponse
    {
        abort_if($teacher->isMerged(), 404);

        $before = $teacher->only('full_name', 'department_id', 'employment_status', 'verification_status');
        $teacher->update($request->safe()->except('confirm_distinct'));
        $this->audit->log('teacher.updated', $teacher, before: $before, after: $teacher->only('full_name', 'department_id', 'employment_status', 'verification_status'));

        return redirect()->route('admin.teachers.index')->with('status', "{$teacher->full_name} updated.");
    }

    public function verify(VerifyTeacherRequest $request, Teacher $teacher): RedirectResponse
    {
        $this->reconciliation->verify($teacher, $request->validated('basis'), $request->validated('note'), $request->user());

        return redirect()->route('admin.teachers.edit', $teacher)->with('status', "{$teacher->full_name} verified.");
    }
}
