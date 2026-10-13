<?php

namespace Tests\Feature\TeacherIdentity;

use App\Enums\GradeLevel;
use App\Enums\IdentityCaseReason;
use App\Enums\SubmissionStatus;
use App\Enums\TeacherVerification;
use App\Models\Department;
use App\Models\PlantillaSubmission;
use App\Models\Section;
use App\Models\ServiceLoad;
use App\Models\Teacher;
use App\Models\TeacherSectionAssignment;
use App\Models\User;
use App\Services\Scheduling\ScheduleNotReadyException;
use App\Services\Scheduling\ScheduleReadinessService;
use App\Services\Scheduling\TimetableVersionService;
use App\Services\Teachers\IdentityObservation;
use App\Services\Teachers\IdentityOutcome;
use App\Services\Teachers\TeacherIdentityReadinessService;
use App\Services\Teachers\TeacherIdentityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Identity readiness for a school year, and its agreed effect on schedule
 * generation: open identity questions block, pending verification warns.
 */
class TeacherIdentityReadinessTest extends TestCase
{
    use RefreshDatabase;

    private const YEAR = '2030-2031';

    private function verified(Teacher $teacher): Teacher
    {
        $teacher->forceFill([
            'verification_status' => TeacherVerification::Verified,
            'verified_at' => now(),
            'verification_evidence' => ['basis' => 'other', 'full_name' => $teacher->full_name, 'department_id' => $teacher->department_id],
        ])->save();

        return $teacher;
    }

    private function assign(Teacher $teacher, Section $section, string $year = self::YEAR): void
    {
        TeacherSectionAssignment::create([
            'teacher_id' => $teacher->id, 'section_id' => $section->id,
            'department_id' => $teacher->department_id ?? Department::first()->id, 'school_year' => $year, 'hours' => 4,
        ]);
    }

    /** One section fully staffed and submitted, so only identity can block. */
    private function readyYear(User $admin): array
    {
        $section = Section::create(['school_year' => self::YEAR, 'grade_level' => GradeLevel::G7, 'name' => 'Pilot']);
        $teachers = [];

        foreach (Department::orderBy('code')->get() as $department) {
            $teachers[$department->code] = $teacher = Teacher::create(['full_name' => "Pilot {$department->code} Teacher", 'department_id' => $department->id]);
            TeacherSectionAssignment::create(['teacher_id' => $teacher->id, 'section_id' => $section->id, 'department_id' => $department->id, 'school_year' => self::YEAR, 'hours' => $department->hours_per_section]);
            PlantillaSubmission::create(['department_id' => $department->id, 'school_year' => self::YEAR, 'status' => SubmissionStatus::Submitted, 'submitted_by_user_id' => $admin->id, 'submitted_at' => now()]);
        }

        return $teachers;
    }

    private function openCaseAbout(Teacher $teacher): void
    {
        app(TeacherIdentityService::class)->openCase(
            new IdentityObservation(IdentityObservation::PLANTILLA, 'plantilla_row:777', $teacher->full_name.' Jr', $teacher->department_id),
            IdentityOutcome::review(IdentityCaseReason::PossibleMatch, [$teacher->id]),
        );
    }

    public function test_the_report_covers_participants_and_explains_each_reason(): void
    {
        $this->seed(\Database\Seeders\DepartmentSeeder::class);
        $fil = Department::where('code', 'FIL')->first();
        $section = Section::create(['school_year' => self::YEAR, 'grade_level' => GradeLevel::G7, 'name' => 'Pilot']);
        $old = Section::create(['school_year' => '2025-2026', 'grade_level' => GradeLevel::G7, 'name' => 'Old']);

        $ready = $this->verified(Teacher::create(['full_name' => 'Verified Person', 'department_id' => $fil->id]));
        $pending = Teacher::create(['full_name' => 'Pending Person', 'department_id' => $fil->id]);
        $conflicted = $this->verified(Teacher::create(['full_name' => 'Conflicted Person', 'department_id' => $fil->id]));
        $homeless = Teacher::create(['full_name' => 'Homeless Person']);
        $historical = Teacher::create(['full_name' => 'Historical Person', 'department_id' => $fil->id]);
        Teacher::create(['full_name' => 'Unassigned Person', 'department_id' => $fil->id]);

        $this->assign($ready, $section);
        $this->assign($pending, Section::create(['school_year' => self::YEAR, 'grade_level' => GradeLevel::G7, 'name' => 'Second']));
        $this->assign($conflicted, Section::create(['school_year' => self::YEAR, 'grade_level' => GradeLevel::G7, 'name' => 'Third']));
        ServiceLoad::create(['teacher_id' => $homeless->id, 'school_year' => self::YEAR, 'hours' => 3]);
        $this->assign($historical, $old, '2025-2026');
        $this->openCaseAbout($conflicted);

        $report = app(TeacherIdentityReadinessService::class)->forSchoolYear(self::YEAR);
        $rows = collect($report['teachers'])->keyBy(fn ($row) => $row['teacher']->full_name);

        $this->assertFalse($report['ready']);
        $this->assertEqualsCanonicalizing(['Verified Person', 'Pending Person', 'Conflicted Person', 'Homeless Person'], $rows->keys()->all(),
            'historical-only and unassigned teachers are not participants');
        $this->assertTrue($rows['Verified Person']['ready']);
        $this->assertSame(['identity_pending'], array_column($rows['Pending Person']['reasons'], 'code'));
        $this->assertSame('blocking', $rows['Conflicted Person']['reasons'][0]['level']);
        $this->assertContains('department_unassigned', array_column($rows['Homeless Person']['reasons'], 'code'));
    }

    public function test_pending_verification_warns_but_never_blocks_generation(): void
    {
        $this->seed();
        Queue::fake();
        $admin = User::where('email', 'admin@jhs.test')->first();
        $this->readyYear($admin);

        $check = app(ScheduleReadinessService::class)->check(self::YEAR);

        $this->assertTrue($check['ready'], json_encode($check['blockers']));
        $this->assertNotEmpty($check['warnings']);
        $this->assertSame('identity_pending', $check['warnings'][0]['code']);
        app(TimetableVersionService::class)->startGeneration(self::YEAR, 1, $admin);
    }

    public function test_an_open_identity_case_blocks_generation_until_decided(): void
    {
        $this->seed();
        Queue::fake();
        $admin = User::where('email', 'admin@jhs.test')->first();
        $teachers = $this->readyYear($admin);
        $this->openCaseAbout($teachers['FIL']);

        $check = app(ScheduleReadinessService::class)->check(self::YEAR);
        $this->assertFalse($check['ready']);
        $this->assertSame(['teacher_identity_conflict'], array_values(array_unique(array_column($check['blockers'], 'code'))));

        $this->expectException(ScheduleNotReadyException::class);
        app(TimetableVersionService::class)->startGeneration(self::YEAR, 1, $admin);
    }

    public function test_the_report_is_admin_only(): void
    {
        $this->seed();

        $this->actingAs(User::where('email', 'admin@jhs.test')->first())->get(route('admin.teacher-readiness.index', ['school_year' => self::YEAR]))->assertOk();
        $this->actingAs(User::where('email', 'chair.fil@jhs.test')->first())->get(route('admin.teacher-readiness.index'))->assertForbidden();
    }
}
