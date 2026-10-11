<?php

namespace Tests\Feature\Scheduling;

use App\Enums\GradeLevel;
use App\Enums\SubmissionStatus;
use App\Enums\TimetableKind;
use App\Enums\TimetableStatus;
use App\Models\Department;
use App\Models\ClassModeratorAssignment;
use App\Models\PlantillaSubmission;
use App\Models\Section;
use App\Models\SystemConstant;
use App\Models\Teacher;
use App\Models\TeacherSectionAssignment;
use App\Models\TimetableVersion;
use App\Models\User;
use App\Services\Scheduling\ScheduleConcurrencyException;
use App\Services\Scheduling\ScheduleTemplateGenerator;
use App\Services\Scheduling\SpecialScheduleGenerator;
use App\Services\Scheduling\TimetableGenerationInput;
use App\Services\Scheduling\TimetableEditor;
use App\Services\Scheduling\TimetableVersionService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TimetableEditingTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_replace_a_cell_and_undo_restores_it(): void
    {
        [$admin, $timetable, $departments] = $this->draftWithACompleteSection();
        $this->actingAs($admin);
        $editor = app(TimetableEditor::class);
        $entry = $timetable->entries()->with('department')->orderBy('id')->firstOrFail();
        $originalDepartmentId = $entry->department_id;
        $newCode = $entry->department->code === 'ENG' ? 'CLE' : 'ENG';

        $edited = $editor->updateEntry($timetable, $entry->id, [
            'action' => 'replace', 'expected_sequence' => 0, 'subject_code' => $newCode,
        ], $admin);

        $this->assertSame(1, $edited['sequence']);
        $this->assertGreaterThan(0, $edited['issues']);
        $this->assertSame($departments[$newCode]->id, $entry->fresh()->department_id);

        $undone = $editor->undo($timetable, 1, $admin);

        $this->assertSame(2, $undone['sequence']);
        $this->assertSame(0, $undone['issues']);
        $this->assertSame($originalDepartmentId, $entry->fresh()->department_id);
        $this->assertDatabaseHas('timetable_changes', ['timetable_version_id' => $timetable->id, 'action' => 'undo']);
    }

    public function test_editor_rejects_a_stale_edit_sequence(): void
    {
        [$admin, $timetable] = $this->draftWithACompleteSection();
        $this->actingAs($admin);
        $entry = $timetable->entries()->firstOrFail();

        $this->expectException(ScheduleConcurrencyException::class);
        app(TimetableEditor::class)->updateEntry($timetable, $entry->id, [
            'action' => 'replace', 'expected_sequence' => 4, 'subject_code' => 'ENG',
        ], $admin);
    }

    public function test_admin_approval_replaces_the_prior_published_version(): void
    {
        [$admin, $timetable] = $this->draftWithACompleteSection();
        $previous = TimetableVersion::create([
            'school_year' => $timetable->school_year,
            'quarter' => $timetable->quarter,
            'version' => 0,
            'status' => TimetableStatus::Approved,
            'created_by_user_id' => $admin->id,
            'approved_by_user_id' => $admin->id,
            'generation_seed' => 1,
            'approved_at' => now(),
        ]);
        $this->actingAs($admin);

        app(TimetableVersionService::class)->approve($timetable, $admin);

        $this->assertSame(TimetableStatus::Approved, $timetable->fresh()->status);
        $this->assertSame(TimetableStatus::Superseded, $previous->fresh()->status);
    }

    public function test_approval_is_blocked_and_validation_errors_are_kept_on_the_draft(): void
    {
        [$admin, $timetable] = $this->draftWithACompleteSection();
        $timetable->entries()->orderBy('id')->firstOrFail()->delete();
        $this->actingAs($admin);

        try {
            app(TimetableVersionService::class)->approve($timetable, $admin);
            $this->fail('Approval should have been blocked by the missing period.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('hard timetable errors', $exception->getMessage());
        }

        $this->assertSame(TimetableStatus::Draft, $timetable->fresh()->status);
        $this->assertDatabaseHas('timetable_validation_issues', ['timetable_version_id' => $timetable->id, 'code' => 'missing_cell']);
    }

    public function test_approval_rechecks_the_latest_teacher_assignment(): void
    {
        [$admin, $timetable, $departments] = $this->draftWithACompleteSection();
        $teacher = Teacher::create(['full_name' => 'Replacement English Teacher', 'department_id' => $departments['ENG']->id, 'source' => 'test']);
        TeacherSectionAssignment::where('school_year', $timetable->school_year)->where('department_id', $departments['ENG']->id)->update(['teacher_id' => $teacher->id]);
        $this->assertApprovalBlocked($timetable, $admin, 'scheduled_teacher_mismatch');
    }

    public function test_approval_rechecks_teacher_department_eligibility(): void
    {
        [$admin, $timetable, $departments] = $this->draftWithACompleteSection();
        $assignment = TeacherSectionAssignment::where('school_year', $timetable->school_year)->where('department_id', $departments['ENG']->id)->firstOrFail();
        $assignment->teacher->update(['department_id' => $departments['CLE']->id]);
        $this->assertApprovalBlocked($timetable, $admin, 'teacher_department_mismatch');
    }

    public function test_approval_rechecks_plantilla_submission_status(): void
    {
        [$admin, $timetable, $departments] = $this->draftWithACompleteSection();
        PlantillaSubmission::where('school_year', $timetable->school_year)->where('department_id', $departments['ENG']->id)->update(['status' => SubmissionStatus::Returned]);
        $this->assertApprovalBlocked($timetable, $admin, 'plantilla_not_submitted');
    }

    public function test_same_subject_replacement_updates_a_stale_teacher(): void
    {
        [$admin, $timetable, $departments] = $this->draftWithACompleteSection();
        $teacher = Teacher::create(['full_name' => 'Replacement English Teacher', 'department_id' => $departments['ENG']->id, 'source' => 'test']);
        TeacherSectionAssignment::where('school_year', $timetable->school_year)->where('department_id', $departments['ENG']->id)->update(['teacher_id' => $teacher->id]);
        $entry = $timetable->entries()->where('department_id', $departments['ENG']->id)->firstOrFail();
        $result = app(TimetableEditor::class)->updateEntry($timetable, $entry->id, ['action' => 'replace', 'expected_sequence' => 0, 'subject_code' => 'ENG'], $admin);
        $this->assertFalse($result['unchanged']);
        $this->assertSame($teacher->id, $entry->fresh()->teacher_id);
    }

    private function assertApprovalBlocked(TimetableVersion $timetable, User $admin, string $code): void
    {
        $this->actingAs($admin);
        try {
            app(TimetableVersionService::class)->approve($timetable, $admin);
            $this->fail('Approval should recheck current scheduling data.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('hard timetable errors', $exception->getMessage());
        }
        $this->assertSame(TimetableStatus::Draft, $timetable->fresh()->status);
        $this->assertDatabaseHas('timetable_validation_issues', ['timetable_version_id' => $timetable->id, 'code' => $code]);
    }

    public function test_approval_rechecks_the_reduced_major_teacher_cap_after_an_advisory_assignment(): void
    {
        [$admin, $timetable, $departments] = $this->draftWithACompleteSection();
        $assignment = TeacherSectionAssignment::where('school_year', $timetable->school_year)->where('department_id', $departments['ENG']->id)->firstOrFail();
        foreach (range(1, 3) as $index) {
            $section = Section::create(['school_year' => $timetable->school_year, 'grade_level' => 'G7', 'name' => 'Extra '.$index]);
            TeacherSectionAssignment::create(['teacher_id' => $assignment->teacher_id, 'section_id' => $section->id, 'department_id' => $departments['ENG']->id, 'school_year' => $timetable->school_year, 'hours' => 4.2]);
        }
        ClassModeratorAssignment::create(['teacher_id' => $assignment->teacher_id, 'section_id' => $assignment->section_id, 'school_year' => $timetable->school_year, 'hours' => 3]);
        $this->assertApprovalBlocked($timetable, $admin, 'teacher_section_cap');
        $issue = $timetable->issues()->where('code', 'teacher_section_cap')->firstOrFail();
        $this->assertSame(3, $issue->details['section_cap']);
        $this->assertSame(4, $issue->details['section_count']);
    }

    public function test_special_approval_does_not_replace_the_approved_normal_schedule(): void
    {
        [$admin, $special] = $this->draftWithACompleteSection(TimetableKind::Special);
        $normal = TimetableVersion::create(['school_year' => $special->school_year, 'quarter' => 1, 'version' => 0, 'status' => TimetableStatus::Approved, 'created_by_user_id' => $admin->id, 'generation_seed' => 1]);
        $previousSpecial = TimetableVersion::create(['school_year' => $special->school_year, 'quarter' => 1, 'version' => 2, 'kind' => TimetableKind::Special, 'status' => TimetableStatus::Approved, 'created_by_user_id' => $admin->id, 'generation_seed' => 1]);
        app(TimetableVersionService::class)->approve($special, $admin);
        $this->assertSame(TimetableStatus::Approved, $special->fresh()->status);
        $this->assertSame(TimetableStatus::Approved, $normal->fresh()->status);
        $this->assertSame(TimetableStatus::Superseded, $previousSpecial->fresh()->status);
        $clone = app(TimetableVersionService::class)->cloneApproved($special->fresh(), $admin);
        $this->assertSame(TimetableKind::Special, $clone->kind);
        $this->assertSame(40, $clone->entries()->count());
        $this->assertSame(0, $clone->issues()->count());
    }

    public function test_special_swap_changes_all_days_and_undo_restores_all_of_them(): void
    {
        [$admin, $special] = $this->draftWithACompleteSection(TimetableKind::Special);
        $entry = $special->entries()->where('day', 1)->where('period', 1)->firstOrFail();
        $target = $special->entries()->where('day', 1)->where('period', 8)->firstOrFail();
        $original = $special->entries()->orderBy('day')->orderBy('period')->get()->map->only(['department_id', 'teacher_id'])->all();
        $edited = app(TimetableEditor::class)->updateEntry($special, $entry->id, ['action' => 'swap', 'target_day' => 3, 'target_period' => 8, 'expected_sequence' => 0], $admin);
        $this->assertSame(0, $edited['issues']);
        foreach (range(1, 5) as $day) {
            $this->assertSame($target->department_id, $special->entries()->where('day', $day)->where('period', 1)->value('department_id'));
            $this->assertSame($entry->department_id, $special->entries()->where('day', $day)->where('period', 8)->value('department_id'));
        }
        $this->assertCount(10, $special->changes()->first()->before_state);
        $undone = app(TimetableEditor::class)->undo($special, 1, $admin);
        $this->assertSame(0, $undone['issues']);
        $this->assertSame($original, $special->entries()->orderBy('day')->orderBy('period')->get()->map->only(['department_id', 'teacher_id'])->all());
    }

    public function test_special_replacement_changes_all_days_and_keeps_errors_visible(): void
    {
        [$admin, $special] = $this->draftWithACompleteSection(TimetableKind::Special);
        $entry = $special->entries()->where('day', 2)->where('period', 8)->firstOrFail();
        $code = $entry->department->code === 'ENG' ? 'CLE' : 'ENG';
        $result = app(TimetableEditor::class)->updateEntry($special, $entry->id, ['action' => 'replace', 'subject_code' => $code, 'expected_sequence' => 0], $admin);
        $this->assertGreaterThan(0, $result['issues']);
        $this->assertCount(5, $special->changes()->first()->before_state);
        $this->assertSame(1, $special->entries()->where('period', 8)->get()->pluck('department_id')->unique()->count());
        $this->assertDatabaseHas('timetable_validation_issues', ['timetable_version_id' => $special->id, 'code' => 'special_subject_daily_count']);
    }

    public function test_version_lifecycle_requires_its_shared_allocation_lock_row(): void
    {
        [$admin, $draft] = $this->draftWithACompleteSection();
        $draft->update(['status' => TimetableStatus::Approved]);
        SystemConstant::where('key', 'current_school_year')->delete();
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Configure the current school year');
        app(TimetableVersionService::class)->cloneApproved($draft, $admin);
    }

    private function draftWithACompleteSection(TimetableKind $kind = TimetableKind::Normal): array
    {
        $this->seed();
        $admin = User::where('email', 'admin@jhs.test')->firstOrFail();
        $year = '2099-2100';
        $section = Section::create(['school_year' => $year, 'grade_level' => GradeLevel::G7, 'name' => 'Pilot']);
        $departments = Department::query()->get()->keyBy('code');
        $teachers = [];

        foreach ($departments as $department) {
            PlantillaSubmission::create(['school_year' => $year, 'department_id' => $department->id, 'status' => SubmissionStatus::Submitted]);
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
        }

        $timetable = TimetableVersion::create([
            'school_year' => $year,
            'quarter' => 1,
            'version' => 1,
            'kind' => $kind,
            'status' => TimetableStatus::Draft,
            'created_by_user_id' => $admin->id,
            'generation_seed' => 7,
        ]);
        $template = $kind === TimetableKind::Special
            ? (new SpecialScheduleGenerator)->generate(app(TimetableGenerationInput::class)->load($timetable)['sections'], [], 12)['entries']
            : (new ScheduleTemplateGenerator(12))->build('G7');

        foreach ($template as $entry) {
            $timetable->entries()->create([
                'section_id' => $section->id,
                'department_id' => $departments[$entry['department_code']]->id,
                'teacher_id' => $teachers[$entry['department_code']]->id,
                'day' => $entry['day'],
                'period' => $entry['period'],
            ]);
        }

        return [$admin, $timetable, $departments];
    }
}
