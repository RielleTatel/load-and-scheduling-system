<?php

namespace App\Services\Teachers;

use App\Enums\IdentityCaseReason;
use App\Enums\IdentityCaseStatus;
use App\Enums\IdentityDecision;
use App\Enums\TeacherVerification;
use App\Models\Department;
use App\Models\Teacher;
use App\Models\TeacherAlias;
use App\Models\TeacherIdentityCase;
use App\Models\TeacherObservation;
use App\Models\TeacherSectionAssignment;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The Admin's identity decisions: answering cases, verifying teachers, and
 * recording what an official directory says. Chairs contribute context and
 * confirmation; only an Admin decides.
 *
 * Every decision is attributable — who, when, why — through the case or
 * teacher record and the audit log.
 */
class TeacherReconciliationService
{
    public function __construct(
        private TeacherIdentityService $identities,
        private AuditLogService $audit,
    ) {}

    /**
     * A token that changes whenever the case or a teacher it questions
     * changes, so a decision made on an outdated screen is refused.
     */
    public function version(TeacherIdentityCase $case): string
    {
        $teachers = Teacher::whereIn('id', $case->involvedTeacherIds())->orderBy('id')
            ->get(['id', 'full_name', 'department_id', 'merged_into_teacher_id', 'updated_at'])
            ->map(fn (Teacher $t) => [$t->id, $t->full_name, $t->department_id, $t->merged_into_teacher_id, $t->updated_at?->toIso8601String()]);

        return hash('sha256', json_encode([
            $case->status->value, $case->candidate_ids, $case->observed_name, $case->observed_department_id, $teachers,
        ]));
    }

    /**
     * @throws ValidationException
     */
    public function resolve(TeacherIdentityCase $case, IdentityDecision $decision, ?int $teacherId, string $note, string $version, User $admin): TeacherIdentityCase
    {
        return DB::transaction(function () use ($case, $decision, $teacherId, $note, $version, $admin) {
            $case = TeacherIdentityCase::lockForUpdate()->findOrFail($case->id);

            if (! $case->isOpen()) {
                throw ValidationException::withMessages(['decision' => 'This case was already decided.']);
            }

            if (! hash_equals($this->version($case), $version)) {
                throw ValidationException::withMessages(['decision' => 'The teachers in this case changed since the page was opened. Review it again before deciding.']);
            }

            $teacher = $this->decide($case, $decision, $teacherId, $note, $admin);

            $case->forceFill([
                'status' => IdentityCaseStatus::Resolved,
                'decision' => $decision,
                'resolution_teacher_id' => $teacher?->id,
                'resolved_by_user_id' => $admin->id,
                'resolved_at' => now(),
                'resolution_note' => $note,
            ])->save();

            foreach ($case->involvedTeacherIds() as $id) {
                $this->identities->restoreVerification(Teacher::find($id));
            }

            $this->audit->log('identity_case.resolved', $case, after: [
                'decision' => $decision->value,
                'teacher_id' => $teacher?->id,
                'observed_name' => $case->observed_name,
                'note' => $note,
            ]);

            return $case;
        });
    }

    /** A Chair's account of who a name in their department refers to. */
    public function addChairContext(TeacherIdentityCase $case, User $chair, string $note): void
    {
        $context = $case->chair_context ?? [];
        $context[] = [
            'user_id' => $chair->id,
            'name' => $chair->name,
            'department_id' => $chair->department_id,
            'note' => $note,
            'at' => now()->toIso8601String(),
        ];

        $case->forceFill(['chair_context' => $context])->save();
        $this->audit->log('identity_case.chair_context', $case, after: ['note' => $note]);
    }

    /** Whether a Chair may see and comment on a case. */
    public function chairCanSee(TeacherIdentityCase $case, User $chair): bool
    {
        if ($case->observed_department_id === $chair->department_id) {
            return true;
        }

        return Teacher::whereIn('id', $case->involvedTeacherIds())
            ->where('department_id', $chair->department_id)->exists();
    }

    /**
     * A Chair confirms that a teacher's canonical name and department are
     * right. This is evidence for verification, not verification itself.
     */
    public function chairConfirm(Teacher $teacher, User $chair, string $note): void
    {
        $teacher->forceFill([
            'chair_confirmed_at' => now(),
            'chair_confirmed_by_user_id' => $chair->id,
            'chair_confirmation_note' => $note,
        ])->save();

        $this->audit->log('teacher.chair_confirmed', $teacher, after: [
            'full_name' => $teacher->full_name,
            'department_id' => $teacher->department_id,
            'note' => $note,
        ]);
    }

