<?php

namespace Tests\Feature\Scheduling;

use App\Enums\TimetableStatus;
use App\Models\SystemConstant;
use App\Models\TimetableVersion;
use App\Models\User;
use App\Models\Department;
use App\Models\Section;
use App\Models\Teacher;
use App\Services\Scheduling\TimetablePresentationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Http\Request;
use Tests\TestCase;

class TimetableAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EncryptCookies::class);
    }

    public function test_admin_can_open_schedule_management_and_generation_is_gated_by_readiness(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@jhs.test')->firstOrFail();

        $this->actingAs($admin)->get(route('admin.schedules.index'))->assertOk()->assertSee('Generation readiness');
        $this->actingAs($admin)->post(route('admin.schedules.generate'), ['quarter' => 1])
            ->assertRedirect(route('admin.schedules.index', ['quarter' => 1]))
            ->assertSessionHas('schedule_blockers');

        $this->assertDatabaseCount('timetable_versions', 0);
    }

    public function test_department_chairs_can_only_open_an_approved_current_year_schedule(): void
    {
        $this->seed();
        $chair = User::where('email', 'chair.fil@jhs.test')->firstOrFail();
        $currentYear = (string) SystemConstant::get('current_school_year', '2026-2027');

        $draft = TimetableVersion::create([
            'school_year' => $currentYear,
            'quarter' => 1,
            'version' => 1,
            'status' => TimetableStatus::Draft,
            'created_by_user_id' => User::where('email', 'admin@jhs.test')->value('id'),
            'generation_seed' => 7,
        ]);

        $this->actingAs($chair)->get(route('chair.schedules.index'))->assertOk()->assertSee('No approved timetable yet');
        $this->actingAs($chair)->get(route('chair.schedules.show', $draft))->assertNotFound();
        $this->actingAs($chair)->get(route('chair.schedules.export', ['timetable' => $draft, 'format' => 'pdf']))->assertNotFound();
        $this->actingAs($chair)->post(route('admin.schedules.generate'), ['quarter' => 1])->assertForbidden();
    }

    public function test_admin_schedule_editor_page_renders_a_draft_grid(): void
    {
        [$admin, $draft] = $this->draftWithSingleEnglishEntry();

        $this->actingAs($admin)->get(route('admin.schedules.show', $draft))
            ->assertOk()->assertSee('Admin editing')->assertSee('English')->assertSee('Undo last edit');
    }

    public function test_schedule_grid_uses_the_workbook_structure_and_full_subject_names(): void
    {
        [$admin, $draft, $section] = $this->draftWithSingleEnglishEntry();

        $response = $this->actingAs($admin)->get(route('admin.schedules.show', $draft));
        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('data-timetable-grid', $html);
        $this->assertStringContainsString('data-grade-schedule="G7"', $html);
        $this->assertStringContainsString('DAY 1', $html);
        $this->assertStringContainsString('>TIME<', $html);
        $this->assertStringContainsString('7 - '.$section->name, $html);
        $this->assertStringContainsString('RECESS', $html);
        $this->assertStringContainsString('LUNCH', $html);
        $this->assertStringContainsString('EXAMEN', $html);
        $this->assertStringContainsString('CLASSROOM CLEAN-UP (CC)', $html);
        $this->assertStringContainsString('REMEDIATION (Science)', $html);
        $this->assertStringContainsString('English', $html);

        $grid = strstr($html, 'data-timetable-grid');
        $this->assertIsString($grid);
        $grid = strstr($grid, '<!-- timetable-grid-end -->', true);
        $this->assertIsString($grid);
        $this->assertStringNotContainsString('Period / time', $grid);
        $this->assertStringNotContainsString('Period 1', $grid);
        $this->assertStringNotContainsString('>ENG<', $grid);
        $this->assertSame(1, substr_count($html, 'data-schedule-filter="grade"'));
        $this->assertSame(1, substr_count($html, 'data-schedule-filter="day"'));
    }

    public function test_all_grades_are_separate_tables_in_grade_seven_to_ten_order(): void
    {
        [$admin, $draft] = $this->draftWithSingleEnglishEntry();

        $html = $this->actingAs($admin)
            ->get(route('admin.schedules.show', $draft).'?grade=all&day=1')
            ->assertOk()->getContent();

        $this->assertSame(4, substr_count($html, 'data-grade-schedule='));
        $this->assertStringContainsString('data-grade-schedule="G7"', $html);
        $this->assertStringContainsString('data-grade-schedule="G8"', $html);
        $this->assertStringContainsString('data-grade-schedule="G9"', $html);
        $this->assertStringContainsString('data-grade-schedule="G10"', $html);
        $this->assertTrue(
            strpos($html, 'data-grade-schedule="G7"') < strpos($html, 'data-grade-schedule="G8"')
            && strpos($html, 'data-grade-schedule="G8"') < strpos($html, 'data-grade-schedule="G9"')
            && strpos($html, 'data-grade-schedule="G9"') < strpos($html, 'data-grade-schedule="G10"'),
        );
    }

    public function test_section_and_empty_department_filters_keep_the_owner_grade_structure(): void
    {
        [$admin, $draft, $section] = $this->draftWithSingleEnglishEntry();
        $filipino = Department::where('code', 'FIL')->firstOrFail();
        $query = http_build_query([
            'grade' => 'all',
            'day' => 1,
            'section_id' => $section->id,
            'department_id' => $filipino->id,
        ]);

        $html = $this->actingAs($admin)
            ->get(route('admin.schedules.show', $draft).'?'.$query)
            ->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'data-grade-schedule='));
        $this->assertStringContainsString('data-grade-schedule="G7"', $html);
        $this->assertStringNotContainsString('data-grade-schedule="G8"', $html);
        $this->assertStringContainsString('RECESS', $html);
        $this->assertStringContainsString('LUNCH', $html);
        $this->assertStringContainsString('CLASSROOM CLEAN-UP (CC)', $html);
    }

    public function test_chair_defaults_to_their_department_but_can_select_the_school_wide_view(): void
    {
        $this->seed();
        $chair = User::where('email', 'chair.fil@jhs.test')->firstOrFail();
        $admin = User::where('email', 'admin@jhs.test')->firstOrFail();
        $year = (string) SystemConstant::get('current_school_year', '2026-2027');
        $section = Section::where('school_year', $year)->where('grade_level', 'G7')->firstOrFail();
        $filipino = Department::where('code', 'FIL')->firstOrFail();
        $english = Department::where('code', 'ENG')->firstOrFail();
        $filipinoTeacher = Teacher::create(['full_name' => 'Filipino Schedule Teacher', 'department_id' => $filipino->id, 'source' => 'test']);
        $englishTeacher = Teacher::create(['full_name' => 'English Schedule Teacher', 'department_id' => $english->id, 'source' => 'test']);
        $approved = TimetableVersion::create([
            'school_year' => $year,
            'quarter' => 1,
            'version' => 1,
            'status' => TimetableStatus::Approved,
            'created_by_user_id' => $admin->id,
            'approved_by_user_id' => $admin->id,
            'generation_seed' => 7,
            'approved_at' => now(),
        ]);
        $approved->entries()->createMany([
            ['section_id' => $section->id, 'department_id' => $filipino->id, 'teacher_id' => $filipinoTeacher->id, 'day' => 1, 'period' => 1],
            ['section_id' => $section->id, 'department_id' => $english->id, 'teacher_id' => $englishTeacher->id, 'day' => 1, 'period' => 2],
        ]);

        $this->actingAs($chair)->get(route('chair.schedules.show', $approved))
            ->assertOk()->assertSee('Filipino')->assertDontSee('English');
        $this->actingAs($chair)->get(route('chair.schedules.show', $approved).'?scope=school')
            ->assertOk()->assertSee('Filipino')->assertSee('English');
    }

    public function test_presentation_orders_grade_seven_before_grade_ten_regardless_of_insert_order(): void
    {
        $year = '2099-2100';
        $admin = User::factory()->create();
        Section::create(['school_year' => $year, 'grade_level' => 'G10', 'name' => 'Xavier']);
        Section::create(['school_year' => $year, 'grade_level' => 'G7', 'name' => 'Arrowsmith']);
        $timetable = TimetableVersion::create([
            'school_year' => $year,
            'quarter' => 1,
            'version' => 1,
            'status' => TimetableStatus::Draft,
            'created_by_user_id' => $admin->id,
            'generation_seed' => 7,
        ]);

        $data = app(TimetablePresentationService::class)->prepare(
            $timetable,
            Request::create('/admin/schedules/'.$timetable->id, 'GET', ['grade' => 'all', 'day' => 1]),
        );

        $this->assertSame(['G7', 'G10'], array_keys($data['gradeGroups']));
        $this->assertSame(['G7', 'G10'], $data['sectionOptions']->map(fn (Section $section) => $section->grade_level->value)->all());
    }

    private function draftWithSingleEnglishEntry(): array
    {
        $this->seed();
        $admin = User::where('email', 'admin@jhs.test')->firstOrFail();
        $schoolYear = (string) SystemConstant::get('current_school_year', '2026-2027');
        $department = Department::where('code', 'ENG')->firstOrFail();
        $section = Section::where('school_year', $schoolYear)->where('grade_level', 'G7')->orderBy('name')->firstOrFail();
        $teacher = Teacher::create([
            'full_name' => 'Schedule Test Teacher',
            'department_id' => $department->id,
            'source' => 'test',
        ]);
        $draft = TimetableVersion::create([
            'school_year' => $schoolYear,
            'quarter' => 1,
            'version' => 1,
            'status' => TimetableStatus::Draft,
            'created_by_user_id' => $admin->id,
            'generation_seed' => 7,
        ]);
        $draft->entries()->create([
            'section_id' => $section->id,
            'department_id' => $department->id,
            'teacher_id' => $teacher->id,
            'day' => 1,
            'period' => 1,
        ]);

        return [$admin, $draft, $section];
    }
}
