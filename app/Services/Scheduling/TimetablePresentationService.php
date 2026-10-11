<?php

namespace App\Services\Scheduling;

use App\Models\Department;
use App\Models\Section;
use App\Models\Teacher;
use App\Models\TimetableVersion;
use App\Models\User;
use Illuminate\Http\Request;

class TimetablePresentationService
{
    public function __construct(private readonly TimetableLayout $layout) {}

    public function generationFailureSummary(?string $message): string
    {
        if (str_starts_with($message ?? '', 'Scheduling cannot fit:')) {
            return 'A teacher does not have enough available class time to meet the scheduling rules. Please review the teacher assignments and unavailable times before generating again. No new schedule was saved.';
        }
        if (str_starts_with($message ?? '', 'No conflict-free timetable was found within the configured search limit')) {
            $reason = preg_match('/; remaining teacher_break=\d+(?: \[|\))/', $message)
                ? 'This attempt ended before all classes could be arranged with the required teacher breaks.'
                : 'This attempt ended before all classes could be arranged without scheduling conflicts.';

            return $reason.' No new schedule was saved. You can try again; your existing schedules are unchanged.';
        }

        if (str_starts_with($message ?? '', 'Scheduling data is no longer ready:')) {
            return 'The teaching assignments changed while the schedule was being prepared. Please review the checklist on the Schedules page before trying again. No new schedule was saved.';
        }

        return 'Something interrupted schedule generation. No new schedule was saved. Please ask the system administrator to check the details before trying again.';
    }

    /**
     * @return array{sections:mixed, grid:array, filters:array, departments:mixed, teachers:mixed, days:list<int>, periods:list<int>}
     */
    public function prepare(TimetableVersion $timetable, Request $request, ?User $chair = null, bool $forExport = false): array
    {
        $grade = $request->query('grade', $forExport ? 'all' : 'G7');
        if (! in_array($grade, ['all', 'G7', 'G8', 'G9', 'G10'], true)) {
            $grade = 'G7';
        }
        $requestedDay = $request->query('day', $forExport ? 'all' : 1);
        $showAllDays = $requestedDay === 'all';
        $displayDay = $showAllDays ? null : filter_var($requestedDay, FILTER_VALIDATE_INT);
        if (! $showAllDays && ($displayDay === false || $displayDay < 1 || $displayDay > SchedulePolicy::DAYS)) {
            $displayDay = 1;
        }
        $days = $showAllDays ? range(1, SchedulePolicy::DAYS) : [(int) $displayDay];

        $sectionsQuery = Section::query()->where('school_year', $timetable->school_year);
        if ($grade !== 'all') {
            $sectionsQuery->where('grade_level', $grade);
        }

        $sectionId = filter_var($request->query('section_id'), FILTER_VALIDATE_INT);
        if ($sectionId !== false && $sectionId !== null && $sectionId > 0) {
            $section = Section::query()->where('school_year', $timetable->school_year)->findOrFail($sectionId);
            $sectionsQuery = Section::query()->where('school_year', $timetable->school_year)->whereKey($section->id);
            $grade = $section->grade_level->value;
        } else {
            $sectionId = null;
        }

        $gradeGroups = $this->layout->groupSections($sectionsQuery->get());
        $sections = collect($gradeGroups)->flatMap(fn ($group) => $group)->values();
        $sectionOptions = Section::query()->where('school_year', $timetable->school_year)
            ->get(['id', 'grade_level', 'name']);
        $sectionOptions = collect($this->layout->groupSections($sectionOptions))
            ->flatMap(fn ($group) => $group)->values();
        $filters = [
            'grade' => $grade,
            'section_id' => $sectionId,
            'day' => $showAllDays ? 'all' : (int) $displayDay,
            'day_only' => $request->has('day'),
            'grade_only' => $request->has('grade'),
            'scope' => 'school',
            'department_id' => null,
            'teacher_id' => null,
        ];

        $departmentId = filter_var($request->query('department_id'), FILTER_VALIDATE_INT);
        $teacherId = filter_var($request->query('teacher_id'), FILTER_VALIDATE_INT);
        $scope = $request->query('scope', 'department');

        if ($chair) {
            $scope = $scope === 'school' ? 'school' : 'department';
            if ($scope === 'department') {
                $departmentId = (int) $chair->department_id;
            } elseif ($departmentId === false || $departmentId === null || $departmentId < 1) {
                $departmentId = null;
            }
        } else {
            $scope = 'school';
            if ($departmentId === false || $departmentId === null || $departmentId < 1) {
                $departmentId = null;
            }
        }
        if ($departmentId !== null && $departmentId !== false && ! Department::query()->whereKey($departmentId)->exists()) {
            $departmentId = null;
        }
        if ($teacherId === false || $teacherId === null || $teacherId < 1 || ! Teacher::query()->whereKey($teacherId)->exists()) {
            $teacherId = null;
        }

        $filters['scope'] = $scope;
        $filters['department_id'] = $departmentId;
        $filters['teacher_id'] = $teacherId;
        $grid = [];
        foreach ($sections as $section) {
            foreach ($days as $day) {
                for ($period = 1; $period <= $timetable->kind->periodsPerDay(); $period++) {
                    $grid[$section->id][$day][$period] = null;
                }
            }
        }

        if ($sections->isNotEmpty()) {
            $entries = $timetable->entries()
                ->with(['department:id,code,name', 'teacher:id,full_name', 'section:id,grade_level,name'])
                ->whereIn('section_id', $sections->pluck('id'))
                ->whereIn('day', $days)
                ->when($departmentId, fn ($query) => $query->where('department_id', $departmentId))
                ->when($teacherId, fn ($query) => $query->where('teacher_id', $teacherId))
                ->get();

            foreach ($entries as $entry) {
                $grid[$entry->section_id][$entry->day][$entry->period] = $entry;
            }
        }

        $departments = Department::query()->orderBy('name')->get(['id', 'name', 'code']);
        $teachers = Teacher::query()
            ->when($scope === 'department' && $chair, fn ($query) => $query->where('department_id', $chair->department_id))
            ->when($departmentId, fn ($query) => $query->where('department_id', $departmentId))
            ->orderBy('full_name')->get(['id', 'full_name', 'department_id']);

        return [
            'sections' => $sections,
            'gradeGroups' => $gradeGroups,
            'sectionOptions' => $sectionOptions,
            'grid' => $grid,
            'filters' => $filters,
            'departments' => $departments,
            'teachers' => $teachers,
            'days' => $days,
            'displayDay' => $displayDay === null ? null : (int) $displayDay,
            'periods' => range(1, $timetable->kind->periodsPerDay()),
            'scheduleRows' => $this->layout->rows((int) ($displayDay ?? 1), $timetable->kind),
            'scheduleRowsByDay' => collect($days)->mapWithKeys(
                fn (int $day): array => [$day => $this->layout->rows($day, $timetable->kind)],
            )->all(),
        ];
    }
}
