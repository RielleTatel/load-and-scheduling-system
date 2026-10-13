<?php

namespace App\Services\Teachers;

use App\Enums\IdentityCaseReason;
use App\Enums\IdentityCaseStatus;
use App\Enums\IdentityDecision;
use App\Enums\TeacherVerification;
use App\Models\Teacher;
use App\Models\TeacherAlias;
use App\Models\TeacherIdentityCase;
use App\Models\TeacherObservation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The one place a written name becomes a teacher ID.
 *
 * Plantilla confirmation, registrar roster confirmation, directory entries and
 * manual teacher entry all pass through here, so the order in which sources
 * arrive cannot change who a name refers to.
 *
 * Matching order:
 *  1. A previous mapping of the same source row whose name and department
 *     are unchanged.
 *  2. An Admin's recorded answer for that source row.
 *  3. A unique exact normalized-name or approved-alias match with a
 *     compatible department.
 *  4. Anything else that resembles an existing teacher — several exact
 *     matches, a match in another department, or a spelling variant — is a
 *     question for an Admin. Similarity only ranks the suggestions.
 *  5. Otherwise the person is new.
 */
class TeacherIdentityService
{
    public function __construct(private NameSimilarity $similarity) {}

    /**
     * Decide without writing anything.
     *
     * @param  list<int>  $ignoreIds  teachers created earlier in the same batch
     */
    public function inspect(IdentityObservation $observation, array $ignoreIds = []): IdentityOutcome
    {
        if ($observation->sourceKey && $mapped = $this->previousMapping($observation)) {
            return IdentityOutcome::existing($mapped);
        }

        if ($observation->sourceKey && $answered = $this->answeredCase($observation)) {
            return $answered;
        }

        $exact = $this->exactCandidates($observation->normalizedName)->whereNotIn('id', $ignoreIds)->values();

        if ($exact->isNotEmpty()) {
            return $this->fromExactCandidates($observation, $exact);
        }

        $similar = $this->similarCandidates($observation->name)->whereNotIn('id', $ignoreIds)->values();

        if ($similar->isNotEmpty()) {
            return IdentityOutcome::review(IdentityCaseReason::PossibleMatch, $similar->pluck('id')->all());
        }

        return IdentityOutcome::new();
    }

    /**
     * Inspect a whole batch before anything authoritative changes, recording
     * a persistent case for every observation that needs an Admin.
     *
     * @param  array<array-key, IdentityObservation>  $observations
     * @return array<array-key, IdentityOutcome>
     */
    public function preflight(array $observations): array
    {
        $outcomes = array_map(fn (IdentityObservation $o) => $this->inspect($o), $observations);

        DB::transaction(function () use ($observations, $outcomes) {
            foreach ($outcomes as $key => $outcome) {
                if ($outcome->needsReview()) {
                    $outcome->case = $this->openCase($observations[$key], $outcome);
                }
            }
        });

        return $outcomes;
    }

    /**
     * Commit a whole cleared batch inside the caller's transaction.
     *
     * A batch can name one new person twice (a moderator of one section and
     * the partner of another): the second reading links to the record the
     * first created. Teachers created by the batch are not re-checked against
     * each other; a batch is one source's reviewed reading.
     *
     * @param  array<array-key, IdentityObservation>  $observations
     * @param  array<array-key, IdentityOutcome>  $outcomes
     * @return array<array-key, Teacher>
     *
     * @throws IdentityChangedException
     */
    public function commitAll(array $observations, array $outcomes): array
    {
        $created = [];
        $teachers = [];

        foreach ($observations as $key => $observation) {
            $batchKey = $observation->normalizedName.'|'.$observation->departmentId;

            if ($outcomes[$key]->kind === IdentityOutcome::NEW && isset($created[$batchKey])) {
                $teachers[$key] = $created[$batchKey];
                $this->recordObservation($observation, $teachers[$key]);

                continue;
            }

            $teachers[$key] = $this->commit($observation, $outcomes[$key], array_map(fn ($t) => $t->id, array_values($created)));

            if ($outcomes[$key]->kind === IdentityOutcome::NEW) {
                $created[$batchKey] = $teachers[$key];
            }
        }

        return $teachers;
    }

