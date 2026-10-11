<?php

namespace Tests\Feature\Scheduling;

use App\Enums\GradeLevel;
use App\Enums\SubmissionStatus;
use App\Enums\TimetableKind;
use App\Enums\TimetableStatus;
use App\Jobs\GenerateTimetable;
use App\Models\Department;
use App\Models\PlantillaSubmission;
use App\Models\Section;
use App\Models\Teacher;
use App\Models\TeacherSectionAssignment;
use App\Models\TeacherUnavailability;
use App\Models\TimetableVersion;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Services\Scheduling\ScheduleGenerationEngine;
use App\Services\Scheduling\ScheduleReadinessService;
use App\Services\Scheduling\ScheduleSearchExhaustedException;
use App\Services\Scheduling\TimetableGenerationInput;
use App\Services\Scheduling\TimetableGenerationProgress;
use App\Services\Scheduling\TimetableGenerator;
use App\Services\Scheduling\TimetableValidationService;
use App\Services\Scheduling\TimetableVersionService;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GenerateTimetableTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EncryptCookies::class);
    }

    public function test_special_job_saves_a_clean_eight_period_draft_without_superseding_normal(): void
    {
        [$admin, $normal] = $this->pendingFixture();
        $normal->update(['status' => TimetableStatus::Draft]);
        $special = TimetableVersion::create(['school_year' => $normal->school_year, 'quarter' => 1, 'version' => 2,
            'kind' => TimetableKind::Special, 'status' => TimetableStatus::Generating, 'created_by_user_id' => $admin->id, 'generation_seed' => 73, 'started_at' => now()]);
        $unusedNormalEngine = \Mockery::mock(TimetableGenerator::class);
        $unusedNormalEngine->shouldNotReceive('generate');
        $this->runJob($special, $unusedNormalEngine);
        $special->refresh();
        $this->assertSame(TimetableStatus::Draft, $special->status, $special->failure_message ?? '');
        $this->assertSame(40, $special->entries()->count());
        $this->assertSame([], app(TimetableValidationService::class)->validate($special));
        $this->assertSame(100, app(TimetableGenerationProgress::class)->read($special)['check_percent']);
        $this->assertSame(TimetableStatus::Draft, $normal->fresh()->status);
        $this->actingAs($admin)->get(route('admin.schedules.show', $special))->assertOk()
            ->assertSee('Special timetable')->assertSee('3:00 - 3:50')->assertSee('DISMISSAL')->assertDontSee('REMEDIATION');
    }

    public function test_each_kind_can_be_generated_independently_but_not_duplicated_while_pending(): void
    {
        Queue::fake();
        [$admin, $normal] = $this->pendingFixture();
        $special = app(TimetableVersionService::class)->startGeneration($normal->school_year, 1, $admin, TimetableKind::Special);
        $this->assertSame(TimetableKind::Special, $special->kind);
        $this->assertSame(2, $special->version);
        Queue::assertPushed(GenerateTimetable::class, fn ($job) => $job->timetableVersionId === $special->id);
        $this->expectException(\DomainException::class);
        app(TimetableVersionService::class)->startGeneration($normal->school_year, 1, $admin, TimetableKind::Special);
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
        app(GenerateTimetable::class, ['timetableVersionId' => $timetable->id])->handle(
            app(ScheduleGenerationEngine::class),
            app(TimetableValidationService::class),
            app(ScheduleReadinessService::class),
            app(AuditLogService::class),
        );

        $timetable->refresh();
        $this->assertSame(TimetableStatus::Draft, $timetable->status);
        $this->assertSame(35, $timetable->entries()->count());
        $this->assertSame(0, $timetable->issues()->count());
        $this->assertSame('draft', app(TimetableGenerationProgress::class)->read($timetable)['status']);
        $this->assertSame(100, app(TimetableGenerationProgress::class)->read($timetable)['check_percent']);
        $this->assertSame(TimetableStatus::Superseded, $earlier->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'timetable.generated', 'auditable_id' => $timetable->id]);
    }

    public function test_progress_is_visible_to_admin_and_restricted_from_department_chairs(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@jhs.test')->firstOrFail();
        $chair = User::where('email', 'chair.fil@jhs.test')->firstOrFail();
        $timetable = TimetableVersion::create([
            'school_year' => '2026-2027', 'quarter' => 1, 'version' => 1,
            'status' => TimetableStatus::Generating, 'created_by_user_id' => $admin->id,
            'generation_seed' => 7,
        ]);
        $url = route('admin.schedules.progress', $timetable);
        $this->actingAs($admin)->getJson($url)->assertOk()
            ->assertJsonPath('status', 'generating')->assertJsonPath('elapsed_seconds', 0)
            ->assertJsonPath('stage', 'Getting ready to start');
        app(TimetableGenerationProgress::class)
            ->record($timetable->id, 'Searching for a valid timetable', 42);
        $this->actingAs($admin)->getJson($url)->assertOk()->assertJsonMissingPath('search_percent')
            ->assertJsonPath('stage', 'Arranging classes')->assertJsonPath('check_percent', 42);
        $this->actingAs($admin)->get(route('admin.schedules.show', $timetable))
            ->assertOk()->assertSee('data-generation-loader', false)
            ->assertSee('Time elapsed:')->assertSee('Stop generation')
            ->assertSee('Class periods without conflicts')->assertSee('role="progressbar"', false)
            ->assertDontSee('Time used for this attempt')->assertDontSee('up to two minutes')
            ->assertDontSee('Search budget used')->assertDontSee('background worker')
            ->assertDontSee('guaranteed completion');
        $timetable->update(['status' => TimetableStatus::Failed]);
        $this->actingAs($admin)->getJson($url)->assertJsonPath('status', 'failed');
        $this->actingAs($chair)->getJson($url)->assertForbidden();
    }

    public function test_progress_percentage_measures_conflict_free_periods_not_elapsed_time(): void
    {
        [$admin, $timetable] = $this->pendingFixture();
        $input = app(TimetableGenerationInput::class)->load($timetable);
        $valid = app(ScheduleGenerationEngine::class)->generate($input['sections'], [], 101, 1);
        $progress = app(TimetableGenerationProgress::class);
        $this->assertSame(0, $progress->checkPercent($input['sections'], [], []));
        $this->assertSame(99, $progress->checkPercent($input['sections'], $valid['entries'], []));
        $blocked = $valid['entries'][0];
        $this->assertLessThan(99, $progress->checkPercent($input['sections'], $valid['entries'], [
            ['teacher_id' => $blocked['teacher_id'], 'day' => $blocked['day'], 'period' => $blocked['period']],
        ]));
        $timetable->update(['started_at' => now()->subMinutes(5), 'generation_checkpoint' => ['entries' => $valid['entries']]]);
        $state = $progress->read($timetable->fresh());
        $this->assertSame(300, $state['elapsed_seconds']);
        $this->assertSame(99, $state['check_percent']);
        $timetable->update(['status' => TimetableStatus::Draft, 'completed_at' => now()]);
        $this->assertNull($progress->read($timetable->fresh())['check_percent']);
    }

    public function test_failed_generation_explains_the_problem_and_collapses_support_details(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@jhs.test')->firstOrFail();
        $message = 'No conflict-free timetable was found within the configured search limit (4449 placements checked; best remaining penalty 18; remaining teacher_break=18 [teacher_break teacher 88 day 4]). No draft was created.';
        $timetable = TimetableVersion::create([
            'school_year' => '2026-2027', 'quarter' => 1, 'version' => 7,
            'status' => TimetableStatus::Failed, 'created_by_user_id' => $admin->id,
            'generation_seed' => 7, 'failure_message' => $message,
        ]);

        $response = $this->actingAs($admin)->get(route('admin.schedules.show', $timetable))
            ->assertOk()->assertSee('A new schedule could not be created.')
            ->assertSee('required teacher breaks')->assertSee('your existing schedules are unchanged.')
            ->assertSee('Technical details (for system support)')->assertSee($message);
        $this->assertMatchesRegularExpression('/<details\b[^>]*>\s*<summary[^>]*>Technical details/', $response->getContent());
        $this->assertDoesNotMatchRegularExpression('/<details\b[^>]*\bopen\b/', $response->getContent());
        $this->assertSame($message, $timetable->fresh()->failure_message);

        $timetable->update(['failure_message' => str_replace('teacher_break=18', 'teacher_break=18, teacher_conflict=1', $message)]);
        $this->get(route('admin.schedules.show', $timetable))
            ->assertSee('without scheduling conflicts')->assertDontSee('with the required teacher breaks.');

        $timetable->update(['failure_message' => 'Scheduling data is no longer ready: missing assignment']);
        $this->get(route('admin.schedules.show', $timetable))->assertSee('Please review the checklist');

        $timetable->update(['failure_message' => '<script>alert("support")</script>']);
        $this->get(route('admin.schedules.show', $timetable))
            ->assertSee('Something interrupted schedule generation.')
            ->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert("support")</script>', false);
    }

    public function test_proven_impossible_availability_fails_without_entries_and_preserves_the_earlier_draft(): void
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
        $this->assertStringContainsString('Scheduling cannot fit:', $failed->failure_message);
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

    public function test_search_sessions_save_the_best_progress_and_continue_the_same_version(): void
    {
        Queue::fake();
        [$admin, $timetable] = $this->pendingFixture();
        $input = app(TimetableGenerationInput::class)->load($timetable);
        $valid = app(ScheduleGenerationEngine::class)->generate($input['sections'], [], 101, 1);
        $engine = \Mockery::mock(TimetableGenerator::class);
        $engine->shouldReceive('generate')->once()->andReturnUsing(function ($sections, $unavailable, $seed, $seconds, $progress, $starting, $checkpoint) use ($valid): never {
            $this->assertSame(45, $seconds);
            $this->assertTrue($progress());
            $checkpoint($valid['entries'], 4);
            $checkpoint(array_slice($valid['entries'], 0, 5), 8);
            throw new ScheduleSearchExhaustedException('Work session ended', $valid['entries']);
        });
        $this->runJob($timetable, $engine);
        $timetable->refresh();
        $this->assertSame(TimetableStatus::Generating, $timetable->status);
        $this->assertNull($timetable->failure_message);
        $this->assertSame(0, $timetable->entries()->count());
        $this->assertCount(35, $timetable->generation_checkpoint['entries']);
        $this->assertSame(4, $timetable->generation_checkpoint['penalty']);
        $this->assertSame(1, $timetable->generation_checkpoint['round']);
        Queue::assertPushed(GenerateTimetable::class, fn ($job) => $job->timetableVersionId === $timetable->id && $job->round === 1);

        // A duplicate older queue message cannot overwrite the next work session.
        $unused = \Mockery::mock(TimetableGenerator::class);
        $unused->shouldNotReceive('generate');
        $this->runJob($timetable, $unused, 0);

        // A fresh engine/worker resumes the durable checkpoint.
        $this->runJob($timetable, null, 1);
        $this->assertSame(TimetableStatus::Draft, $timetable->fresh()->status);
        $this->assertNull($timetable->fresh()->generation_checkpoint);
        $this->assertSame(35, $timetable->entries()->count());
        $this->assertSame([], app(TimetableValidationService::class)->validate($timetable->fresh()));
    }

    public function test_admin_can_stop_generation_and_queued_jobs_cannot_save_afterwards(): void
    {
        Queue::fake();
        [$admin, $timetable] = $this->pendingFixture();
        $chair = User::where('email', 'chair.fil@jhs.test')->firstOrFail();
        $this->actingAs($chair)->post(route('admin.schedules.stop', $timetable))->assertForbidden();
        $this->actingAs($admin)->post(route('admin.schedules.stop', $timetable))->assertRedirect();
        $this->assertSame(TimetableStatus::Cancelled, $timetable->fresh()->status);
        $this->assertNotNull($timetable->fresh()->completed_at);
        $this->assertNull($timetable->fresh()->generation_checkpoint);
        $unused = \Mockery::mock(TimetableGenerator::class);
        $unused->shouldNotReceive('generate');
        $this->runJob($timetable, $unused);
        $this->assertSame(0, $timetable->entries()->count());
        Queue::assertNothingPushed();
        $this->get(route('admin.schedules.show', $timetable))->assertOk()->assertSee('Generation was stopped.');
        $this->post(route('admin.schedules.stop', $timetable))->assertRedirect();
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_stopping_during_a_search_prevents_the_completed_result_from_being_saved(): void
    {
        [$admin, $timetable] = $this->pendingFixture();
        $input = app(TimetableGenerationInput::class)->load($timetable);
        $valid = app(ScheduleGenerationEngine::class)->generate($input['sections'], [], 101, 1);
        $engine = \Mockery::mock(TimetableGenerator::class);
        $engine->shouldReceive('generate')->once()->andReturnUsing(function ($sections, $unavailable, $seed, $seconds, $progress) use ($valid, $admin, $timetable): array {
            app(TimetableVersionService::class)->stopGeneration($timetable, $admin);
            $this->assertFalse($progress());

            return $valid;
        });
        $this->runJob($timetable, $engine);
        $this->assertSame(TimetableStatus::Cancelled, $timetable->fresh()->status);
        $this->assertSame(0, $timetable->entries()->count());
    }

    public function test_changed_inputs_discard_a_stale_checkpoint_and_recheck_before_saving(): void
    {
        Queue::fake();
        [$admin, $timetable] = $this->pendingFixture();
        $loader = app(TimetableGenerationInput::class);
        $input = $loader->load($timetable);
        $valid = app(ScheduleGenerationEngine::class)->generate($input['sections'], [], 101, 1);
        $timetable->update(['generation_checkpoint' => ['round' => 0, 'fingerprint' => 'outdated', 'entries' => $valid['entries'], 'penalty' => 0]]);
        $engine = \Mockery::mock(TimetableGenerator::class);
        $engine->shouldReceive('generate')->once()->andReturnUsing(function ($sections, $unavailable, $seed, $seconds, $progress, $starting) use ($timetable, $valid): array {
            $this->assertSame([], $starting);
            $teacherId = $sections[0]['assignments']['ENG']['teacher_id'];
            TeacherUnavailability::create([
                'teacher_id' => $teacherId, 'school_year' => $timetable->school_year,
                'quarter' => 1, 'day' => 1, 'period' => 1,
            ]);

            return $valid;
        });
        $this->runJob($timetable, $engine);
        $this->assertSame(TimetableStatus::Generating, $timetable->fresh()->status);
        $this->assertSame(0, $timetable->entries()->count());
        $this->assertSame(['round' => 1], $timetable->fresh()->generation_checkpoint);
        Queue::assertPushed(GenerateTimetable::class);
    }

    public function test_unexpected_worker_failure_is_reported_instead_of_leaving_a_stuck_generation(): void
    {
        [$admin, $timetable] = $this->pendingFixture();
        (new GenerateTimetable($timetable->id))->failed(new \RuntimeException('Unexpected interruption'));
        $this->assertSame(TimetableStatus::Failed, $timetable->fresh()->status);
        $this->assertSame('Unexpected interruption', $timetable->fresh()->failure_message);
    }

    private function pendingFixture(): array
    {
        $this->seed();
        $admin = User::where('email', 'admin@jhs.test')->firstOrFail();
        $this->createReadyFixture('2099-2100', $admin);
        $timetable = TimetableVersion::create([
            'school_year' => '2099-2100', 'quarter' => 1, 'version' => 1,
            'status' => TimetableStatus::Generating, 'created_by_user_id' => $admin->id,
            'generation_seed' => 7, 'started_at' => now(),
        ]);

        return [$admin, $timetable];
    }

    private function runJob(TimetableVersion $timetable, ?TimetableGenerator $engine = null, int $round = 0): void
    {
        (new GenerateTimetable($timetable->id, $round))->handle(
            $engine ?? app(ScheduleGenerationEngine::class),
            app(TimetableValidationService::class),
            app(ScheduleReadinessService::class),
            app(AuditLogService::class),
        );
    }
}
