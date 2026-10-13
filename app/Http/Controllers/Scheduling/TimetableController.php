<?php

namespace App\Http\Controllers\Scheduling;

use App\Enums\TimetableKind;
use App\Enums\TimetableStatus;
use App\Http\Controllers\Controller;
use App\Models\SystemConstant;
use App\Models\Teacher;
use App\Models\TeacherUnavailability;
use App\Models\TimetableVersion;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Services\Scheduling\ScheduleNotReadyException;
use App\Services\Scheduling\ScheduleReadinessService;
use App\Services\Scheduling\TimetableEditor;
use App\Services\Scheduling\TimetableExportService;
use App\Services\Scheduling\TimetableGenerationProgress;
use App\Services\Scheduling\TimetablePresentationService;
use App\Services\Scheduling\TimetableValidationService;
use App\Services\Scheduling\TimetableVersionService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class TimetableController extends Controller
{
    public function adminIndex(Request $request, ScheduleReadinessService $readiness)
    {
        $schoolYear = (string) SystemConstant::get('current_school_year', '2026-2027');
        $quarter = $this->quarter($request);
        $kind = $this->kind($request);
        $versions = TimetableVersion::query()
            ->where('school_year', $schoolYear)->where('quarter', $quarter)
            ->where('kind', $kind->value)
            ->with('createdBy:id,name')
            ->withCount(['issues as hard_issue_count' => fn ($query) => $query->where('severity', 'hard')])
            ->orderByDesc('version')->get();

        return view('scheduling.index', [
            'isAdmin' => true,
            'schoolYear' => $schoolYear,
            'quarter' => $quarter,
            'kind' => $kind,
            'versions' => $versions,
            'schedule' => $versions->first(),
            'readiness' => $readiness->check($schoolYear),
        ]);
    }

    public function chairIndex(Request $request)
    {
        $schoolYear = (string) SystemConstant::get('current_school_year', '2026-2027');
        $quarter = $this->quarter($request);
        $kind = $this->kind($request);
        $schedule = TimetableVersion::query()
            ->where('school_year', $schoolYear)
            ->where('quarter', $quarter)
            ->where('kind', $kind->value)
            ->where('status', TimetableStatus::Approved->value)
            ->orderByDesc('version')->first();

        return view('scheduling.index', [
            'isAdmin' => false,
            'schoolYear' => $schoolYear,
            'quarter' => $quarter,
            'kind' => $kind,
            'versions' => collect(),
            'schedule' => $schedule,
            'readiness' => null,
        ]);
    }

    public function adminShow(
        Request $request,
        TimetableVersion $timetable,
        TimetablePresentationService $presentation,
        TimetableValidationService $validation,
    ) {
        return $this->show($request, $timetable, $presentation, $validation, null, true);
    }

    public function progress(TimetableVersion $timetable, TimetableGenerationProgress $progress): JsonResponse
    {
        return response()->json($progress->read($timetable))->header('Cache-Control', 'no-store');
    }

    public function stop(TimetableVersion $timetable, TimetableVersionService $versions): RedirectResponse
    {
        try {
            $versions->stopGeneration($timetable, request()->user());
        } catch (DomainException $exception) {
            return back()->withErrors(['generation' => $exception->getMessage()]);
        }

        return back()->with('status', 'Generation stopped. Your existing schedules are unchanged.');
    }

    public function chairShow(
        Request $request,
        TimetableVersion $timetable,
        TimetablePresentationService $presentation,
        TimetableValidationService $validation,
    ) {
        abort_unless(
            $timetable->school_year === (string) SystemConstant::get('current_school_year', '2026-2027')
                && $timetable->status === TimetableStatus::Approved,
            404,
        );

        return $this->show($request, $timetable, $presentation, $validation, $request->user(), false);
    }

    private function show(
        Request $request,
        TimetableVersion $timetable,
        TimetablePresentationService $presentation,
        TimetableValidationService $validation,
        ?User $chair,
        bool $isAdmin,
    ) {
        if ($isAdmin && $timetable->status === TimetableStatus::Draft) {
            $validation->refresh($timetable);
        }

        $data = $presentation->prepare($timetable, $request, $chair);
        $hardIssues = $isAdmin
            ? $timetable->issues()->where('severity', 'hard')->with(['section:id,grade_level,name', 'teacher:id,full_name'])->orderBy('code')->get()
            : collect();

        return view('scheduling.show', array_merge($data, [
            'isAdmin' => $isAdmin,
            'timetable' => $timetable->load(['createdBy:id,name', 'approvedBy:id,name']),
            'hasEntries' => $timetable->entries()->exists(),
            'hardIssues' => $hardIssues,
            'hardIssueCount' => $isAdmin ? $hardIssues->count() : 0,
            'generationFailureSummary' => $presentation->generationFailureSummary($timetable->failure_message),
            'availability' => $isAdmin
                ? TeacherUnavailability::query()->where('school_year', $timetable->school_year)
                    ->where('quarter', $timetable->quarter)->with('teacher:id,full_name')
                    ->orderBy('day')->orderBy('period')->get()
                : collect(),
            'availabilityTeachers' => $isAdmin
                ? Teacher::query()->active()->with('department:id,name,code')->orderBy('full_name')->get(['id', 'full_name', 'department_id'])
                : collect(),
            'periodTimes' => collect($data['scheduleRows'])->where('type', 'subject')->pluck('time', 'period')->all(),
            'remediation' => [1 => 'Science', 2 => 'English', 3 => 'Mathematics', 4 => 'CLE & Filipino', 5 => 'Social Studies & TLE'],
        ]));
    }

    public function generate(Request $request, TimetableVersionService $versions): RedirectResponse
    {
        $input = $request->validate(['quarter' => ['required', 'integer', 'between:1,4'], 'kind' => ['sometimes', 'required', 'in:normal,special']]);
        $kind = TimetableKind::from($input['kind'] ?? 'normal');
        $schoolYear = (string) SystemConstant::get('current_school_year', '2026-2027');

        try {
            $timetable = $versions->startGeneration($schoolYear, (int) $input['quarter'], $request->user(), $kind);
        } catch (ScheduleNotReadyException $exception) {
            return redirect()->route('admin.schedules.index', ['quarter' => $input['quarter']] + ($kind === TimetableKind::Special ? ['kind' => $kind->value] : []))
                ->withErrors(['generation' => 'Generation is blocked until every readiness item is resolved.'])
                ->with('schedule_blockers', $exception->blockers);
        } catch (DomainException $exception) {
            return back()->withErrors(['generation' => $exception->getMessage()]);
        }

        return redirect()->route('admin.schedules.show', $timetable)->with('status', 'Your schedule request has been received. You can follow its progress below.');
    }

    public function approve(TimetableVersion $timetable, TimetableVersionService $versions): RedirectResponse
    {
        try {
            $versions->approve($timetable, request()->user());
        } catch (DomainException $exception) {
            return back()->withErrors(['approval' => $exception->getMessage()]);
        }

        return back()->with('status', 'Schedule approved and published for Department Chairs.');
    }

    public function clone(TimetableVersion $timetable, TimetableVersionService $versions): RedirectResponse
    {
        try {
            $draft = $versions->cloneApproved($timetable, request()->user());
        } catch (DomainException $exception) {
            return back()->withErrors(['schedule' => $exception->getMessage()]);
        }

        return redirect()->route('admin.schedules.show', $draft)->with('status', 'An editable draft copy was created.');
    }

    public function updateEntry(Request $request, TimetableVersion $timetable, int $entry, TimetableEditor $editor): JsonResponse
    {
        $input = $request->validate([
            'action' => ['required', 'in:replace,swap'],
            'expected_sequence' => ['required', 'integer', 'min:0'],
            'subject_code' => ['required_if:action,replace', 'in:ENG,MATH,SCI,CLE,FIL,SOC,TLE,MAPEH'],
            'target_day' => ['required_if:action,swap', 'integer', 'between:1,5'],
            'target_period' => ['required_if:action,swap', 'integer', 'between:1,'.$timetable->kind->periodsPerDay()],
        ]);

        try {
            $result = $editor->updateEntry($timetable, $entry, $input, $request->user());
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json($result + ['message' => 'Schedule change saved.']);
    }

    public function undo(Request $request, TimetableVersion $timetable, TimetableEditor $editor): JsonResponse
    {
        $input = $request->validate(['expected_sequence' => ['required', 'integer', 'min:0']]);
        try {
            $result = $editor->undo($timetable, (int) $input['expected_sequence'], $request->user());
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json($result + ['message' => 'The previous schedule change was undone.']);
    }

    public function addAvailability(Request $request, TimetableVersion $timetable, TimetableValidationService $validation, AuditLogService $audit): RedirectResponse
    {
        $input = $request->validate([
            'teacher_id' => ['required', 'integer', Rule::exists('teachers', 'id')->whereNull('merged_into_teacher_id')],
            'day' => ['required', 'integer', 'between:1,5'],
            'period' => ['required', 'integer', 'between:1,'.$timetable->kind->periodsPerDay()],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $teacher = Teacher::query()->findOrFail($input['teacher_id']);
        $attributes = [
            'teacher_id' => $teacher->id,
            'school_year' => $timetable->school_year,
            'quarter' => $timetable->quarter,
            'day' => $input['day'],
            'period' => $input['period'],
        ];
        $unavailability = TeacherUnavailability::query()->firstOrNew($attributes);
        if ($unavailability->exists) {
            return back()->withErrors(['availability' => 'That teacher is already marked unavailable in this slot.']);
        }
        $unavailability->reason = $input['reason'] ?? null;
        $unavailability->created_by_user_id = $request->user()->id;
        $unavailability->save();
        $audit->log('timetable.teacher_unavailable', $unavailability, null, $attributes + ['reason' => $unavailability->reason]);

        $this->refreshDraftIssues($timetable, $validation);

        return back()->with('status', 'Teacher availability saved. Draft errors have been refreshed.');
    }

    public function deleteAvailability(TimetableVersion $timetable, TeacherUnavailability $availability, TimetableValidationService $validation, AuditLogService $audit): RedirectResponse
    {
        abort_unless(
            $availability->school_year === $timetable->school_year && (int) $availability->quarter === (int) $timetable->quarter,
            404,
        );
        $before = $availability->only(['teacher_id', 'school_year', 'quarter', 'day', 'period', 'reason']);
        $availability->delete();
        $audit->log('timetable.teacher_availability_removed', $timetable, $before, null);
        $this->refreshDraftIssues($timetable, $validation);

        return back()->with('status', 'Teacher availability removed. Draft errors have been refreshed.');
    }

    private function refreshDraftIssues(TimetableVersion $timetable, TimetableValidationService $validation): void
    {
        TimetableVersion::query()
            ->where('school_year', $timetable->school_year)
            ->where('quarter', $timetable->quarter)
            ->where('status', TimetableStatus::Draft->value)
            ->get()->each(fn (TimetableVersion $draft) => $validation->refresh($draft));
    }

    public function adminExport(Request $request, TimetableVersion $timetable, string $format, TimetablePresentationService $presentation, TimetableExportService $exports): Response
    {
        return $this->export($request, $timetable, $format, $presentation, $exports, null);
    }

    public function chairExport(Request $request, TimetableVersion $timetable, string $format, TimetablePresentationService $presentation, TimetableExportService $exports): Response
    {
        abort_unless(
            $timetable->school_year === (string) SystemConstant::get('current_school_year', '2026-2027')
                && $timetable->status === TimetableStatus::Approved,
            404,
        );

        return $this->export($request, $timetable, $format, $presentation, $exports, $request->user());
    }

    private function export(Request $request, TimetableVersion $timetable, string $format, TimetablePresentationService $presentation, TimetableExportService $exports, ?User $chair): Response
    {
        abort_unless(in_array($format, ['pdf', 'xlsx'], true), 404);
        $data = $presentation->prepare($timetable, $request, $chair, true);
        $binary = $format === 'pdf'
            ? $exports->pdf($timetable, $data)
            : $exports->xlsx($timetable, $data);
        $fileName = 'JHS-SY-'.$timetable->school_year.'-Q'.$timetable->quarter.'-v'.$timetable->version;
        if ($timetable->kind === TimetableKind::Special) {
            $fileName .= '-SPECIAL';
        }
        if ($timetable->status !== TimetableStatus::Approved) {
            $fileName .= '-DRAFT';
        }

        return response($binary, 200, [
            'Content-Type' => $format === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$fileName.'.'.$format.'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function quarter(Request $request): int
    {
        $quarter = filter_var($request->query('quarter', 1), FILTER_VALIDATE_INT);

        return $quarter !== false && $quarter >= 1 && $quarter <= 4 ? $quarter : 1;
    }

    private function kind(Request $request): TimetableKind
    {
        $value = $request->query('kind', 'normal');

        return is_string($value) ? (TimetableKind::tryFrom($value) ?? TimetableKind::Normal) : TimetableKind::Normal;
    }
}