    /**
     * Apply a cleared outcome inside the caller's transaction: link or create
     * the teacher and record the observation. The decision is re-made first so
     * a concurrent change cannot slip in between preflight and commit.
     *
     * @param  list<int>  $ignoreIds  teachers created earlier in the same batch
     *
     * @throws IdentityChangedException
     */
    public function commit(IdentityObservation $observation, IdentityOutcome $expected, array $ignoreIds = []): Teacher
    {
        $outcome = $this->inspect($observation, $ignoreIds);

        if ($outcome->needsReview() || ! $outcome->sameAs($expected)) {
            throw new IdentityChangedException($observation->name);
        }

        try {
            $teacher = $outcome->kind === IdentityOutcome::NEW
                ? $this->createTeacher($observation, $outcome->distinctApproved)
                : $this->link($outcome->teacher, $observation);
        } catch (UniqueConstraintViolationException) {
            throw new IdentityChangedException($observation->name);
        }

        if ($observation->sourceKey) {
            $this->recordObservation($observation, $teacher);
        }

        return $teacher;
    }

    /** Record what a source said without linking it to anyone. */
    public function recordObservation(IdentityObservation $observation, ?Teacher $teacher): TeacherObservation
    {
        return TeacherObservation::updateOrCreate(
            ['source_key' => $observation->sourceKey ?? 'manual:'.uniqid('', true)],
            [
                'teacher_id' => $teacher?->id,
                'source_type' => $observation->sourceType,
                'source_reference' => $observation->sourceReference,
                'school_year' => $observation->schoolYear,
                'completeness' => $observation->completeness,
                'coverage_department_id' => $observation->coverageDepartmentId,
                'raw_name' => $observation->rawName ?? $observation->name,
                'observed_name' => $observation->name,
                'normalized_name' => $observation->normalizedName,
                'observed_department_id' => $observation->departmentId,
                'identity_hash' => $observation->identityHash(),
                'recorded_by_user_id' => auth()->id(),
            ],
        );
    }

    /**
     * Persist the question, reusing the open case for the same source row and
     * reading. A case raised for an older reading of that row is superseded.
     */
    public function openCase(IdentityObservation $observation, IdentityOutcome $outcome, ?Teacher $subject = null): TeacherIdentityCase
    {
        $attributes = [
            'reason' => $outcome->reason,
            'source_type' => $observation->sourceType,
            'source_key' => $observation->sourceKey,
            'source_reference' => $observation->sourceReference,
            'school_year' => $observation->schoolYear,
            'observed_name' => $observation->name,
            'normalized_name' => $observation->normalizedName,
            'observed_department_id' => $observation->departmentId,
            'subject_teacher_id' => $subject?->id,
            'candidate_ids' => $outcome->candidateIds,
        ];

        $case = null;
        if ($observation->sourceKey) {
            $open = TeacherIdentityCase::open()->where('source_key', $observation->sourceKey)->get();
            $case = $open->first(fn (TeacherIdentityCase $c) => $c->normalized_name === $observation->normalizedName
                && $c->observed_department_id === $observation->departmentId);

            foreach ($open->reject(fn ($c) => $c->is($case)) as $stale) {
                $this->supersede($stale);
            }
        }

        if ($case) {
            $case->update(['candidate_ids' => $outcome->candidateIds, 'reason' => $outcome->reason]);
        } else {
            $case = TeacherIdentityCase::create($attributes);
        }

        $this->suspendChallengedVerification($case);

        return $case;
    }

    /**
     * Close the open cases raised by source rows that were edited, removed or
     * replaced by a newer upload, so they stop holding anyone up.
     *
     * @param  iterable<string>  $sourceKeys
     */
    public function supersedeSources(iterable $sourceKeys): void
    {
        $keys = collect($sourceKeys)->values();
        if ($keys->isEmpty()) {
            return;
        }

        TeacherIdentityCase::open()->whereIn('source_key', $keys)->get()->each(fn ($case) => $this->supersede($case));
    }

