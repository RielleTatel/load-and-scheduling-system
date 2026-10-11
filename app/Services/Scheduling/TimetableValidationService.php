<?php

namespace App\Services\Scheduling;

use App\Models\Section;
use App\Models\TeacherSectionAssignment;
use App\Models\TeacherUnavailability;
use App\Models\TimetableVersion;
use App\Models\TimetableValidationIssue;

class TimetableValidationService
{
    public function __construct(
        private readonly ScheduleConstraintValidator $validator,
        private readonly ScheduleReadinessService $readiness,
    ) {}

    /** @return list<array<string, mixed>> */
    public function validate(TimetableVersion $timetable): array
    {
        $sections = Section::query()
            ->where('school_year', $timetable->school_year)
            ->get(['id', 'grade_level', 'name'])
            ->map(fn (Section $section) => [
                'id' => $section->id,
                'grade_level' => $section->grade_level->value,
                'name' => $section->name,
            ])->all();

        $savedEntries = $timetable->entries()->with(['department:id,code', 'teacher:id,department_id'])->get();
        $entries = $savedEntries->map(fn ($entry) => [
            'section_id' => $entry->section_id,
            'department_code' => $entry->department?->code,
            'teacher_id' => $entry->teacher_id,
            'day' => $entry->day,
            'period' => $entry->period,
        ])->all();

        $unavailable = TeacherUnavailability::query()
            ->where('school_year', $timetable->school_year)
            ->where('quarter', $timetable->quarter)
            ->get(['teacher_id', 'day', 'period'])
            ->map(fn ($slot) => $slot->toArray())->all();

        $issues = $this->validator->validate($sections, $entries, $unavailable, $timetable->kind);
        foreach ($this->readiness->check($timetable->school_year)['blockers'] as $blocker) {
            $issues[] = ['severity' => 'hard', 'category' => 'readiness'] + $blocker;
        }
        $assignments = TeacherSectionAssignment::query()->where('school_year', $timetable->school_year)
            ->get()->keyBy(fn ($assignment) => $assignment->section_id.':'.$assignment->department_id);
        // Report each stale section/subject/teacher combination once, not once per period.
        foreach ($savedEntries->unique(fn ($entry) => $entry->section_id.':'.$entry->department_id.':'.$entry->teacher_id) as $entry) {
            $assignment = $assignments->get($entry->section_id.':'.$entry->department_id);
            if (! $assignment || $entry->teacher_id !== $assignment->teacher_id) {
                $issues[] = [
                    'code' => 'scheduled_teacher_mismatch', 'severity' => 'hard', 'category' => 'assignment',
                    'message' => 'The scheduled teacher no longer matches the current plantilla assignment. Update the subject cells or generate a new draft.',
                    'section_id' => $entry->section_id, 'teacher_id' => $entry->teacher_id,
                    'department_id' => $entry->department_id, 'subject_code' => $entry->department?->code,
                ];
            }
            if (! $entry->teacher || $entry->teacher->department_id !== $entry->department_id) {
                $issues[] = [
                    'code' => 'teacher_department_mismatch', 'severity' => 'hard', 'category' => 'assignment',
                    'message' => 'The scheduled teacher does not belong to this subject’s department.',
                    'section_id' => $entry->section_id, 'teacher_id' => $entry->teacher_id,
                    'department_id' => $entry->department_id,
                ];
            }
        }

        return $issues;
    }

    /** @param list<array<string, mixed>> $issues */
    public function persist(TimetableVersion $timetable, array $issues): void
    {
        $timetable->issues()->delete();

        foreach ($issues as $issue) {
            $details = $issue;
            unset($details['code'], $details['severity'], $details['category'], $details['message'], $details['section_id'], $details['teacher_id'], $details['day'], $details['period']);

            $timetable->issues()->create([
                'code' => $issue['code'],
                'severity' => $issue['severity'] ?? 'hard',
                'category' => $issue['category'] ?? 'section_rule',
                'message' => $issue['message'],
                'section_id' => $issue['section_id'] ?? null,
                'teacher_id' => $issue['teacher_id'] ?? null,
                'day' => $issue['day'] ?? null,
                'period' => $issue['period'] ?? null,
                'details' => $details === [] ? null : $details,
            ]);
        }
    }

    public function refresh(TimetableVersion $timetable): array
    {
        $issues = $this->validate($timetable);
        $this->persist($timetable, $issues);

        return $issues;
    }

    public function hasHardIssues(TimetableVersion $timetable): bool
    {
        return $timetable->issues()->where('severity', 'hard')->exists();
    }
}
