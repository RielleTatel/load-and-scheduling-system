<?php

namespace App\Services\Teachers;

use App\Enums\IdentityCaseStatus;
use App\Enums\IdentityDecision;
use App\Models\ClassModeratorAssignment;
use App\Models\HonorsClassAssignment;
use App\Models\ServiceLoad;
use App\Models\Teacher;
use App\Models\TeacherAlias;
use App\Models\TeacherIdentityCase;
use App\Models\TeacherObservation;
use App\Models\TeacherOtherAssignment;
use App\Models\TeacherSectionAssignment;
use App\Models\TeacherUnavailability;
use App\Models\TimetableEntry;
use App\Models\TimetableValidationIssue;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Folds a duplicate teacher record into the one that survives.
 *
 * The survivor keeps its ID. Every reference to the redundant record moves:
 * the five assignment families across all school years, and the scheduling
 * tables, because it is the same person. Values that cannot both be true —
 * two different service loads for one year — need an Admin's choice; nothing
 * is added together or silently overwritten. The redundant record stays, marked
 * merged, so its history and source names remain traceable.
 */
class TeacherMergeService
{
    public function __construct(
        private TeacherIdentityService $identities,
        private AuditLogService $audit,
    ) {}

    /**
     * What a merge would do, and what blocks it.
     *
     * @return array{survivor:Teacher, redundant:Teacher, department_id:?int, moves:array<string,int>, collapses:array<string,int>, service_conflicts:array<string,array{survivor:float,redundant:float}>, blockers:list<string>, token:string}
     */
    public function preview(Teacher $redundant, Teacher $survivor): array
    {
        $blockers = [];

        if ($redundant->is($survivor)) {
            $blockers[] = 'Choose a different teacher to keep.';
        }
        if ($redundant->isMerged() || $survivor->isMerged()) {
            $blockers[] = 'One of these records was already merged.';
        }
        if ($redundant->department_id && $survivor->department_id && $redundant->department_id !== $survivor->department_id) {
            $blockers[] = 'The two records are in different departments. Settle the department first (edit one record or decide its identity case).';
        }

        $departmentId = $survivor->department_id ?? $redundant->department_id;

        $foreignLoads = TeacherSectionAssignment::where('teacher_id', $redundant->id)
            ->when($departmentId, fn ($q) => $q->where('department_id', '!=', $departmentId))->count();
        if ($departmentId && $foreignLoads) {
            $blockers[] = "{$foreignLoads} section load(s) on the duplicate belong to another department. Reassign them first.";
        }

        $doubleBooked = $this->doubleBookings($redundant, $survivor);
        if ($doubleBooked) {
            $blockers[] = "Both records are scheduled in the same period in {$doubleBooked} timetable slot(s). Resolve that double booking first.";
        }

        $serviceConflicts = [];
        $redundantService = ServiceLoad::where('teacher_id', $redundant->id)->get()->keyBy('school_year');
        foreach (ServiceLoad::where('teacher_id', $survivor->id)->get() as $load) {
            $other = $redundantService->get($load->school_year);
            if ($other && (float) $other->hours !== (float) $load->hours) {
                $serviceConflicts[$load->school_year] = ['survivor' => (float) $load->hours, 'redundant' => (float) $other->hours];
            }
        }

        $moves = [
            'Section loads' => TeacherSectionAssignment::where('teacher_id', $redundant->id)->count(),
            'Class moderator assignments' => ClassModeratorAssignment::where('teacher_id', $redundant->id)->count(),
            "Honor's Class assignments" => HonorsClassAssignment::where('teacher_id', $redundant->id)->count(),
            'Service loads' => $redundantService->count(),
            'Other assignments' => TeacherOtherAssignment::where('teacher_id', $redundant->id)->count(),
            'Timetable entries' => TimetableEntry::where('teacher_id', $redundant->id)->count(),
            'Unavailable periods' => TeacherUnavailability::where('teacher_id', $redundant->id)->count(),
            'Source observations' => TeacherObservation::where('teacher_id', $redundant->id)->count(),
        ];

        $collapses = [
            'Service loads already recorded identically' => $redundantService->filter(fn ($l) => ServiceLoad::where('teacher_id', $survivor->id)
                ->where('school_year', $l->school_year)->where('hours', $l->hours)->exists())->count(),
            'Other assignments the survivor already holds' => TeacherOtherAssignment::where('teacher_id', $redundant->id)->get()
                ->filter(fn ($a) => $this->holdsRole($survivor->id, $a))->count(),
            'Unavailable periods the survivor already has' => TeacherUnavailability::where('teacher_id', $redundant->id)->get()
                ->filter(fn ($u) => $this->hasSlot($survivor->id, $u))->count(),
        ];

        return [
            'survivor' => $survivor,
            'redundant' => $redundant,
            'department_id' => $departmentId,
            'moves' => $moves,
            'collapses' => $collapses,
            'service_conflicts' => $serviceConflicts,
            'blockers' => $blockers,
            'token' => $this->token($redundant, $survivor),
        ];
    }

