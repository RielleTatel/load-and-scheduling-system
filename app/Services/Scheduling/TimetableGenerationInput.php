<?php

namespace App\Services\Scheduling;

use App\Enums\TimetableKind;
use App\Models\Section;
use App\Models\TeacherSectionAssignment;
use App\Models\TeacherUnavailability;
use App\Models\TimetableVersion;

final class TimetableGenerationInput
{
    public function load(TimetableVersion $timetable): array
    {
        $assignments = [];
        foreach (TeacherSectionAssignment::query()->where('school_year', $timetable->school_year)
            ->with(['teacher:id,department_id', 'department:id,code'])->orderBy('id')->get() as $assignment) {
            if ($assignment->teacher && $assignment->department) {
                $assignments[$assignment->section_id][$assignment->department->code] = [
                    'department_id' => $assignment->department_id,
                    'teacher_id' => $assignment->teacher_id,
                ];
            }
        }
        foreach ($assignments as &$subjects) {
            ksort($subjects);
        }
        unset($subjects);

        $sections = Section::query()->where('school_year', $timetable->school_year)->orderBy('id')->get()
            ->map(fn (Section $section): array => [
                'id' => $section->id,
                'grade_level' => $section->grade_level->value,
                'name' => $section->name,
                'assignments' => $assignments[$section->id] ?? [],
            ])->all();
        $unavailable = TeacherUnavailability::query()->where('school_year', $timetable->school_year)
            ->where('quarter', $timetable->quarter)->orderBy('teacher_id')->orderBy('day')->orderBy('period')
            ->get(['teacher_id', 'day', 'period'])->toArray();

        return [
            'sections' => $sections,
            'unavailable' => $unavailable,
            'fingerprint' => hash('sha256', json_encode([$sections, $unavailable, $timetable->kind->value], JSON_THROW_ON_ERROR)),
        ];
    }

    public function startingEntries(TimetableVersion $timetable, array $sections): array
    {
        $source = TimetableVersion::query()->where('school_year', $timetable->school_year)
            ->where('kind', $timetable->kind->value)
            ->whereKeyNot($timetable->id)->whereHas('entries')
            ->orderByRaw("CASE WHEN status = 'approved' THEN 0 WHEN status = 'draft' THEN 1 ELSE 2 END")
            ->orderByDesc('version')->first();

        $existing = $source?->entries()->with('department:id,code')->orderBy('section_id')
            ->orderBy('day')->orderBy('period')->get()->map(fn ($entry): array => [
                'section_id' => $entry->section_id,
                'department_code' => $entry->department?->code,
                'day' => $entry->day,
                'period' => $entry->period,
            ])->all() ?? [];
        $standard = $timetable->kind === TimetableKind::Normal ? app(StandardTimetable::class)->entries($sections) : [];

        return [
            'source_version_id' => $source?->id,
            'entries' => $standard ?: $existing,
            'fallback_entries' => $standard ? $existing : [],
            'reference' => $standard ? 'Excel workbook reference' : ($source ? 'Existing schedule' : null),
        ];
    }
}
