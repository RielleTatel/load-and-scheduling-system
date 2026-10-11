<?php

namespace App\Services\Scheduling;

use App\Enums\TimetableKind;
use App\Enums\TimetableStatus;
use App\Jobs\GenerateTimetable;
use App\Models\SystemConstant;
use App\Models\TimetableVersion;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use DomainException;
use Illuminate\Support\Facades\DB;

class TimetableVersionService
{
    public function __construct(
        private readonly ScheduleReadinessService $readiness,
        private readonly TimetableValidationService $validation,
        private readonly AuditLogService $audit,
    ) {}

    public function startGeneration(string $schoolYear, int $quarter, User $actor, TimetableKind $kind = TimetableKind::Normal): TimetableVersion
    {
        if ($quarter < 1 || $quarter > 4) {
            throw new DomainException('Quarter must be between 1 and 4.');
        }

        $readiness = $this->readiness->check($schoolYear);
        if (! $readiness['ready']) {
            throw new ScheduleNotReadyException($readiness['blockers']);
        }

        return DB::transaction(function () use ($schoolYear, $quarter, $actor, $kind): TimetableVersion {
            $this->lockLifecycle();
            $pending = TimetableVersion::query()
                ->where('school_year', $schoolYear)
                ->where('quarter', $quarter)
                ->where('kind', $kind->value)
                ->where('status', TimetableStatus::Generating->value)
                ->lockForUpdate()->exists();
            if ($pending) {
                throw new DomainException('A '.$kind->value.' schedule generation is already pending for this quarter.');
            }

            $version = (int) TimetableVersion::query()
                ->where('school_year', $schoolYear)->where('quarter', $quarter)->max('version') + 1;
            $timetable = TimetableVersion::create([
                'school_year' => $schoolYear,
                'quarter' => $quarter,
                'version' => $version,
                'kind' => $kind,
                'status' => TimetableStatus::Generating,
                'created_by_user_id' => $actor->id,
                'generation_seed' => random_int(1, 2147483647),
                'started_at' => now(),
            ]);

            $this->audit->log('timetable.generation_requested', $timetable, null, [
                'school_year' => $schoolYear,
                'quarter' => $quarter,
                'version' => $version,
                'kind' => $kind->value,
            ]);
            GenerateTimetable::dispatch($timetable->id)->afterCommit();

            return $timetable;
        }, 3);
    }

    public function approve(TimetableVersion $timetable, User $actor): TimetableVersion
    {
        $result = DB::transaction(function () use ($timetable, $actor): array {
            $this->lockLifecycle();
            $locked = TimetableVersion::query()->lockForUpdate()->findOrFail($timetable->id);
            if ($locked->status !== TimetableStatus::Draft) {
                throw new DomainException('Only a draft timetable can be approved.');
            }

            $issues = $this->validation->refresh($locked);
            $hardIssues = array_filter($issues, fn (array $issue) => ($issue['severity'] ?? 'hard') === 'hard');
            if ($hardIssues !== []) {
                return ['approved' => false, 'timetable' => $locked];
            }

            TimetableVersion::query()
                ->where('school_year', $locked->school_year)
                ->where('quarter', $locked->quarter)
                ->where('kind', $locked->kind->value)
                ->where('status', TimetableStatus::Approved->value)
                ->where('id', '<>', $locked->id)
                ->update(['status' => TimetableStatus::Superseded->value, 'updated_at' => now()]);

            $locked->status = TimetableStatus::Approved;
            $locked->approved_by_user_id = $actor->id;
            $locked->approved_at = now();
            $locked->save();
            $this->audit->log('timetable.approved', $locked, null, [
                'school_year' => $locked->school_year,
                'quarter' => $locked->quarter,
                'version' => $locked->version,
                'kind' => $locked->kind->value,
            ]);

            return ['approved' => true, 'timetable' => $locked];
        }, 3);

        if (! $result['approved']) {
            throw new DomainException('Approval is blocked while hard timetable errors remain.');
        }

        return $result['timetable'];
    }

    public function stopGeneration(TimetableVersion $timetable, User $actor): void
    {
        DB::transaction(function () use ($timetable, $actor): void {
            $locked = TimetableVersion::query()->lockForUpdate()->findOrFail($timetable->id);
            if ($locked->status === TimetableStatus::Cancelled) {
                return;
            }
            if ($locked->status !== TimetableStatus::Generating) {
                throw new DomainException('Only a schedule that is still being generated can be stopped.');
            }
            $locked->status = TimetableStatus::Cancelled;
            $locked->completed_at = now();
            $locked->generation_checkpoint = null;
            $locked->save();
            $this->audit->log('timetable.generation_stopped', $locked, null, ['stopped_by_user_id' => $actor->id]);
        });
    }

    public function cloneApproved(TimetableVersion $source, User $actor): TimetableVersion
    {
        if ($source->status !== TimetableStatus::Approved) {
            throw new DomainException('Only an approved timetable can be copied into a new draft.');
        }

        return DB::transaction(function () use ($source, $actor): TimetableVersion {
            $this->lockLifecycle();
            $source = TimetableVersion::query()->lockForUpdate()->findOrFail($source->id);
            if ($source->status !== TimetableStatus::Approved) {
                throw new DomainException('Only an approved timetable can be copied into a new draft.');
            }
            $version = (int) TimetableVersion::query()
                ->where('school_year', $source->school_year)
                ->where('quarter', $source->quarter)->max('version') + 1;
            $draft = TimetableVersion::create([
                'school_year' => $source->school_year,
                'quarter' => $source->quarter,
                'version' => $version,
                'kind' => $source->kind,
                'status' => TimetableStatus::Draft,
                'created_by_user_id' => $actor->id,
                'generation_seed' => $source->generation_seed,
                'started_at' => now(),
                'completed_at' => now(),
            ]);

            $now = now();
            $rows = $source->entries()->orderBy('id')->get()->map(fn ($entry) => [
                'timetable_version_id' => $draft->id,
                'section_id' => $entry->section_id,
                'department_id' => $entry->department_id,
                'teacher_id' => $entry->teacher_id,
                'day' => $entry->day,
                'period' => $entry->period,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('timetable_entries')->insert($chunk);
            }

            $this->validation->refresh($draft);
            $this->audit->log('timetable.draft_cloned', $draft, null, [
                'source_version_id' => $source->id,
                'version' => $version,
            ]);

            return $draft;
        }, 3);
    }

    /** All kinds share version numbers; serialize allocation and publication. */
    private function lockLifecycle(): void
    {
        // Lock an existing setup row even before the first version is created.
        // Locking only timetable rows (or a kind's pending rows) cannot do that.
        if (! SystemConstant::query()->where('key', 'current_school_year')->lockForUpdate()->first()) {
            throw new DomainException('Configure the current school year before creating or approving schedules.');
        }
    }
}
