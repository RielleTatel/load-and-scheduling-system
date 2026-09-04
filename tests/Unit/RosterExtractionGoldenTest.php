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

    /**
     * The plantillas' convention is "drop the particle, use the surname" —
     * Anchieta, Brebeuf and Colombiere are all written bare across the seven
     * sheets. Two sections are genuine exceptions: every sheet writes
     * "De Britto"/"De Brito" and none writes bare "Britto"; and the G8 Magis
     * class is written four ways, so its canonical keeps the full phrase for
     * "ignatius" and "loyola" to alias onto.
     *
     * Proposals must therefore land on the verified short name for all 36.
     */
    public function test_proposes_the_verified_short_name_for_every_section(): void
    {
        $this->seed(SystemConstantSeeder::class);
        $this->seed(SectionSeeder::class);

        $rows = app(RosterExtractionService::class)->extract(base_path('tests/Fixtures/class-moderators.pdf'));
        $seeded = Section::where('school_year', SystemConstant::get('current_school_year'))->get();

        foreach ($rows as $row) {
            $match = $seeded->first(fn (Section $s) => $s->full_name === $row['full_name']);

            $this->assertSame($match->name, $row['name'], "short name for {$row['full_name']}");
        }
    }

    public function test_no_section_is_flagged_when_every_proposal_is_known(): void
    {
        $this->seed(SystemConstantSeeder::class);
        $this->seed(SectionSeeder::class);

        $flagged = array_filter(
            app(RosterExtractionService::class)->extract(base_path('tests/Fixtures/class-moderators.pdf')),
            fn ($row) => $row['flagged'],
        );

        $this->assertSame([], array_column($flagged, 'full_name'));
    }
}