    /**
     * Apply a previewed merge atomically.
     *
     * @param  array<string, string>  $serviceChoices  school year => 'survivor' | 'redundant'
     *
     * @throws ValidationException
     */
    public function merge(Teacher $redundant, Teacher $survivor, string $token, array $serviceChoices, string $note, User $admin): Teacher
    {
        return DB::transaction(function () use ($redundant, $survivor, $token, $serviceChoices, $note, $admin) {
            $redundant = Teacher::lockForUpdate()->findOrFail($redundant->id);
            $survivor = Teacher::lockForUpdate()->findOrFail($survivor->id);

            // A retried confirmation of a merge that already happened.
            if ($redundant->merged_into_teacher_id === $survivor->id) {
                return $survivor;
            }

            if (! hash_equals($this->token($redundant, $survivor), $token)) {
                throw ValidationException::withMessages(['token' => 'These records changed since the preview. Review the merge again.']);
            }

            $preview = $this->preview($redundant, $survivor);
            if ($preview['blockers']) {
                throw ValidationException::withMessages(['token' => $preview['blockers']]);
            }

            foreach (array_keys($preview['service_conflicts']) as $year) {
                if (! in_array($serviceChoices[$year] ?? null, ['survivor', 'redundant'], true)) {
                    throw ValidationException::withMessages(['service' => "Choose which {$year} service load is correct."]);
                }
            }

            if (! $survivor->department_id && $redundant->department_id) {
                $survivor->update(['department_id' => $redundant->department_id]);
            }

            $this->moveAssignments($redundant, $survivor, $serviceChoices);
            $this->moveScheduling($redundant, $survivor);
            $this->moveIdentityRecords($redundant, $survivor, $admin, $note);

            $redundant->forceFill([
                'merged_into_teacher_id' => $survivor->id,
                'merged_at' => now(),
            ])->save();

            $this->audit->log('teacher.merged', $survivor, before: [
                'redundant_teacher_id' => $redundant->id,
                'redundant_name' => $redundant->full_name,
                'redundant_department_id' => $redundant->department_id,
            ], after: [
                'survivor_teacher_id' => $survivor->id,
                'moves' => $preview['moves'],
                'service_choices' => $serviceChoices,
                'note' => $note,
            ]);

            return $survivor;
        });
    }

    private function moveAssignments(Teacher $redundant, Teacher $survivor, array $serviceChoices): void
    {
        // Keyed by section and year, not teacher: these transfer without collisions.
        foreach ([TeacherSectionAssignment::class, ClassModeratorAssignment::class, HonorsClassAssignment::class] as $model) {
            $model::where('teacher_id', $redundant->id)->update(['teacher_id' => $survivor->id]);
        }

        foreach (ServiceLoad::where('teacher_id', $redundant->id)->get() as $load) {
            $kept = ServiceLoad::where('teacher_id', $survivor->id)->where('school_year', $load->school_year)->first();

            if (! $kept) {
                $load->update(['teacher_id' => $survivor->id]);

                continue;
            }

            if (($serviceChoices[$load->school_year] ?? null) === 'redundant') {
                $kept->update(['hours' => $load->hours]);
            }
            $load->delete();
        }

        foreach (TeacherOtherAssignment::where('teacher_id', $redundant->id)->get() as $assignment) {
            $this->holdsRole($survivor->id, $assignment)
                ? $assignment->delete()
                : $assignment->update(['teacher_id' => $survivor->id]);
        }
    }

    private function moveScheduling(Teacher $redundant, Teacher $survivor): void
    {
        TimetableEntry::where('teacher_id', $redundant->id)->update(['teacher_id' => $survivor->id]);
        TimetableValidationIssue::where('teacher_id', $redundant->id)->update(['teacher_id' => $survivor->id]);

        foreach (TeacherUnavailability::where('teacher_id', $redundant->id)->get() as $slot) {
            $this->hasSlot($survivor->id, $slot)
                ? $slot->delete()
                : $slot->update(['teacher_id' => $survivor->id]);
        }
    }

