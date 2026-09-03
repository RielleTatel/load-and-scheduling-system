<?php

namespace Tests\Feature;

use App\Models\ClassModeratorAssignment;
use App\Models\Section;
use App\Models\SystemConstant;
use App\Models\Teacher;
use Database\Seeders\SystemConstantSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillClassModeratorHoursTest extends TestCase
{
    use RefreshDatabase;

    public function test_moves_stale_assignments_to_the_current_constant(): void
    {
        $this->seed(SystemConstantSeeder::class);
        SystemConstant::where('key', 'class_moderator_hours')->update(['value' => '4']);

        $stale = ClassModeratorAssignment::create([
            'teacher_id' => Teacher::factory()->create()->id,
            'section_id' => Section::factory()->create()->id,
            'school_year' => '2026-2027',
            'hours' => 3,
        ]);
        $current = ClassModeratorAssignment::create([
            'teacher_id' => Teacher::factory()->create()->id,
            'section_id' => Section::factory()->create()->id,
            'school_year' => '2026-2027',
            'hours' => 4,
        ]);

        $this->artisan('plantilla:backfill-cm-hours')->assertSuccessful();

        $this->assertEquals(4.0, $stale->fresh()->hours);
        $this->assertEquals(4.0, $current->fresh()->hours);
    }

    public function test_dry_run_reports_without_writing(): void
    {
        $this->seed(SystemConstantSeeder::class);
        SystemConstant::where('key', 'class_moderator_hours')->update(['value' => '4']);

        $stale = ClassModeratorAssignment::create([
            'teacher_id' => Teacher::factory()->create()->id,
            'section_id' => Section::factory()->create()->id,
            'school_year' => '2026-2027',
            'hours' => 3,
        ]);

        $this->artisan('plantilla:backfill-cm-hours', ['--dry-run' => true])->assertSuccessful();

        $this->assertEquals(3.0, $stale->fresh()->hours);
    }

    public function test_no_op_when_nothing_is_stale(): void
    {
        $this->seed(SystemConstantSeeder::class);
        SystemConstant::where('key', 'class_moderator_hours')->update(['value' => '4']);

        ClassModeratorAssignment::create([
            'teacher_id' => Teacher::factory()->create()->id,
            'section_id' => Section::factory()->create()->id,
            'school_year' => '2026-2027',
            'hours' => 4,
        ]);

        $this->artisan('plantilla:backfill-cm-hours')
            ->expectsOutputToContain('Nothing to backfill')
            ->assertSuccessful();
    }
}
