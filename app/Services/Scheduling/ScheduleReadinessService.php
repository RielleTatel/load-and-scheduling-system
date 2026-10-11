<?php

namespace App\Services\Scheduling;

use App\Enums\SubmissionStatus;
use App\Models\ClassModeratorAssignment;
use App\Models\Department;
use App\Models\PlantillaSubmission;
use App\Models\Section;
use App\Models\TeacherOtherAssignment;
use App\Models\TeacherSectionAssignment;

final class ScheduleReadinessService
{
    public const SUBJECT_CODES = ['ENG', 'MATH', 'SCI', 'CLE', 'FIL', 'SOC', 'TLE', 'MAPEH'];

    /** @return array{ready:bool, school_year:string, blockers:list<array<string, mixed>>} */
    public function check(string $schoolYear): array
    {
        $blockers = [];
        $departments = Department::query()->orderBy('code')->get()->keyBy('code');

        foreach (self::SUBJECT_CODES as $code) {
            if (! $departments->has($code)) {
                $blockers[] = $this->blocker('department_missing', "The {$code} department is not configured.", ['department_code' => $code]);
            }
        }

        $submissions = PlantillaSubmission::query()
            ->where('school_year', $schoolYear)
            ->get()
            ->keyBy('department_id');

        foreach (self::SUBJECT_CODES as $code) {
            $department = $departments->get($code);
            if (! $department) {
                continue;
            }
            $submission = $submissions->get($department->id);
            $subjectName = (new TimetableLayout)->subjectName($code, $department->name);
            if (! $submission || ! in_array($submission->status, [SubmissionStatus::Submitted, SubmissionStatus::Locked], true)) {
                $blockers[] = $this->blocker('plantilla_not_submitted', "{$subjectName} must submit its plantilla before schedule generation or approval.", [
                    'department_id' => $department->id,
                    'department_code' => $department->code,
                ]);
            }
        }

        $sections = Section::query()->where('school_year', $schoolYear)->orderBy('grade_level')->orderBy('name')->get();
        if ($sections->isEmpty()) {
            $blockers[] = $this->blocker('sections_missing', "No sections are configured for {$schoolYear}.");
        }

        $assignments = TeacherSectionAssignment::query()
            ->where('school_year', $schoolYear)
            ->with(['teacher.department', 'department'])
            ->get();
        $bySectionDepartment = $assignments->keyBy(fn ($assignment) => $assignment->section_id.':'.$assignment->department_id);

        foreach ($sections as $section) {
            foreach (self::SUBJECT_CODES as $code) {
                $department = $departments->get($code);
                if (! $department) {
                    continue;
                }
                $assignment = $bySectionDepartment->get($section->id.':'.$department->id);
                $subjectName = (new TimetableLayout)->subjectName($code, $department->name);
                if (! $assignment) {
                    $blockers[] = $this->blocker('teacher_assignment_missing', "{$section->grade_level->value} {$section->name} has no {$subjectName} teacher assigned.", [
                        'section_id' => $section->id,
                        'department_id' => $department->id,
                        'department_code' => $code,
                    ]);
                    continue;
                }

                if (! $assignment->teacher || $assignment->teacher->department_id !== $department->id || $assignment->department_id !== $department->id) {
                    $blockers[] = $this->blocker('teacher_department_mismatch', "The assigned teacher for {$section->name} does not belong to {$subjectName}.", [
                        'section_id' => $section->id,
                        'teacher_id' => $assignment->teacher_id,
                        'department_id' => $department->id,
                        'department_code' => $code,
                    ]);
                }
            }
        }

        $moderatorTeacherIds = ClassModeratorAssignment::query()
            ->where('school_year', $schoolYear)
            ->pluck('teacher_id')->unique()->flip();
        $honorariumTeacherIds = TeacherOtherAssignment::query()
            ->join('other_assignment_roles', 'other_assignment_roles.id', '=', 'teacher_other_assignments.other_assignment_role_id')
            ->where('teacher_other_assignments.school_year', $schoolYear)
            ->where('other_assignment_roles.is_honorarium', true)
            ->pluck('teacher_other_assignments.teacher_id')->unique()->flip();

        $assignmentsByTeacher = $assignments->groupBy('teacher_id');
        foreach ($assignmentsByTeacher as $teacherId => $teacherAssignments) {
            $teacher = $teacherAssignments->first()->teacher;
            if (! $teacher) {
                continue;
            }
            $hasAdvisoryLoad = $moderatorTeacherIds->has($teacherId) || $honorariumTeacherIds->has($teacherId);
            $isMajor = in_array($teacher->department?->code, ['ENG', 'MATH', 'SCI'], true);
            $cap = $isMajor && $hasAdvisoryLoad ? 3 : 4;
            $sectionCount = $teacherAssignments->pluck('section_id')->unique()->count();

            if ($sectionCount > $cap) {
                $blockers[] = $this->blocker('teacher_section_cap', "{$teacher->full_name} is assigned to {$sectionCount} sections; the current maximum is {$cap}.", [
                    'teacher_id' => (int) $teacherId,
                    'section_count' => $sectionCount,
                    'section_cap' => $cap,
                ]);
            }
        }

        return [
            'ready' => $blockers === [],
            'school_year' => $schoolYear,
            'blockers' => $blockers,
        ];
    }

    private function blocker(string $code, string $message, array $context = []): array
    {
        return ['code' => $code, 'message' => $message] + $context;
    }
}