    /**
     * Observations keep their original names; aliases carry the redundant
     * spelling to the survivor; cases about the pair are closed as decided.
     */
    private function moveIdentityRecords(Teacher $redundant, Teacher $survivor, User $admin, string $note): void
    {
        TeacherObservation::where('teacher_id', $redundant->id)->update(['teacher_id' => $survivor->id]);

        foreach (TeacherAlias::where('teacher_id', $redundant->id)->get() as $alias) {
            TeacherAlias::firstOrCreate(
                ['teacher_id' => $survivor->id, 'normalized_name' => $alias->normalized_name],
                $alias->only('alias_name', 'identity_case_id', 'approved_by_user_id', 'note'),
            );
        }

        if ($redundant->normalized_name !== $survivor->normalized_name) {
            TeacherAlias::firstOrCreate(
                ['teacher_id' => $survivor->id, 'normalized_name' => $redundant->normalized_name],
                ['alias_name' => $redundant->full_name, 'approved_by_user_id' => $admin->id, 'note' => "Merged record: {$note}"],
            );
        }

        foreach (TeacherIdentityCase::open()->get() as $case) {
            if (! in_array($redundant->id, $case->involvedTeacherIds(), true)) {
                continue;
            }

            $pairCase = $case->subject_teacher_id === $redundant->id && in_array($survivor->id, $case->candidate_ids ?? [], true)
                || $case->subject_teacher_id === $survivor->id && in_array($redundant->id, $case->candidate_ids ?? [], true);

            if ($pairCase) {
                $case->forceFill([
                    'status' => IdentityCaseStatus::Resolved,
                    'decision' => IdentityDecision::Same,
                    'resolution_teacher_id' => $survivor->id,
                    'resolved_by_user_id' => $admin->id,
                    'resolved_at' => now(),
                    'resolution_note' => "Merged into {$survivor->full_name}: {$note}",
                ])->save();

                continue;
            }

            $candidates = collect($case->candidate_ids ?? [])
                ->map(fn ($id) => $id === $redundant->id ? $survivor->id : $id)->unique()->values()->all();
            $case->forceFill([
                'candidate_ids' => $candidates,
                'subject_teacher_id' => $case->subject_teacher_id === $redundant->id ? $survivor->id : $case->subject_teacher_id,
            ])->save();
        }

        $this->identities->restoreVerification($survivor->fresh());
    }

    private function holdsRole(int $teacherId, TeacherOtherAssignment $assignment): bool
    {
        return TeacherOtherAssignment::where('teacher_id', $teacherId)
            ->where('other_assignment_role_id', $assignment->other_assignment_role_id)
            ->where('school_year', $assignment->school_year)->exists();
    }

    private function hasSlot(int $teacherId, TeacherUnavailability $slot): bool
    {
        return TeacherUnavailability::where('teacher_id', $teacherId)
            ->where('school_year', $slot->school_year)->where('quarter', $slot->quarter)
            ->where('day', $slot->day)->where('period', $slot->period)->exists();
    }

    private function doubleBookings(Teacher $a, Teacher $b): int
    {
        return TimetableEntry::where('teacher_id', $a->id)->get()
            ->filter(fn (TimetableEntry $e) => TimetableEntry::where('teacher_id', $b->id)
                ->where('timetable_version_id', $e->timetable_version_id)
                ->where('day', $e->day)->where('period', $e->period)->exists())
            ->count();
    }

    /** Changes when either record or anything a merge would move changes. */
    private function token(Teacher $redundant, Teacher $survivor): string
    {
        $state = [];
        foreach ([$redundant, $survivor] as $teacher) {
            $state[] = [
                $teacher->id, $teacher->full_name, $teacher->department_id, $teacher->merged_into_teacher_id,
                TeacherSectionAssignment::where('teacher_id', $teacher->id)->count(),
                ClassModeratorAssignment::where('teacher_id', $teacher->id)->count(),
                HonorsClassAssignment::where('teacher_id', $teacher->id)->count(),
                ServiceLoad::where('teacher_id', $teacher->id)->orderBy('school_year')->get(['school_year', 'hours'])->toArray(),
                TeacherOtherAssignment::where('teacher_id', $teacher->id)->count(),
                TimetableEntry::where('teacher_id', $teacher->id)->count(),
                TeacherUnavailability::where('teacher_id', $teacher->id)->count(),
            ];
        }

        return hash('sha256', json_encode($state));
    }
}
