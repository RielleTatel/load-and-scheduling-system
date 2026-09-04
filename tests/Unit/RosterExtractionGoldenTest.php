<?php

namespace Tests\Unit;

use App\Models\Section;
use App\Models\SystemConstant;
use App\Services\Roster\RosterExtractionService;
use Database\Seeders\SectionSeeder;
use Database\Seeders\SystemConstantSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The seeded 36 sections were hand-transcribed from this same PDF and verified.
 * Extraction must reproduce them exactly — every room, moderator and partner —
 * or the extractor is wrong, not the seeder.
 */
class RosterExtractionGoldenTest extends TestCase
{
    use RefreshDatabase;

    public function test_extraction_matches_the_verified_roster(): void
    {
        $this->seed(SystemConstantSeeder::class);
        $this->seed(SectionSeeder::class);

        $rows = app(RosterExtractionService::class)->extract(base_path('tests/Fixtures/class-moderators.pdf'));
        $seeded = Section::where('school_year', SystemConstant::get('current_school_year'))->get();

        $this->assertCount($seeded->count(), $rows);

        foreach ($rows as $row) {
            $match = $seeded->first(fn (Section $s) => $s->full_name === $row['full_name']);

            $this->assertNotNull($match, "extracted \"{$row['full_name']}\" is not in the verified roster");
            $this->assertSame($match->grade_level->value, $row['grade_level'], "grade for {$row['full_name']}");
            $this->assertSame($match->room, $row['room'], "room for {$row['full_name']}");
            $this->assertSame($match->moderator_name, $row['moderator_name'], "moderator for {$row['full_name']}");
            $this->assertSame($match->teacher_partner_name, $row['teacher_partner_name'], "partner for {$row['full_name']}");
            $this->assertSame((bool) $match->is_magis, $row['is_magis'], "magis flag for {$row['full_name']}");
        }
    }
}
