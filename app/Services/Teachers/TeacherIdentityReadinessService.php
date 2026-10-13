<?php

namespace App\Services\Teachers;

use App\Enums\TeacherVerification;
use App\Models\ClassModeratorAssignment;
use App\Models\HonorsClassAssignment;
use App\Models\ServiceLoad;
use App\Models\Teacher;
use App\Models\TeacherIdentityCase;
use App\Models\TeacherOtherAssignment;
use App\Models\TeacherSectionAssignment;

/**
 * Which teachers taking part in a school year still have identity questions.
 *
 * Participants are the teachers any of the five assignment families
 * reference for that year; unassigned and historical-only records are not
 * counted. An open identity case or a contradicted verification blocks —
 * the record may be two people, or someone else entirely. Pending
 * verification and a missing department are reported but do not block:
 * the official directory may never arrive, and section/department checks
 * belong to schedule readiness. This says nothing about whether workloads,
 * sections or rooms are complete.
 */
class TeacherIdentityReadinessService
{
    public const BLOCKING = 'blocking';

    public const WARNING = 'warning';

    /**
     * @return array{school_year:string, ready:bool, teachers:list<array{teacher:Teacher, ready:bool, reasons:list<array{code:string, level:string, message:string, case_id?:int}>}>}
     */
    public function forSchoolYear(string $schoolYear): array
    {
        $ids = collect([TeacherSectionAssignment::class, ClassModeratorAssignment::class, HonorsClassAssignment::class, ServiceLoad::class, TeacherOtherAssignment::class])
            ->flatMap(fn (string $model) => $model::where('school_year', $schoolYear)->pluck('teacher_id'))
            ->unique()->values();

        $openCases = TeacherIdentityCase::open()->get();
        $teachers = Teacher::active()->whereIn('id', $ids)->with('department')->orderBy('full_name')->get();

        $report = $teachers->map(function (Teacher $teacher) use ($openCases) {
            $reasons = [];

            foreach ($openCases->filter(fn ($case) => in_array($teacher->id, $case->involvedTeacherIds(), true)) as $case) {
                $reasons[] = [
                    'code' => 'identity_case_open',
                    'level' => self::BLOCKING,
                    'message' => "{$teacher->full_name}: identity case #{$case->id} ({$case->reason->label()}) needs an Admin decision.",
                    'case_id' => $case->id,
                ];
            }

            if ($teacher->verification_status === TeacherVerification::NeedsReview && $reasons === []) {
                $reasons[] = ['code' => 'identity_needs_review', 'level' => self::BLOCKING, 'message' => "{$teacher->full_name}: a source contradicted the verified identity; an Admin must review it."];
            }

            if ($teacher->verification_status === TeacherVerification::Pending) {
                $reasons[] = ['code' => 'identity_pending', 'level' => self::WARNING, 'message' => "{$teacher->full_name}: identity not yet verified by an Admin."];
            }

            if (! $teacher->department_id) {
                $reasons[] = ['code' => 'department_unassigned', 'level' => self::WARNING, 'message' => "{$teacher->full_name}: no department assigned."];
            }

            return ['teacher' => $teacher, 'ready' => $reasons === [], 'reasons' => $reasons];
        })->values()->all();

        return [
            'school_year' => $schoolYear,
            'ready' => collect($report)->every('ready'),
            'teachers' => $report,
        ];
    }

    /** @return list<array{code:string, level:string, message:string}> */
    public function reasons(string $schoolYear, string $level): array
    {
        return collect($this->forSchoolYear($schoolYear)['teachers'])
            ->flatMap(fn ($row) => collect($row['reasons'])->where('level', $level)->map(fn ($r) => $r + ['teacher_id' => $row['teacher']->id]))
            ->values()->all();
    }
}
