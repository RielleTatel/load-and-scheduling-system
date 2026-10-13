<?php

namespace App\Http\Controllers\DepartmentChair;

use App\Http\Controllers\Controller;
use App\Http\Requests\Chair\StoreTeacherRequest;
use App\Models\PlantillaSubmission;
use App\Models\SystemConstant;
use App\Models\Teacher;
use App\Models\TeacherIdentityCase;
use App\Services\Audit\AuditLogService;
use App\Services\Curriculum\LoadCalculationService;
use App\Services\Teachers\IdentityObservation;
use App\Services\Teachers\IdentityOutcome;
use App\Services\Teachers\TeacherIdentityService;
use App\Services\Teachers\TeacherReconciliationService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TeacherController extends Controller
{
    public function __construct(
        private LoadCalculationService $loads,
        private AuditLogService $audit,
        private TeacherIdentityService $identities,
        private TeacherReconciliationService $reconciliation,
    ) {}

    public function index(LoadCalculationService $loads): View
    {
        $department = auth()->user()->department;
        $schoolYear = SystemConstant::get('current_school_year');

        $teachers = Teacher::active()->where('department_id', $department->id)
            ->orderBy('full_name')->get()
            ->map(fn ($teacher) => [
                'model' => $teacher,
                'load' => $loads->forTeacher($teacher, $schoolYear),
                'cases' => $this->identities->openCasesFor($teacher->id),
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
            'source' => IdentityObservation::MANUAL,
        ]);
        $observation = new IdentityObservation(
            IdentityObservation::MANUAL, "manual:teacher:{$teacher->id}", $teacher->full_name, $teacher->department_id, 'Added by the Department Chair',
        );
        $this->identities->recordObservation($observation, $teacher);
        $this->audit->log('teacher.created', $teacher, after: $teacher->only('full_name', 'employment_status'));

        // A look-alike of an existing teacher is added, so loads can be encoded,
        // but held for an Admin to decide whether it is a duplicate.
        $similar = $this->identities->similarCandidates($teacher->full_name, $teacher->id);
        if ($similar->isNotEmpty()) {
            $this->identities->openCase($observation, IdentityOutcome::review(
                \App\Enums\IdentityCaseReason::PossibleMatch, $similar->pluck('id')->all(),
            ), $teacher);

            return redirect()->route('chair.teachers.index')->with('status', 'Teacher added.')
                ->with('warning', "{$teacher->full_name} resembles a teacher already in the directory. An Admin will confirm they are a different person.");
        }

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
     * The Chair attests that this record's name and department are right.
     * It is evidence for an Admin's verification, not verification itself,
     * and it is allowed after submission: it changes no load.
     */
    public function confirmIdentity(Request $request, Teacher $teacher): RedirectResponse
    {
        $this->authorizeTeacher($teacher);
        $note = $request->validate(['note' => ['required', 'string', 'max:2000']])['note'];

        $this->reconciliation->chairConfirm($teacher, $request->user(), $note);

        return back()->with('status', "Confirmation recorded for {$teacher->full_name}.");
    }

    /** Context for an identity case that touches the Chair's department. */
    public function addCaseContext(Request $request, TeacherIdentityCase $identityCase): RedirectResponse
    {
        abort_unless($identityCase->isOpen() && $this->reconciliation->chairCanSee($identityCase, $request->user()), 404);
        $note = $request->validate(['note' => ['required', 'string', 'max:2000']])['note'];

        $this->reconciliation->addChairContext($identityCase, $request->user(), $note);

        return back()->with('status', 'Your note was added for the Admin.');
    }

    /**
     * 404 if the teacher isn't in the requesting chair's department.
     */
    private function authorizeTeacher(Teacher $teacher): void
    {
        abort_unless($teacher->department_id === auth()->user()->department_id && ! $teacher->isMerged(), 404);
    }
}