    /**
     * Attest to a teacher's canonical name and department.
     *
     * The basis is a reviewed plantilla with the Chair's confirmation, a
     * recorded official directory entry, or another source the Admin
     * describes. An official directory is never required.
     *
     * @throws ValidationException
     */
    public function verify(Teacher $teacher, string $basis, string $note, User $admin): void
    {
        DB::transaction(function () use ($teacher, $basis, $note, $admin) {
            $teacher = Teacher::lockForUpdate()->findOrFail($teacher->id);

            $errors = [];
            if ($teacher->isMerged()) {
                $errors[] = 'This record was merged into another teacher.';
            }
            if (! $teacher->department_id) {
                $errors[] = 'Assign a department before verifying.';
            }
            if ($this->identities->openCasesFor($teacher->id)->isNotEmpty()) {
                $errors[] = 'Decide the open identity cases for this teacher first.';
            }

            $evidence = [
                'basis' => $basis,
                'note' => $note,
                'full_name' => $teacher->full_name,
                'department_id' => $teacher->department_id,
            ];

            if ($basis === 'plantilla') {
                $observations = $teacher->observations()->where('source_type', IdentityObservation::PLANTILLA)
                    ->where('observed_department_id', $teacher->department_id)->pluck('source_reference', 'id');

                if (! $teacher->chair_confirmed_at) {
                    $errors[] = 'Plantilla-based verification needs the Chair\'s confirmation of this teacher.';
                }
                if ($observations->isEmpty()) {
                    $errors[] = 'No reviewed plantilla row for this department names this teacher.';
                }

                $evidence += [
                    'chair_confirmed_by_user_id' => $teacher->chair_confirmed_by_user_id,
                    'chair_confirmed_at' => $teacher->chair_confirmed_at?->toIso8601String(),
                    'chair_confirmation_note' => $teacher->chair_confirmation_note,
                    'observations' => $observations->all(),
                ];
            } elseif ($basis === 'directory') {
                $observations = $teacher->observations()->where('source_type', IdentityObservation::DIRECTORY)->pluck('source_reference', 'id');

                if ($observations->isEmpty()) {
                    $errors[] = 'No recorded directory entry names this teacher.';
                }

                $evidence['observations'] = $observations->all();
            }

            if ($errors) {
                throw ValidationException::withMessages(['basis' => $errors]);
            }

            $before = ['verification_status' => $teacher->verification_status->value];
            $teacher->forceFill([
                'verification_status' => TeacherVerification::Verified,
                'verified_at' => now(),
                'verified_by_user_id' => $admin->id,
                'verification_evidence' => $evidence,
            ])->save();

            $this->audit->log('teacher.verified', $teacher, before: $before, after: $evidence);
        });
    }

    /**
     * Record a directory the school received, and what it says.
     *
     * Each listed name is matched like any other source: an exact or approved
     * match links, a variant opens a case. Only a source the Admin declares
     * complete, for a stated school year and scope, can raise an absence —
     * and an absence is a question to investigate, never a removal.
     *
     * @param  list<string>  $names
     * @return array{linked:int, review:int, unmatched:int, absences:int}
     */
    public function recordDirectory(string $reference, string $schoolYear, string $completeness, ?Department $scope, array $names, User $admin): array
    {
        return DB::transaction(function () use ($reference, $schoolYear, $completeness, $scope, $names, $admin) {
            $sourceId = hash('crc32b', $reference . '|' . $schoolYear . '|' . microtime());
            $counts = ['linked' => 0, 'review' => 0, 'unmatched' => 0, 'absences' => 0];
            $matched = [];

            foreach (array_values(array_filter(array_map('trim', $names))) as $i => $name) {
                $observation = new IdentityObservation(
                    IdentityObservation::DIRECTORY,
                    "directory:{$sourceId}:{$i}",
                    $name,
                    $scope?->id,
                    $reference,
                    $schoolYear,
                    $name,
                    $completeness,
                    $scope?->id,
                );

                $outcome = $this->identities->inspect($observation);

                if ($outcome->kind === IdentityOutcome::EXISTING) {
                    $teacher = $this->identities->commit($observation, $outcome);
                    $matched[$teacher->id] = true;
                    $counts['linked']++;
                } elseif ($outcome->needsReview()) {
                    $this->identities->openCase($observation, $outcome);
                    $this->identities->recordObservation($observation, null);
                    $matched += array_fill_keys($outcome->candidateIds, true);
                    $counts['review']++;
                } else {
                    // A directory names someone the school has no record of.
                    // That is information, not a reason to create a teacher.
                    $this->identities->recordObservation($observation, null);
                    $counts['unmatched']++;
                }
            }

            if ($completeness === 'complete') {
                $absent = Teacher::active()
                    ->when($scope, fn ($q) => $q->where('department_id', $scope->id))
                    ->whereNotIn('id', array_keys($matched))->get();

                foreach ($absent as $teacher) {
                    $this->identities->openCase(
                        new IdentityObservation(IdentityObservation::DIRECTORY, "directory:{$sourceId}:absent:{$teacher->id}", $teacher->full_name, $scope?->id, $reference, $schoolYear, null, $completeness, $scope?->id),
                        IdentityOutcome::review(IdentityCaseReason::DirectoryAbsence, []),
                        $teacher,
                    );
                    $counts['absences']++;
                }
            }

            $this->audit->log('teacher_directory.recorded', $admin, after: [
                'reference' => $reference,
                'school_year' => $schoolYear,
                'completeness' => $completeness,
                'scope_department_id' => $scope?->id,
            ] + $counts);

            return $counts;
        });
    }

