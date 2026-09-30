<?php

namespace Tests\Feature\Scheduling;

use App\Enums\GradeLevel;
use App\Enums\SubmissionStatus;
use App\Enums\TimetableStatus;
use App\Models\Department;
use App\Models\PlantillaSubmission;
use App\Models\Section;
use App\Models\Teacher;
use App\Models\TeacherSectionAssignment;
use App\Models\TeacherUnavailability;
use App\Models\TimetableVersion;
use App\Models\User;
use App\Services\Scheduling\ScheduleReadinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Tests\TestCase;

class GenerateTimetableTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EncryptCookies::class);
    }

    public function test_generation_job_persists_a_complete_clean_draft(): void
    {
        $this->seed();
        $year = '2099-2100';
        $admin = User::where('email', 'admin@jhs.test')->firstOrFail();
        $this->createReadyFixture($year, $admin);

        $readiness = app(ScheduleReadinessService::class)->check($year);
        $this->assertTrue($readiness['ready'], json_encode($readiness['blockers']));

        $earlier = TimetableVersion::create([
            'school_year' => $year,
            'quarter' => 1,
            'version' => 1,
            'status' => TimetableStatus::Draft,
            'created_by_user_id' => $admin->id,
            'generation_seed' => 101,
            'started_at' => now()->subMinute(),
            'completed_at' => now()->subMinute(),
        ]);
        $timetable = TimetableVersion::create([
            'school_year' => $year,
            'quarter' => 1,
            'version' => 2,
            'status' => TimetableStatus::Generating,
            'created_by_user_id' => $admin->id,
            'generation_seed' => 20260930,
            'started_at' => now(),
        ]);

        $this->actingAs($admin);
        app(\App\Jobs\GenerateTimetable::class, ['timetableVersionId' => $timetable->id])->handle(
            app(\App\Services\Scheduling\ScheduleGenerationEngine::class),
            app(\App\Services\Scheduling\TimetableValidationService::class),
            app(ScheduleReadinessService::class),
            app(\App\Services\Audit\AuditLogService::class),
        );

        $timetable->refresh();
        $this->assertSame(TimetableStatus::Draft, $timetable->status);
        $this->assertSame(35, $timetable->entries()->count());
        $this->assertSame(0, $timetable->issues()->count());
        $this->assertSame(TimetableStatus::Superseded, $earlier->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'timetable.generated', 'auditable_id' => $timetable->id]);
    }

    public function test_search_exhaustion_fails_without_entries_and_preserves_the_earlier_draft(): void
    {
        $this->seed();
        $year = '2099-2100';
        $admin = User::where('email', 'admin@jhs.test')->firstOrFail();
        $teachers = $this->createReadyFixture($year, $admin);

        for ($day = 1; $day <= 5; $day++) {
            for ($period = 1; $period <= 7; $period++) {
                TeacherUnavailability::create([
                    'teacher_id' => $teachers['ENG']->id,
                    'school_year' => $year,
                    'quarter' => 1,
                    'day' => $day,
                    'period' => $period,
                    'reason' => 'Impossible test fixture',
                    'created_by_user_id' => $admin->id,
                ]);
            }
        }

        $earlier = TimetableVersion::create([
            'school_year' => $year,
            'quarter' => 1,
            'version' => 1,
            'status' => TimetableStatus::Draft,
            'created_by_user_id' => $admin->id,
            'generation_seed' => 101,
            'started_at' => now()->subMinute(),
            'completed_at' => now()->subMinute(),
        ]);
        $failed = TimetableVersion::create([
            'school_year' => $year,
            'quarter' => 1,
            'version' => 2,
            'status' => TimetableStatus::Generating,
            'created_by_user_id' => $admin->id,
            'generation_seed' => 20261001,
            'started_at' => now(),
        ]);

        $this->actingAs($admin);
        $this->runJob($failed);

        $failed->refresh();
        $this->assertSame(TimetableStatus::Failed, $failed->status);
        $this->assertSame(0, $failed->entries()->count());
        $this->assertNotNull($failed->failure_message);
        $this->assertStringContainsString('No conflict-free timetable', $failed->failure_message);
        $this->assertSame(TimetableStatus::Draft, $earlier->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'timetable.generation_failed', 'auditable_id' => $failed->id]);
    }

    /** @return array<string, Teacher> */
    private function createReadyFixture(string $year, User $admin): array
    {
        $section = Section::create(['school_year' => $year, 'grade_level' => GradeLevel::G7, 'name' => 'Pilot']);
        $teachers = [];

        foreach (Department::orderBy('code')->get() as $department) {
            $teacher = Teacher::create([
                'full_name' => 'Pilot '.$department->code.' Teacher',
                'department_id' => $department->id,
                'source' => 'test',
            ]);
            $teachers[$department->code] = $teacher;
            TeacherSectionAssignment::create([
                'teacher_id' => $teacher->id,
                'section_id' => $section->id,
                'department_id' => $department->id,
                'school_year' => $year,
                'hours' => $department->hours_per_section,
            ]);
            PlantillaSubmission::create([
                'department_id' => $department->id,
                'school_year' => $year,
                'status' => SubmissionStatus::Submitted,
                'submitted_by_user_id' => $admin->id,
                'submitted_at' => now(),
            ]);
        }

        return $teachers;
    }

    private function runJob(TimetableVersion $timetable): void
    {
        app(\App\Jobs\GenerateTimetable::class, ['timetableVersionId' => $timetable->id])->handle(
            app(\App\Services\Scheduling\ScheduleGenerationEngine::class),
            app(\App\Services\Scheduling\TimetableValidationService::class),
            app(ScheduleReadinessService::class),
            app(\App\Services\Audit\AuditLogService::class),
        );
    }
}
