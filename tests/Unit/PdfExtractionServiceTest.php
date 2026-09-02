<?php

namespace Tests\Unit;

use App\Services\Plantilla\ExtractionFailedException;
use App\Services\Plantilla\PdfExtractionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PdfExtractionServiceTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<int, array<string, mixed>> */
    private function extract(string $sheet): array
    {
        $this->seed(\Database\Seeders\SectionSeeder::class);

        return app(PdfExtractionService::class)->extract(base_path("tests/Fixtures/{$sheet}-plantilla.pdf"));
    }

    private function rowFor(array $rows, string $surname): array
    {
        foreach ($rows as $row) {
            if ($row['teacher_name'] && str_contains(strtolower($row['teacher_name']), strtolower($surname))) {
                return $row;
            }
        }
        $this->fail("no extracted row for {$surname}");
    }

    public function test_extracts_rows_from_real_filipino_plantilla(): void
    {
        $rows = $this->extract('filipino');

        $this->assertGreaterThanOrEqual(5, count($rows));
        $names = implode('|', array_column($rows, 'teacher_name'));
        $this->assertStringContainsString('Bilbar', $names);
        $this->assertStringContainsString('Comeros', $names);
    }

    public function test_extracts_employment_status(): void
    {
        $statuses = array_filter(array_column($this->extract('filipino'), 'employment_status'));

        $this->assertNotEmpty($statuses);
        $this->assertStringContainsStringIgnoringCase('permanent', implode('|', $statuses));
    }

    public function test_extracts_teaching_sections_with_recovered_grades(): void
    {
        // Delos Reyes teaches four Grade 7 sections (doc section 3 of the blocker).
        $row = $this->rowFor($this->extract('filipino'), 'Delos Reyes');

        $this->assertStringContainsString('Arrowsmith', $row['sections']);
        $this->assertStringContainsString('Jogues', $row['sections']);
        $this->assertStringContainsString('Campion', $row['sections']);
        $this->assertStringContainsString('Rubio', $row['sections']);
        $this->assertStringStartsWith('G7:', $row['sections']);
    }

    public function test_extracts_class_moderator(): void
    {
        $row = $this->rowFor($this->extract('filipino'), 'Delos Reyes');

        $this->assertStringContainsString('Rubio', (string) $row['cm']);
        $this->assertStringContainsString('G7', (string) $row['cm']);
    }

    public function test_extracts_service_load(): void
    {
        $row = $this->rowFor($this->extract('filipino'), 'Delos Reyes');

        $this->assertSame('3', (string) $row['service_load']);
    }

    public function test_extracts_other_assignment(): void
    {
        $row = $this->rowFor($this->extract('filipino'), 'Bilbar');

        $this->assertStringContainsStringIgnoringCase('Department Chair', (string) $row['other_assignment']);
    }

    public function test_bare_ignatius_is_placed_in_grade_8(): void
    {
        // The old extraction filed Bilbar's "Ignatius" under G7. It is the
        // G8 Magis section, Saint Ignatius of Loyola.
        $row = $this->rowFor($this->extract('filipino'), 'Bilbar');

        $this->assertStringContainsString('G8: Ignatius of Loyola', $row['sections']);
    }

    public function test_cle_sections_land_in_their_true_grades(): void
    {
        $rows = $this->extract('cle');

        // Every one of Guinabo's five sections is Grade 10.
        $this->assertStringStartsWith('G10:', $this->rowFor($rows, 'abo')['sections']);
        // Natividad's four are all Grade 8.
        $this->assertStringStartsWith('G8:', $this->rowFor($rows, 'Natividad')['sections']);
    }

    public function test_social_studies_row_without_status_parenthetical_still_gets_a_name(): void
    {
        // Omega's row has no "(Permanent)" marker - the old parser swallowed the
        // whole row into teacher_name.
        $row = $this->rowFor($this->extract('social-studies'), 'Omega');

        $this->assertSame('Rodelyn Omega', $row['teacher_name']);
    }

    public function test_science_magis_row_resolves_the_honors_section(): void
    {
        $row = $this->rowFor($this->extract('science'), 'Magasa');

        $this->assertStringContainsString('Ignatius of Loyola', (string) $row['hc']);
        $this->assertStringContainsString('G8', (string) $row['hc']);
    }

    public function test_tle_embedded_grades_agree_with_the_roster(): void
    {
        // TLE states grade inline ("10Colombiere"). Nothing should conflict.
        foreach ($this->extract('tle') as $row) {
            $this->assertArrayNotHasKey('sections', $row['flags'] ?? [],
                "TLE row {$row['teacher_name']} unexpectedly flagged: " . json_encode($row['flags'] ?? []));
        }
    }

    public function test_miki_is_flagged_for_the_registrar(): void
    {
        $flags = array_merge(...array_map(fn ($r) => array_values($r['flags'] ?? []), $this->extract('cle')));

        $this->assertStringContainsString('Miki', implode(' | ', $flags));
    }

    public function test_service_load_is_not_confused_by_the_status_cell(): void
    {
        // Bilbar's Service Load is 3. The status parenthetical used to leak into
        // the trailing cells and drag the wrong number out.
        $row = $this->rowFor($this->extract('filipino'), 'Bilbar');

        $this->assertSame('3', (string) $row['service_load']);
    }

    public function test_other_assignment_excludes_the_employment_status(): void
    {
        $row = $this->rowFor($this->extract('filipino'), 'Bilbar');

        $this->assertStringNotContainsStringIgnoringCase('permanent', (string) $row['other_assignment']);
    }

    public function test_bare_magis_marker_annotates_the_preceding_section(): void
    {
        // MAPEH writes "Ignatius / (Magis) / Ogilvie": the marker qualifies the
        // section before it, and must not swallow the section after it.
        $row = $this->rowFor($this->extract('mapeh'), 'Vendola');

        $this->assertStringNotContainsString('Ogilvie', (string) $row['hc']);
    }

    public function test_every_service_load_is_a_plausible_value(): void
    {
        // Service Load is 3 across almost every row, occasionally waived ("-").
        // Anything above 8 means a neighbouring column was misread.
        foreach (['filipino', 'cle', 'science', 'mathematics', 'social-studies'] as $sheet) {
            foreach ($this->extract($sheet) as $row) {
                $value = (string) $row['service_load'];
                if ($value === '' || $value === '-') {
                    continue;
                }
                $this->assertLessThanOrEqual(8, (float) $value,
                    "{$sheet}/{$row['teacher_name']} got Service Load {$value}");
            }
        }
    }

    public function test_section_name_split_across_lines_is_rejoined(): void
    {
        // Science prints "Ignatius of" / "Loyola" on two lines; Magasa teaches it.
        $row = $this->rowFor($this->extract('science'), 'MAGASA');

        $this->assertStringContainsString('Ignatius of Loyola', (string) $row['sections']);
    }

    public function test_signature_block_does_not_leak_into_rows(): void
    {
        foreach (['science', 'tle', 'mathematics', 'social-studies'] as $sheet) {
            foreach ($this->extract($sheet) as $row) {
                $this->assertDoesNotMatchRegularExpression('/^(?:MRS?|BB|FR)\./i', (string) $row['other_assignment'],
                    "{$sheet}/{$row['teacher_name']} picked up a signatory");
            }
        }
    }

    public function test_club_role_keeps_its_moderator_word(): void
    {
        // "16 3 Punlaan" / "Moderator" wraps across two lines. Dropping the
        // second line leaves "Punlaan", which matches no role on import.
        $row = $this->rowFor($this->extract('filipino'), 'Comeros');

        $this->assertSame('Punlaan Moderator', (string) $row['other_assignment']);
    }

    public function test_signature_block_does_not_leak_into_the_filipino_last_row(): void
    {
        // The signatories use mixed-case honorifics ("Bb. LEAH ANGELIC C. BILBAR")
        // and title lines ("Asst. Principal for Academics").
        $other = (string) $this->rowFor($this->extract('filipino'), 'Ajijun')['other_assignment'];

        $this->assertStringNotContainsString('BILBAR', $other);
        $this->assertStringNotContainsString('Principal', $other);
        $this->assertStringNotContainsString('SUPILANAS', $other);
    }

    public function test_none_is_not_an_other_assignment(): void
    {
        // CLE writes "None" in the Other Assignment cell. That means no role,
        // not a role named "None".
        foreach ($this->extract('cle') as $row) {
            $this->assertNotSame('none', strtolower((string) $row['other_assignment']));
        }
    }

    public function test_moderating_class_count_does_not_leak_into_other_assignment(): void
    {
        // Math writes "1 moderating" / "class" as a count with no section named.
        foreach ($this->extract('mathematics') as $row) {
            $this->assertStringNotContainsStringIgnoringCase('moderating', (string) $row['other_assignment'],
                "row {$row['teacher_name']}");
        }
    }

    public function test_department_prefixed_role_keeps_its_prefix(): void
    {
        // TLE writes "TLE" / "Coordinator" across two lines. Only the
        // parenthesised "(MAPEH)" form is a department tag to be dropped.
        $row = $this->rowFor($this->extract('tle'), 'Escabosa');

        $this->assertSame('TLE Coordinator', (string) $row['other_assignment']);
    }

    public function test_count_only_moderator_cell_does_not_claim_a_section(): void
    {
        // Math writes "1 moderating class" and never names the section. The next
        // section on the row is a teaching section, not the moderated one -
        // reading it as the moderator made Layon moderate Loyola, which the
        // registrar assigns to Magasa.
        $row = $this->rowFor($this->extract('mathematics'), 'Layon');

        $this->assertNull($row['cm']);
        $this->assertStringContainsString('Lewis', (string) $row['sections']);
    }

    public function test_roman_numeral_status_is_extracted(): void
    {
        $row = $this->rowFor($this->extract('mapeh'), 'Wahid');

        $this->assertStringContainsStringIgnoringCase('probationary', (string) $row['employment_status']);
    }

    public function test_section_beginning_with_de_is_not_folded_into_the_name(): void
    {
        // "De Brtio," is a section, not a continuation of the teacher's name.
        // Only a lowercase preposition ("of Loyola") continues a wrapped name.
        $row = $this->rowFor($this->extract('social-studies'), 'Peña');

        $this->assertSame('Roshielle Sheera Peña', $row['teacher_name']);
    }

    /** Every sheet: the extractor never emits a section outside the 36-name roster. */
    public function test_no_sheet_invents_a_section(): void
    {
        $this->seed(\Database\Seeders\SectionSeeder::class);
        $valid = \App\Models\Section::pluck('name')->all();

        foreach (['filipino', 'cle', 'tle', 'science', 'mathematics', 'mapeh', 'social-studies'] as $sheet) {
            foreach ($this->extract($sheet) as $row) {
                foreach (['sections', 'cm', 'hc'] as $field) {
                    foreach ($this->namesIn((string) ($row[$field] ?? '')) as $name) {
                        $this->assertContains($name, $valid, "{$sheet}/{$field} produced unknown section \"{$name}\"");
                    }
                }
            }
        }
    }

    /** @return array<int,string> */
    private function namesIn(string $value): array
    {
        $out = [];
        foreach (explode(';', $value) as $segment) {
            if (! preg_match('/G(?:7|8|9|10)\s*:\s*(.+)/', trim($segment), $m)) {
                continue;
            }
            foreach (explode(',', $m[1]) as $n) {
                if (($n = trim($n)) !== '') {
                    $out[] = $n;
                }
            }
        }

        return $out;
    }

    public function test_textless_pdf_throws(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($path, '%PDF-1.4 empty');

        $this->expectException(ExtractionFailedException::class);

        try {
            app(PdfExtractionService::class)->extract($path);
        } finally {
            @unlink($path);
        }
    }
}