    /** @throws ValidationException */
    private function decide(TeacherIdentityCase $case, IdentityDecision $decision, ?int $teacherId, string $note, User $admin): ?Teacher
    {
        $allowed = match ($case->reason) {
            IdentityCaseReason::PossibleMatch, IdentityCaseReason::AmbiguousMatch => [IdentityDecision::Same, IdentityDecision::Distinct],
            IdentityCaseReason::DepartmentConflict => [IdentityDecision::KeepDepartment, IdentityDecision::UseObservedDepartment, IdentityDecision::Distinct],
            IdentityCaseReason::DirectoryAbsence => [IdentityDecision::AbsenceExplained],
        };

        if (! in_array($decision, $allowed, true)) {
            throw ValidationException::withMessages(['decision' => 'That decision does not apply to this case.']);
        }

        if ($case->subject_teacher_id && $decision === IdentityDecision::Same) {
            throw ValidationException::withMessages(['decision' => 'Both names already have their own records. Merge them from the merge preview instead.']);
        }

        if (in_array($decision, [IdentityDecision::Distinct, IdentityDecision::AbsenceExplained], true)) {
            return $decision === IdentityDecision::AbsenceExplained ? $case->subjectTeacher : null;
        }

        if (! $teacherId || ! in_array($teacherId, $case->candidate_ids ?? [], true)) {
            throw ValidationException::withMessages(['teacher_id' => 'Choose one of the suggested teachers.']);
        }

        $teacher = Teacher::active()->lockForUpdate()->find($teacherId);
        if (! $teacher) {
            throw ValidationException::withMessages(['teacher_id' => 'That teacher was merged into another record. Review the case again.']);
        }

        if ($decision === IdentityDecision::Same) {
            $this->approveAlias($teacher, $case, $note, $admin);
        }

        if ($decision === IdentityDecision::UseObservedDepartment) {
            $this->moveDepartment($teacher, $case);
            $this->approveAlias($teacher, $case, $note, $admin);
        }

        if ($decision === IdentityDecision::KeepDepartment) {
            $this->approveAlias($teacher, $case, $note, $admin);
        }

        return $teacher;
    }

    private function approveAlias(Teacher $teacher, TeacherIdentityCase $case, string $note, User $admin): void
    {
        if (! $case->observed_name || $case->normalized_name === $teacher->normalized_name) {
            return;
        }

        TeacherAlias::firstOrCreate(
            ['teacher_id' => $teacher->id, 'normalized_name' => $case->normalized_name],
            [
                'alias_name' => $case->observed_name,
                'identity_case_id' => $case->id,
                'approved_by_user_id' => $admin->id,
                'note' => $note,
            ],
        );
    }

    /**
     * Move a teacher to the department a source names. Loads already recorded
     * under the old department are never rewritten by an identity decision:
     * the move is refused until they are cleared or reassigned.
     */
    private function moveDepartment(Teacher $teacher, TeacherIdentityCase $case): void
    {
        $target = $case->observed_department_id;
        if (! $target || $teacher->department_id === $target) {
            return;
        }

        $loads = TeacherSectionAssignment::where('teacher_id', $teacher->id)->where('department_id', '!=', $target)->count();
        if ($loads > 0) {
            throw ValidationException::withMessages(['decision' => "{$teacher->full_name} has {$loads} section load(s) recorded under their current department. Reassign or clear those first; an identity decision does not move loads."]);
        }

        $teacher->update(['department_id' => $target]);
        $teacher->forceFill([
            'chair_confirmed_at' => null,
            'chair_confirmed_by_user_id' => null,
            'chair_confirmation_note' => null,
        ])->save();
    }

    /** Observations recorded for a case's source row, newest first. */
    public function observationsFor(TeacherIdentityCase $case)
    {
        return $case->source_key
            ? TeacherObservation::where('source_key', $case->source_key)->get()
            : collect();
    }
}
