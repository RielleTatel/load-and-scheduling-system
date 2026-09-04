<?php

namespace Tests\Unit;

use App\Services\Roster\RosterExtractionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RosterExtractionServiceTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<int, array<string, mixed>> */
    private function extract(): array
    {
        return app(RosterExtractionService::class)->extract(base_path('tests/Fixtures/class-moderators.pdf'));
    }

    private function rowFor(array $rows, string $fullNameFragment): array
    {
        foreach ($rows as $row) {
            if (str_contains(strtolower($row['full_name']), strtolower($fullNameFragment))) {
                return $row;
            }
        }
        $this->fail("no extracted row for {$fullNameFragment}");
    }

    public function test_extracts_thirty_six_sections(): void
    {
        $this->assertCount(36, $this->extract());
    }

    public function test_each_grade_yields_nine_sections(): void
    {
        $rows = $this->extract();

        foreach (['G7', 'G8', 'G9', 'G10'] as $grade) {
            $count = count(array_filter($rows, fn ($r) => $r['grade_level'] === $grade));
            $this->assertSame(9, $count, "{$grade} should yield 9 sections");
        }
    }

    public function test_grade_comes_from_the_block_label_not_document_order(): void
    {
        // The document prints GRADE 8 before GRADE 7; order must never be assumed.
        $this->assertSame('G7', $this->rowFor($this->extract(), 'Arrowsmith')['grade_level']);
        $this->assertSame('G8', $this->rowFor($this->extract(), 'Borgia')['grade_level']);
    }

    public function test_extracts_room_moderator_and_partner(): void
    {
        $row = $this->rowFor($this->extract(), 'Arrowsmith');

        $this->assertSame('206', $row['room']);
        $this->assertSame('Frizie B. Dealagdon', $row['moderator_name']);
        $this->assertSame('Angel Joy Suzette H. Lauresta', $row['teacher_partner_name']);
    }

    public function test_strips_the_gll_suffix_from_a_partner_name(): void
    {
        // The sheet writes "Mrs. Hazel G. Sumicad (GLL)".
        $this->assertSame('Hazel G. Sumicad', $this->rowFor($this->extract(), 'Regis')['teacher_partner_name']);
    }

    public function test_strips_the_honorific_but_keeps_the_sj_suffix(): void
    {
        // "Br. James Ryan C. Seneriches, SJ" — the order suffix is part of the
        // name and the verified roster stores it; only the honorific goes.
        $this->assertSame('James Ryan C. Seneriches, SJ', $this->rowFor($this->extract(), 'Colombiere')['moderator_name']);
    }

    public function test_rejoins_a_name_wrapped_across_lines(): void
    {
        // "Ms. Ma. Julianna Yzabel G." / "Ragay"
        $this->assertSame('Ma. Julianna Yzabel G. Ragay', $this->rowFor($this->extract(), 'Ogilvie')['teacher_partner_name']);
    }

    public function test_flags_exactly_three_magis_sections(): void
    {
        $magis = array_filter($this->extract(), fn ($r) => $r['is_magis']);

        $this->assertCount(3, $magis);
    }

    public function test_textless_pdf_throws(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($path, '%PDF-1.4 empty');

        $this->expectException(\App\Services\Plantilla\ExtractionFailedException::class);

        try {
            app(RosterExtractionService::class)->extract($path);
        } finally {
            @unlink($path);
        }
    }
}
