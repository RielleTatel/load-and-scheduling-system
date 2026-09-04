<?php

namespace Tests\Feature\Admin;

use App\Models\RosterExtractionRow;
use App\Models\RosterImport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RosterReviewTest extends TestCase
{
    use RefreshDatabase;

    private function stageRow(array $overrides = []): RosterExtractionRow
    {
        $import = RosterImport::create([
            'school_year' => '2026-2027',
            'file_path' => 'rosters/test.pdf',
            'original_filename' => 'test.pdf',
            'extraction_status' => 'extracted',
        ]);

        return $import->rows()->create([
            'row_json' => array_merge([
                'grade_level' => 'G7', 'full_name' => 'Saint John de Britto', 'name' => 'de Britto',
                'room' => '305', 'is_magis' => false,
                'moderator_name' => 'Francheska June Naomi A. Francisco',
                'teacher_partner_name' => 'Chantie A. Chiong',
                'flagged' => true,
                'flags' => ['name' => '"Saint John de Britto" contains "de" — confirm the short name.'],
            ], $overrides),
            'row_status' => \App\Enums\ExtractionRowStatus::Flagged,
        ]);
    }

    public function test_review_screen_shows_the_flag_reason(): void
    {
        $this->seed();
        $this->stageRow();

        $this->actingAs(User::where('email', 'admin@jhs.test')->first())
            ->get(route('admin.roster.review'))
            ->assertOk()
            ->assertSee('confirm the short name', false);
    }

    public function test_admin_corrects_the_short_name(): void
    {
        $this->seed();
        $row = $this->stageRow();

        $this->actingAs(User::where('email', 'admin@jhs.test')->first())
            ->patch(route('admin.roster.rows.update', $row), [
                'grade_level' => 'G7',
                'full_name' => 'Saint John de Britto',
                'name' => 'De Britto',
                'room' => '305',
                'moderator_name' => 'Francheska June Naomi A. Francisco',
                'teacher_partner_name' => 'Chantie A. Chiong',
            ])
            ->assertRedirect();

        $this->assertSame('De Britto', $row->fresh()->row_json['name']);
        $this->assertSame(\App\Enums\ExtractionRowStatus::Extracted, $row->fresh()->row_status);
    }
}
