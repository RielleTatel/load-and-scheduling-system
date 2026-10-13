<?php

namespace App\Http\Controllers\Admin;

use App\Enums\IdentityDecision;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ResolveIdentityCaseRequest;
use App\Models\TeacherIdentityCase;
use App\Services\Teachers\TeacherIdentityService;
use App\Services\Teachers\TeacherReconciliationService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * Questions about who a written name refers to, waiting for an Admin.
 *
 * Imports that raised them stay staged until each is answered; the answer
 * and its reasoning stay on the case afterwards.
 */
class IdentityCaseController extends Controller
{
    public function __construct(
        private TeacherIdentityService $identities,
        private TeacherReconciliationService $reconciliation,
    ) {}

    public function index(): View
    {
        $cases = TeacherIdentityCase::with(['observedDepartment', 'subjectTeacher', 'resolvedBy'])
            ->orderByRaw("CASE WHEN status = 'open' THEN 0 ELSE 1 END")
            ->latest('id')->get();

        return view('admin.identity-cases.index', [
            'open' => $cases->filter->isOpen(),
            'resolved' => $cases->reject->isOpen()->take(50),
            'describe' => fn (TeacherIdentityCase $case) => $this->identities->describe($case),
        ]);
    }

    public function show(TeacherIdentityCase $identityCase): View
    {
        return view('admin.identity-cases.show', [
            'case' => $identityCase->load(['observedDepartment', 'subjectTeacher.department', 'resolvedBy', 'resolutionTeacher']),
            'candidates' => $identityCase->candidates()->loadCount(['sectionAssignments', 'moderatorAssignments']),
            'observations' => $this->reconciliation->observationsFor($identityCase),
            'description' => $this->identities->describe($identityCase),
            'version' => $this->reconciliation->version($identityCase),
            'decisions' => $this->decisionsFor($identityCase),
        ]);
    }

    public function resolve(ResolveIdentityCaseRequest $request, TeacherIdentityCase $identityCase): RedirectResponse
    {
        $this->reconciliation->resolve(
            $identityCase,
            IdentityDecision::from($request->validated('decision')),
            $request->validated('teacher_id') ? (int) $request->validated('teacher_id') : null,
            $request->validated('note'),
            $request->validated('version'),
            $request->user(),
        );

        return redirect()->route('admin.identity-cases.show', $identityCase)
            ->with('status', 'Decision recorded. The staged import can now be confirmed again.');
    }

    /** @return array<string, string> decision value => label */
    private function decisionsFor(TeacherIdentityCase $case): array
    {
        return match ($case->reason) {
            \App\Enums\IdentityCaseReason::PossibleMatch, \App\Enums\IdentityCaseReason::AmbiguousMatch => $case->subject_teacher_id
                ? ['distinct' => 'Different people — keep both records']
                : ['same' => 'Same person as the chosen teacher', 'distinct' => 'A different person — create a new record'],
            \App\Enums\IdentityCaseReason::DepartmentConflict => [
                'keep_department' => 'Same person — their current department stands',
                'use_observed_department' => 'Same person — move them to the department this source names',
                'distinct' => 'A different person — create a new record',
            ],
            \App\Enums\IdentityCaseReason::DirectoryAbsence => ['absence_explained' => 'Investigated — record the outcome'],
        };
    }
}
