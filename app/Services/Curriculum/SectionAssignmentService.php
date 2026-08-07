<?php

namespace App\Services\Curriculum;

use App\Models\ClassModeratorAssignment;
use App\Models\HonorsClassAssignment;
use App\Models\Section;
use App\Models\SystemConstant;
use App\Models\Teacher;
use App\Models\TeacherSectionAssignment;
use DomainException;

class SectionAssignmentService
{
    /**
     * Assign a teacher to teach a section for their department.
     * Hours come from the department rate. One subject teacher per section per dept.
     *
     * @throws DomainException 'section_taken' if another teacher already holds it
     */
    public function assign(Teacher $teacher, Section $section): TeacherSectionAssignment
    {
        $schoolYear = $this->schoolYear();
        $department = $teacher->department;

        $existing = TeacherSectionAssignment::where('section_id', $section->id)
            ->where('department_id', $department->id)
            ->where('school_year', $schoolYear)
            ->first();

        if ($existing && $existing->teacher_id !== $teacher->id) {
            throw new DomainException('section_taken');
        }

        return TeacherSectionAssignment::updateOrCreate(
            ['section_id' => $section->id, 'department_id' => $department->id, 'school_year' => $schoolYear],
            ['teacher_id' => $teacher->id, 'hours' => $department->hours_per_section],
        );
    }

    /**
     * Assign the class moderator for a section (drawn from any department).
     *
     * @throws DomainException 'moderator_taken' if the section already has a different moderator
     */
    public function assignModerator(Teacher $teacher, Section $section): ClassModeratorAssignment
    {
        $schoolYear = $this->schoolYear();

        $existing = ClassModeratorAssignment::where('section_id', $section->id)
            ->where('school_year', $schoolYear)
            ->first();

        if ($existing && $existing->teacher_id !== $teacher->id) {
            throw new DomainException('moderator_taken');
        }

        return ClassModeratorAssignment::updateOrCreate(
            ['section_id' => $section->id, 'school_year' => $schoolYear],
            ['teacher_id' => $teacher->id, 'hours' => SystemConstant::get('class_moderator_hours', 3)],
        );
    }

    /**
     * Assign an Honor's Class section to a teacher.
     *
     * @throws DomainException 'no_honors_class' if the teacher's department has no Honor's Class
     */
    public function assignHonors(Teacher $teacher, Section $section): HonorsClassAssignment
    {
        if (! $teacher->department->has_honors_class) {
            throw new DomainException('no_honors_class');
        }

        return HonorsClassAssignment::updateOrCreate(
            ['teacher_id' => $teacher->id, 'section_id' => $section->id, 'school_year' => $this->schoolYear()],
            ['hours' => SystemConstant::get('honors_class_hours', 8)],
        );
    }

    private function schoolYear(): string
    {
        return SystemConstant::get('current_school_year');
    }
}
