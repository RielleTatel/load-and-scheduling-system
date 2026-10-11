<?php

namespace App\Services\Scheduling;

use App\Enums\TimetableKind;
use App\Enums\TimetableStatus;
use App\Models\Department;
use App\Models\TeacherSectionAssignment;
use App\Models\TimetableChange;
use App\Models\TimetableEntry;
use App\Models\TimetableVersion;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use DomainException;
use Illuminate\Support\Facades\DB;

class TimetableEditor
{
    public function __construct(
        private readonly TimetableValidationService $validation,
        private readonly AuditLogService $audit,
    ) {}

    public function updateEntry(TimetableVersion $timetable, int $entryId, array $input, User $actor): array
    {
        return DB::transaction(function () use ($timetable, $entryId, $input, $actor): array {
            $locked = $this->editableVersion($timetable->id, (int) $input['expected_sequence']);
            $entry = $locked->entries()->lockForUpdate()->findOrFail($entryId);
            $before = [];

            if ($input['action'] === 'replace') {
                $department = Department::query()->whereIn('code', ScheduleReadinessService::SUBJECT_CODES)
                    ->where('code', $input['subject_code'])->firstOrFail();
                $assignment = TeacherSectionAssignment::query()
                    ->where('school_year', $locked->school_year)
                    ->where('section_id', $entry->section_id)
                    ->where('department_id', $department->id)
                    ->with('teacher:id,department_id')
                    ->first();
                if (! $assignment || ! $assignment->teacher || $assignment->teacher->department_id !== $department->id) {
                    throw new DomainException('That section does not have an eligible teacher assigned to this subject.');
                }

                $replacements = $locked->kind === TimetableKind::Special
                    ? $locked->entries()->lockForUpdate()->where('section_id', $entry->section_id)->where('period', $entry->period)->orderBy('day')->get()
                    : collect([$entry]);
                if ($locked->kind === TimetableKind::Special && $replacements->count() !== SchedulePolicy::DAYS) {
                    throw new DomainException('This review period is missing a day. Generate a complete special draft first.');
                }
                if ($replacements->every(fn ($cell) => $cell->department_id === $department->id && $cell->teacher_id === $assignment->teacher_id)) {
                    return ['sequence' => $locked->edit_sequence, 'issues' => $locked->issues()->count(), 'unchanged' => true];
                }
                foreach ($replacements as $cell) {
                    $before[] = $this->snapshot($cell);
                    $cell->department_id = $department->id;
                    $cell->teacher_id = $assignment->teacher_id;
                    $cell->save();
                }
            } elseif ($input['action'] === 'swap') {
                $special = $locked->kind === TimetableKind::Special;
                if ($special && (int) $input['target_period'] === $entry->period) {
                    return ['sequence' => $locked->edit_sequence, 'issues' => $locked->issues()->count(), 'unchanged' => true];
                }
                foreach ($special ? range(1, SchedulePolicy::DAYS) : [$entry->day] as $day) {
                    $source = $special ? $locked->entries()->lockForUpdate()->where('section_id', $entry->section_id)->where('day', $day)->where('period', $entry->period)->firstOrFail() : $entry;
                    $target = $locked->entries()->lockForUpdate()
                        ->where('section_id', $entry->section_id)
                        ->where('day', $special ? $day : $input['target_day'])
                        ->where('period', $input['target_period'])->firstOrFail();
                    if ($target->id === $source->id) {
                        return ['sequence' => $locked->edit_sequence, 'issues' => $locked->issues()->count(), 'unchanged' => true];
                    }
                    $before[] = $this->snapshot($source);
                    $before[] = $this->snapshot($target);
                    [$source->department_id, $target->department_id] = [$target->department_id, $source->department_id];
                    [$source->teacher_id, $target->teacher_id] = [$target->teacher_id, $source->teacher_id];
                    $source->save();
                    $target->save();
                }
            } else {
                throw new DomainException('Unsupported timetable edit.');
            }

            return $this->finishChange($locked, $before, $actor, 'edit');
        });
    }

    public function undo(TimetableVersion $timetable, int $expectedSequence, User $actor): array
    {
        return DB::transaction(function () use ($timetable, $expectedSequence, $actor): array {
            $locked = $this->editableVersion($timetable->id, $expectedSequence);
            $lastChange = $locked->changes()->orderByDesc('sequence')->first();
            if (! $lastChange || $lastChange->action === 'undo') {
                throw new DomainException('There is no schedule edit available to undo.');
            }

            $before = $lastChange->before_state;
            $current = [];
            foreach ($before as $snapshot) {
                $entry = $locked->entries()->lockForUpdate()->findOrFail($snapshot['id']);
                $current[] = $this->snapshot($entry);
                $entry->department_id = $snapshot['department_id'];
                $entry->teacher_id = $snapshot['teacher_id'];
                $entry->save();
            }

            return $this->finishChange($locked, $current, $actor, 'undo');
        });
    }

    private function editableVersion(int $id, int $expectedSequence): TimetableVersion
    {
        $timetable = TimetableVersion::query()->lockForUpdate()->findOrFail($id);
        if ($timetable->status !== TimetableStatus::Draft) {
            throw new DomainException('Only a draft timetable can be edited.');
        }
        if ($timetable->edit_sequence !== $expectedSequence) {
            throw new ScheduleConcurrencyException;
        }

        return $timetable;
    }

    private function finishChange(TimetableVersion $timetable, array $before, User $actor, string $action): array
    {
        $sequence = $timetable->edit_sequence + 1;
        $after = [];
        foreach ($before as $snapshot) {
            $entry = $timetable->entries()->findOrFail($snapshot['id']);
            $after[] = $this->snapshot($entry);
        }

        TimetableChange::create([
            'timetable_version_id' => $timetable->id,
            'user_id' => $actor->id,
            'sequence' => $sequence,
            'action' => $action,
            'before_state' => $before,
            'after_state' => $after,
        ]);
        $timetable->edit_sequence = $sequence;
        $timetable->save();

        $issues = $this->validation->refresh($timetable);
        $this->audit->log('timetable.entry_'.$action, $timetable, $before, $after);

        return ['sequence' => $sequence, 'issues' => count($issues), 'unchanged' => false];
    }

    private function snapshot(TimetableEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'section_id' => $entry->section_id,
            'day' => $entry->day,
            'period' => $entry->period,
            'department_id' => $entry->department_id,
            'teacher_id' => $entry->teacher_id,
        ];
    }
}
