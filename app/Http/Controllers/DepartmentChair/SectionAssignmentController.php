<?php

namespace App\Http\Controllers\DepartmentChair;

use App\Enums\GradeLevel;
use App\Http\Controllers\Controller;
use App\Http\Requests\Chair\StoreSectionAssignmentRequest;
use App\Models\ClassModeratorAssignment;
use App\Models\HonorsClassAssignment;
use App\Models\PlantillaSubmission;
use App\Models\Section;
use App\Models\SystemConstant;
use App\Models\Teacher;
use App\Models\TeacherSectionAssignment;
use App\Services\Audit\AuditLogService;
use App\Services\Curriculum\SectionAssignmentService;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SectionAssignmentController extends Controller
{
    public function __construct(
        private SectionAssignmentService $assignments,
        private AuditLogService $audit,
    ) {}

    public function index(): View
    {
        $department = auth()->user()->department;
        $schoolYear = SystemConstant::get('current_school_year');

        $teachers = Teacher::where('department_id', $department->id)->orderBy('full_name')->get();
        $sections = Section::orderBy('grade_level')->orderBy('name')->get()->groupBy(fn ($s) => $s->grade_level->value);

        $subjectBySection = TeacherSectionAssignment::where('department_id', $department->id)
            ->where('school_year', $schoolYear)->with('teacher')->get()->keyBy('section_id');
        $moderatorBySection = ClassModeratorAssignment::where('school_year', $schoolYear)
            ->with('teacher')->get()->keyBy('section_id');
        $honorsBySection = HonorsClassAssignment::where('school_year', $schoolYear)
            ->whereIn('teacher_id', $teachers->pluck('id'))->with('teacher')->get()->keyBy('section_id');

        return view('chair.assignments.index', [
            'department' => $department,
            'submission' => PlantillaSubmission::currentFor($department->id),
            'teachers' => $teachers,
            'sections' => $sections,
            'grades' => GradeLevel::cases(),
            'subjectBySection' => $subjectBySection,
            'moderatorBySection' => $moderatorBySection,
            'honorsBySection' => $honorsBySection,
        ]);
    }

    public function store(StoreSectionAssignmentRequest $request): RedirectResponse
    {
        return $this->run(fn () => $this->assignments->assign(
            Teacher::find($request->validated('teacher_id')),
            Section::find($request->validated('section_id')),
        ), 'teacher_id', 'Section assigned.');
    }

    public function storeModerator(StoreSectionAssignmentRequest $request): RedirectResponse
    {
        return $this->run(fn () => $this->assignments->assignModerator(
            Teacher::find($request->validated('teacher_id')),
            Section::find($request->validated('section_id')),
        ), 'teacher_id', 'Moderator assigned.');
    }

    public function storeHonors(StoreSectionAssignmentRequest $request): RedirectResponse
    {
        return $this->run(fn () => $this->assignments->assignHonors(
            Teacher::find($request->validated('teacher_id')),
            Section::find($request->validated('section_id')),
        ), 'teacher_id', "Honor's Class assigned.");
    }

    public function destroy(Request $request): RedirectResponse
    {
        $this->authorizeEditable();
        TeacherSectionAssignment::where('id', $request->input('assignment_id'))
            ->where('department_id', auth()->user()->department_id)->delete();

        return back()->with('status', 'Assignment removed.');
    }

    public function destroyModerator(Request $request): RedirectResponse
    {
        $this->authorizeEditable();
        $moderator = ClassModeratorAssignment::find($request->input('assignment_id'));
        if ($moderator && $this->ownsTeacher($moderator->teacher_id)) {
            $moderator->delete();
        }

        return back()->with('status', 'Moderator removed.');
    }

    public function destroyHonors(Request $request): RedirectResponse
    {
        $this->authorizeEditable();
        $honors = HonorsClassAssignment::find($request->input('assignment_id'));
        if ($honors && $this->ownsTeacher($honors->teacher_id)) {
            $honors->delete();
        }

        return back()->with('status', "Honor's Class removed.");
    }

    /**
     * Run an assignment action, mapping a DomainException to a field error.
     */
    private function run(callable $action, string $field, string $success): RedirectResponse
    {
        $this->authorizeEditable();

        try {
            $result = $action();
            $this->audit->log('assignment.changed', $result);
        } catch (DomainException $e) {
            return back()->withErrors([$field => $this->message($e->getMessage())]);
        }

        return back()->with('status', $success);
    }

    private function message(string $code): string
    {
        return match ($code) {
            'section_taken' => 'Another teacher in your department already teaches that section.',
            'moderator_taken' => 'That section already has a class moderator.',
            'no_honors_class' => "Your department doesn't have an Honor's Class column.",
            default => 'That assignment could not be made.',
        };
    }

    private function authorizeEditable(): void
    {
        $this->authorize('update', PlantillaSubmission::currentFor(auth()->user()->department_id));
    }

    private function ownsTeacher(int $teacherId): bool
    {
        return Teacher::where('id', $teacherId)
            ->where('department_id', auth()->user()->department_id)->exists();
    }
}