    /**
     * Source keys for every person a roster row names.
     *
     * @return list<string>
     */
    public static function rosterKeys(int $rowId): array
    {
        return ["roster_row:{$rowId}:moderator", "roster_row:{$rowId}:teacher_partner"];
    }

    /** Close a case whose question no longer applies, and release its teachers. */
    public function supersede(TeacherIdentityCase $case): void
    {
        $case->forceFill([
            'status' => IdentityCaseStatus::Resolved,
            'decision' => IdentityDecision::Superseded,
            'resolved_at' => now(),
            'resolution_note' => 'The source row changed or was removed before an Admin decided.',
        ])->save();

        foreach ($case->involvedTeacherIds() as $id) {
            $this->restoreVerification(Teacher::find($id));
        }
    }

    /** One line a Chair or Admin can act on. */
    public function describe(TeacherIdentityCase $case): string
    {
        $names = $case->candidates()->map(fn (Teacher $t) => "\"{$t->full_name}\""
            .($t->department ? " ({$t->department->name})" : ' (no department yet)'))->implode(', ');

        $question = match ($case->reason) {
            IdentityCaseReason::PossibleMatch => "resembles {$names}",
            IdentityCaseReason::AmbiguousMatch => "matches more than one teacher: {$names}",
            IdentityCaseReason::DepartmentConflict => "is already in the directory under another department: {$names}",
            IdentityCaseReason::DirectoryAbsence => 'is missing from a complete teacher directory',
        };

        $who = $case->observed_name ?? $case->subjectTeacher?->full_name;

        return "\"{$who}\" {$question}. An Admin must decide who this is (identity case #{$case->id}).";
    }

    /** Teachers that still answer to a name: active records and their approved aliases. */
    public function exactCandidates(string $normalizedName): Collection
    {
        if ($normalizedName === '') {
            return collect();
        }

        $aliased = TeacherAlias::where('normalized_name', $normalizedName)->pluck('teacher_id');

        return Teacher::active()
            ->where(fn ($q) => $q->where('normalized_name', $normalizedName)->orWhereIn('id', $aliased))
            ->orderBy('id')->get();
    }

