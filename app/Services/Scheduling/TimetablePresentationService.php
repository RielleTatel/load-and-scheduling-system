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

    /**
     * @return array{sections:mixed, grid:array, filters:array, departments:mixed, teachers:mixed, days:list<int>, periods:list<int>}
     */
    public function prepare(TimetableVersion $timetable, Request $request, ?User $chair = null, bool $forExport = false): array
    {
        $grade = $request->query('grade', $forExport ? 'all' : 'G7');
        if (! in_array($grade, ['all', 'G7', 'G8', 'G9', 'G10'], true)) {
            $grade = 'G7';
        }
        $displayDay = filter_var($request->query('day', 1), FILTER_VALIDATE_INT);
        if ($displayDay === false || $displayDay < 1 || $displayDay > SchedulePolicy::DAYS) {
            $displayDay = 1;
        }
        $days = $forExport && ! $request->has('day') ? range(1, SchedulePolicy::DAYS) : [(int) $displayDay];

        $sectionsQuery = Section::query()->where('school_year', $timetable->school_year);
        if ($grade !== 'all') {
            $sectionsQuery->where('grade_level', $grade);
        }

        $sectionId = filter_var($request->query('section_id'), FILTER_VALIDATE_INT);
        if ($sectionId !== false && $sectionId !== null && $sectionId > 0) {
            $section = Section::query()->where('school_year', $timetable->school_year)->findOrFail($sectionId);
            $sectionsQuery->whereKey($section->id);
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
            'day' => (int) $displayDay,
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
                for ($period = 1; $period <= SchedulePolicy::PERIODS_PER_DAY; $period++) {
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
            'displayDay' => (int) $displayDay,
            'periods' => range(1, SchedulePolicy::PERIODS_PER_DAY),
            'scheduleRows' => $this->layout->rows((int) $displayDay),
        ];
    }
}