    /** Teachers whose name or approved alias resembles $name. */
    public function similarCandidates(string $name, ?int $exceptId = null): Collection
    {
        $aliases = TeacherAlias::all()->groupBy('teacher_id');

        return Teacher::active()->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))->orderBy('id')->get()
            ->filter(fn (Teacher $t) => $this->similarity->resembles($name, $t->full_name)
                || $aliases->get($t->id, collect())->contains(fn (TeacherAlias $a) => $this->similarity->resembles($name, $a->alias_name)))
            ->values();
    }

    private function previousMapping(IdentityObservation $observation): ?Teacher
    {
        $previous = TeacherObservation::where('source_key', $observation->sourceKey)->first();

        if (! $previous || ! $previous->teacher_id || $previous->identity_hash !== $observation->identityHash()) {
            return null;
        }

        return Teacher::find($previous->teacher_id)?->survivor();
    }

    /** The Admin's latest answer for this source row and reading, or the case still waiting for one. */
    private function answeredCase(IdentityObservation $observation): ?IdentityOutcome
    {
        $case = TeacherIdentityCase::where('source_key', $observation->sourceKey)
            ->where('normalized_name', $observation->normalizedName)
            ->where(fn ($q) => $observation->departmentId === null
                ? $q->whereNull('observed_department_id')
                : $q->where('observed_department_id', $observation->departmentId))
            ->where(fn ($q) => $q->where('status', IdentityCaseStatus::Open->value)
                ->orWhere('decision', '!=', IdentityDecision::Superseded->value))
            ->latest('id')->first();

        if (! $case) {
            return null;
        }

        if ($case->isOpen()) {
            return IdentityOutcome::review($case->reason, $case->candidate_ids ?? [], $case);
        }

        return match ($case->decision) {
            IdentityDecision::Distinct => IdentityOutcome::new(distinctApproved: true),
            IdentityDecision::Same, IdentityDecision::KeepDepartment, IdentityDecision::UseObservedDepartment => ($teacher = $case->resolutionTeacher?->survivor())
                ? IdentityOutcome::existing($teacher)
                : null,
            default => null,
        };
    }

    private function fromExactCandidates(IdentityObservation $observation, Collection $exact): IdentityOutcome
    {
        $ids = $exact->pluck('id')->all();

        if ($observation->departmentId === null) {
            return $exact->count() === 1
                ? IdentityOutcome::existing($exact->first())
                : IdentityOutcome::review(IdentityCaseReason::AmbiguousMatch, $ids);
        }

        $same = $exact->where('department_id', $observation->departmentId);
        $unclaimed = $exact->whereNull('department_id');
        $elsewhere = $exact->whereNotNull('department_id')->where('department_id', '!=', $observation->departmentId);

        if ($same->count() === 1) {
            return IdentityOutcome::existing($same->first());
        }

        if ($same->count() > 1) {
            return IdentityOutcome::review(IdentityCaseReason::AmbiguousMatch, $same->pluck('id')->all());
        }

        if ($elsewhere->isNotEmpty()) {
            return IdentityOutcome::review(IdentityCaseReason::DepartmentConflict, $ids);
        }

        return $unclaimed->count() === 1
            ? IdentityOutcome::existing($unclaimed->first())
            : IdentityOutcome::review(IdentityCaseReason::AmbiguousMatch, $ids);
    }

    /**
     * A source with a department can give one to a teacher who has none. It
     * never moves a teacher between departments, and never overwrites the
     * provenance of whoever named them first.
     */
    private function link(Teacher $teacher, IdentityObservation $observation): Teacher
    {
        if ($observation->departmentId && $teacher->department_id === null) {
            $teacher->update(['department_id' => $observation->departmentId]);
        }

        return $teacher;
    }

    private function createTeacher(IdentityObservation $observation, bool $distinctApproved): Teacher
    {
        $teacher = new Teacher([
            'full_name' => $observation->name,
            'department_id' => $observation->departmentId,
            'source' => $observation->sourceType,
        ]);

        // An Admin confirmed a different person with an existing name: give
        // the new record the next ordinal so the unique key still holds.
        if ($distinctApproved) {
            $teacher->name_ordinal = 1 + (int) Teacher::where('normalized_name', $observation->normalizedName)
                ->where('department_id', $observation->departmentId)->max('name_ordinal');
        }

        $teacher->save();

        return $teacher;
    }

    /**
     * A case about an existing verified teacher's department suspends reliance
     * on that verification until an Admin answers. The canonical name,
     * department and loads are untouched.
     */
    private function suspendChallengedVerification(TeacherIdentityCase $case): void
    {
        if (! in_array($case->reason, [IdentityCaseReason::DepartmentConflict, IdentityCaseReason::DirectoryAbsence], true)) {
            return;
        }

        Teacher::whereIn('id', $case->involvedTeacherIds())
            ->where('verification_status', TeacherVerification::Verified->value)
            ->update(['verification_status' => TeacherVerification::NeedsReview->value]);
    }

    /**
     * Once no open case questions a teacher, an earlier verification applies
     * again if the teacher's name and department are what was verified.
     */
    public function restoreVerification(?Teacher $teacher): void
    {
        if (! $teacher || $teacher->verification_status !== TeacherVerification::NeedsReview) {
            return;
        }

        if ($this->openCasesFor($teacher->id)->isNotEmpty()) {
            return;
        }

        $evidence = $teacher->verification_evidence ?? [];
        $unchanged = $teacher->verified_at
            && ($evidence['full_name'] ?? null) === $teacher->full_name
            && ($evidence['department_id'] ?? null) === $teacher->department_id;

        $teacher->forceFill([
            'verification_status' => $unchanged ? TeacherVerification::Verified : TeacherVerification::Pending,
        ])->save();
    }

    /** @return Collection<int, TeacherIdentityCase> */
    public function openCasesFor(int $teacherId): Collection
    {
        return TeacherIdentityCase::open()->get()
            ->filter(fn (TeacherIdentityCase $c) => in_array($teacherId, $c->involvedTeacherIds(), true))
            ->values();
    }
}
