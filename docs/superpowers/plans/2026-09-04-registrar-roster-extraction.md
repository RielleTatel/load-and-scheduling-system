# Registrar Roster Extraction Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a System Admin upload the registrar's "List of Class Moderators" PDF and have it populate that school year's sections, moderators and teacher-partners — replacing the hand-edited `SectionSeeder` as the way roster data enters the system.

**Architecture:** Mirrors the existing plantilla pipeline exactly: PDF → extraction service → JSON staging table → human review screen → transactional commit. Nothing auto-commits. Because the roster is per-year data living on a timeless `sections` table, the table is first year-scoped, and every section lookup made year-aware — otherwise a second year's import silently corrupts `SectionResolver`.

**Tech Stack:** Laravel 11, Blade + Alpine + Tailwind, MySQL (SQLite in tests), `smalot/pdfparser`, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-09-04-registrar-roster-extraction-design.md`

## Global Constraints

- **Never invent a section.** Anything unresolvable is flagged for the Admin, never guessed. (Spec §5)
- **Never auto-commit extracted data.** Human review is mandatory, per SRS FR-5.
- **Short names are never derived silently.** The extractor proposes; the Admin confirms. (Spec §2)
- **All hour/rate constants come from `system_constants`**, never hard-coded.
- **Every write to roster data is audit-logged** via `AuditLogService::log()`, per SRS §6.3.
- **The active school year is always** `SystemConstant::get('current_school_year')` — currently `'2026-2027'`. Importing a roster must NOT change it. (Spec §7.1)
- Staging rows store fields as **JSON keys in `row_json`**, not columns — new fields need no migration.
- Tests run on in-memory SQLite. Grade ordering must never rely on raw SQL sorting (`G10` sorts before `G7` alphabetically).

---

### Task 1: Year-scope the sections table

Adds `school_year` to `sections` and makes the unique key year-aware. No behaviour changes yet — existing rows are backfilled to the active year so everything keeps working.

**Files:**
- Create: `database/migrations/2026_09_04_000001_add_school_year_to_sections_table.php`
- Modify: `app/Models/Section.php:13` (add `school_year` to `$fillable`)
- Test: `tests/Feature/SectionYearScopeTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `sections.school_year` (string, 9 chars); `Section::$fillable` includes `'school_year'`; unique key `(school_year, grade_level, name)`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/SectionYearScopeTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Section;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SectionYearScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_section_name_may_exist_in_two_school_years(): void
    {
        Section::create(['school_year' => '2026-2027', 'grade_level' => 'G7', 'name' => 'Arrowsmith']);
        Section::create(['school_year' => '2027-2028', 'grade_level' => 'G7', 'name' => 'Arrowsmith']);

        $this->assertSame(2, Section::where('name', 'Arrowsmith')->count());
    }

    public function test_same_section_name_collides_within_one_year(): void
    {
        Section::create(['school_year' => '2026-2027', 'grade_level' => 'G7', 'name' => 'Arrowsmith']);

        $this->expectException(\Illuminate\Database\QueryException::class);

        Section::create(['school_year' => '2026-2027', 'grade_level' => 'G7', 'name' => 'Arrowsmith']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=SectionYearScopeTest`
Expected: FAIL — `school_year` column does not exist.

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_09_04_000001_add_school_year_to_sections_table.php`:

```php
<?php

use App\Models\SystemConstant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The registrar re-issues the roster every year: rooms, moderators and
     * teacher-partners all change. Sections were modelled as timeless, so a
     * second import would overwrite the prior year in place and orphan the
     * assignments pointing at those section rows.
     */
    public function up(): void
    {
        Schema::table('sections', function (Blueprint $table) {
            $table->string('school_year', 9)->default('2026-2027')->after('id');
        });

        // Existing rows are the 2026-2027 roster.
        DB::table('sections')->update(['school_year' => SystemConstant::get('current_school_year', '2026-2027')]);

        Schema::table('sections', function (Blueprint $table) {
            $table->dropUnique(['grade_level', 'name']);
            $table->unique(['school_year', 'grade_level', 'name']);
        });
    }

    public function down(): void
    {
        Schema::table('sections', function (Blueprint $table) {
            $table->dropUnique(['school_year', 'grade_level', 'name']);
            $table->unique(['grade_level', 'name']);
            $table->dropColumn('school_year');
        });
    }
};
```

- [ ] **Step 4: Add `school_year` to the model's fillable**

In `app/Models/Section.php`, change line 13 from:

```php
    protected $fillable = ['grade_level', 'name', 'full_name', 'room', 'is_magis', 'moderator_name', 'teacher_partner_name'];
```

to:

```php
    protected $fillable = ['school_year', 'grade_level', 'name', 'full_name', 'room', 'is_magis', 'moderator_name', 'teacher_partner_name'];
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --filter=SectionYearScopeTest`
Expected: PASS (2 tests)

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_09_04_000001_add_school_year_to_sections_table.php app/Models/Section.php tests/Feature/SectionYearScopeTest.php
git commit -m "feat: year-scope the sections table"
```

---

### Task 2: Make every section lookup year-aware

Task 1 makes two years *possible*; this task stops them colliding. `SectionResolver::boot()` indexes sections by name alone — with two years loaded, `Arrowsmith` resolves to whichever row came last, so a 2027 plantilla could attach load to a 2026 section.

**Files:**
- Modify: `app/Services/Plantilla/SectionResolver.php:158`
- Modify: `app/Services/Plantilla/PlantillaReviewService.php:176`
- Modify: `database/seeders/SectionSeeder.php:105`
- Modify: `database/seeders/RegistrarStaffSeeder.php:27`
- Test: `tests/Unit/SectionResolverYearScopeTest.php`

**Interfaces:**
- Consumes: `sections.school_year` from Task 1.
- Produces: all four call sites filter on `SystemConstant::get('current_school_year')`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/SectionResolverYearScopeTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Models\Section;
use App\Services\Plantilla\SectionResolver;
use Database\Seeders\SystemConstantSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SectionResolverYearScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolves_to_the_active_years_section(): void
    {
        $this->seed(SystemConstantSeeder::class); // current_school_year = 2026-2027

        $active = Section::create(['school_year' => '2026-2027', 'grade_level' => 'G7', 'name' => 'Arrowsmith']);
        // A later year exists and would win a name-keyed index built over all rows.
        Section::create(['school_year' => '2027-2028', 'grade_level' => 'G7', 'name' => 'Arrowsmith']);

        $resolution = app(SectionResolver::class)->resolve('Arrowsmith');

        $this->assertTrue($resolution->isResolved());
        $this->assertSame($active->id, $resolution->section->id);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=SectionResolverYearScopeTest`
Expected: FAIL — resolves to the 2027-2028 row's id, because `boot()` loads every year and the later row overwrites the index entry.

- [ ] **Step 3: Scope the resolver**

In `app/Services/Plantilla/SectionResolver.php`, add the import at the top of the file alongside the existing `use` statements:

```php
use App\Models\SystemConstant;
```

Then change `boot()` (line ~158) from:

```php
        $this->sections = Section::all();
```

to:

```php
        // Section names are unique within a year, not across years: the same
        // name recurs every SY. Indexing every year would let a later year's
        // row win the lookup and attach load to the wrong section.
        $this->sections = Section::where('school_year', SystemConstant::get('current_school_year'))->get();
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --filter=SectionResolverYearScopeTest`
Expected: PASS

- [ ] **Step 5: Scope the moderator lookup**

In `app/Services/Plantilla/PlantillaReviewService.php`, change line ~176 from:

```php
        $rostered = Section::whereNotNull('moderator_name')->get()
```

to:

```php
        $rostered = Section::where('school_year', $schoolYear)->whereNotNull('moderator_name')->get()
```

Then change the method signature so `$schoolYear` is available — from:

```php
    private function importModerator(Teacher $teacher, string $schoolYear, ?string $cm, string $name): array
```

it already receives `$schoolYear`; no signature change is needed. Verify by reading the method header.

- [ ] **Step 6: Scope the two seeders**

In `database/seeders/SectionSeeder.php`, the stale-delete at line ~105 currently removes any section not in `$keep`, which would delete every other year. Change:

```php
        $stale = Section::whereNotIn('id', $keep)->get();
```

to:

```php
        $stale = Section::where('school_year', $schoolYear)->whereNotIn('id', $keep)->get();
```

Add `$schoolYear = SystemConstant::get('current_school_year', '2026-2027');` near the top of `run()`, add `use App\Models\SystemConstant;` to the imports, and pass `'school_year' => $schoolYear` in the `Section::updateOrCreate` attribute array so seeded rows carry their year.

In `database/seeders/RegistrarStaffSeeder.php`, change line ~27 from:

```php
        $names = Section::query()
```

to:

```php
        $names = Section::where('school_year', SystemConstant::get('current_school_year', '2026-2027'))
```

and add `use App\Models\SystemConstant;` to its imports.

- [ ] **Step 7: Update year-blind count assertions**

`SeedTest` and `ReferenceDataTest` assert absolute section counts, which now need a year. In `tests/Feature/SeedTest.php`, change the three bare counts (`Section::count()` at lines ~27 and ~82, and the `whereNotNull` pair at ~109-110) to scope on the active year, e.g.:

```php
        $sy = SystemConstant::get('current_school_year');
        $this->assertSame(36, Section::where('school_year', $sy)->count());
```

Apply the same scoping in `tests/Feature/Admin/ReferenceDataTest.php:72`.

- [ ] **Step 8: Run the full suite**

Run: `php artisan test`
Expected: PASS — all 180 existing tests plus the new ones.

- [ ] **Step 9: Commit**

```bash
git add app/Services/Plantilla/SectionResolver.php app/Services/Plantilla/PlantillaReviewService.php database/seeders/SectionSeeder.php database/seeders/RegistrarStaffSeeder.php tests/Unit/SectionResolverYearScopeTest.php tests/Feature/SeedTest.php tests/Feature/Admin/ReferenceDataTest.php
git commit -m "fix: scope every section lookup to the active school year"
```

---

### Task 3: RosterExtractionService — grade blocks and section rows

The parser. Anchors are strong here (spec §1): `GRADE N` labels each block, sections begin `Saint`/`Blessed`, people begin an honorific, and a bare 3-digit room terminates every row.

**Files:**
- Create: `app/Services/Roster/RosterExtractionService.php`
- Create: `tests/Fixtures/class-moderators.pdf` (copy of the real document)
- Test: `tests/Unit/RosterExtractionServiceTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: `RosterExtractionService::extract(string $absolutePdfPath): array` — a list of rows shaped:
  `array{grade_level:string, full_name:string, name:?string, room:?string, is_magis:bool, moderator_name:?string, teacher_partner_name:?string, flagged:bool, flags:array<string,string>}`.
  Throws `App\Services\Plantilla\ExtractionFailedException` on an unreadable/textless PDF.

- [ ] **Step 1: Copy the source PDF in as a test fixture**

```bash
cp "docs/List of Class Mods and Teacher-Partners 2026.pdf" tests/Fixtures/class-moderators.pdf
```

- [ ] **Step 2: Write the failing test**

Create `tests/Unit/RosterExtractionServiceTest.php`:

```php
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

    public function test_strips_honorifics_and_the_sj_suffix(): void
    {
        // "Br. James Ryan C. Seneriches, SJ"
        $this->assertSame('James Ryan C. Seneriches', $this->rowFor($this->extract(), 'Colombiere')['moderator_name']);
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
```

- [ ] **Step 3: Run test to verify it fails**

Run: `php artisan test --filter=RosterExtractionServiceTest`
Expected: FAIL — class `App\Services\Roster\RosterExtractionService` not found.

- [ ] **Step 4: Implement the service**

Create `app/Services/Roster/RosterExtractionService.php`:

```php
<?php

namespace App\Services\Roster;

use App\Services\Plantilla\ExtractionFailedException;
use Smalot\PdfParser\Parser;
use Throwable;

/**
 * Extracts the registrar's "List of Class Moderators" into one row per section.
 *
 * The document is a clean 4-column table (Section | Moderator | Teacher-Partner
 * | Room), but PDF text extraction flattens its geometry: grade blocks come out
 * of order, the Grade Level Leader lines collapse together, and long names wrap
 * mid-cell. So this parses on markers, never on reading order — the literal
 * "GRADE n" label carries the grade, a section begins "Saint"/"Blessed", people
 * begin with an honorific, and a bare 3-digit room terminates each row.
 *
 * Grade Level Leader lines are ignored: the schema models GLL as an
 * OtherAssignmentRole, not a section field.
 *
 * Short names are never derived here — that call is editorial (the registrar's
 * "Saint John de Britto" is "De Britto" but "Saint Jose de Anchieta" is
 * "Anchieta"), so a proposal is offered and the Admin confirms it on review.
 */
class RosterExtractionService
{
    private const HONORIFICS = '(?:Ms|Mr|Mrs|Bb|Gng|Br|Sch|Fr|Rev|Dr)\.?';

    /**
     * @return array<int, array{grade_level:string, full_name:string, name:?string, room:?string, is_magis:bool, moderator_name:?string, teacher_partner_name:?string, flagged:bool, flags:array<string,string>}>
     */
    public function extract(string $absolutePdfPath): array
    {
        try {
            $text = (new Parser())->parseFile($absolutePdfPath)->getText();
        } catch (Throwable $e) {
            throw new ExtractionFailedException('Unreadable PDF: ' . $e->getMessage(), previous: $e);
        }

        if (trim($text) === '') {
            throw new ExtractionFailedException('PDF contains no extractable text (scanned image?).');
        }

        $lines = $this->lines($text);
        $rows = [];
        $grade = null;
        $buffer = [];

        foreach ($lines as $line) {
            if ($found = $this->gradeLabel($line)) {
                $rows = array_merge($rows, $this->flush($buffer, $grade));
                $buffer = [];
                $grade = $found;
                continue;
            }

            if ($grade === null || $this->isNoise($line)) {
                continue;
            }

            // A new section name closes the previous row.
            if ($this->isSectionStart($line)) {
                $rows = array_merge($rows, $this->flush($buffer, $grade));
                $buffer = [];
            }

            $buffer[] = $line;
        }

        return array_merge($rows, $this->flush($buffer, $grade));
    }

    /** @return array<int, string> */
    private function lines(string $text): array
    {
        return array_values(array_filter(array_map(
            fn ($line) => trim(preg_replace('/\s+/u', ' ', str_replace(["\u{200B}", "\t"], ' ', $line))),
            explode("\n", $text),
        ), fn ($line) => $line !== ''));
    }

    private function gradeLabel(string $line): ?string
    {
        return preg_match('/^GRADE\s*(7|8|9|10)\b/i', $line, $m) ? 'G' . $m[1] : null;
    }

    /**
     * Column headers, the Grade Level Leader lines, and the signature block.
     * "Section" survives extraction as "Sec tion" in one header.
     */
    private function isNoise(string $line): bool
    {
        return (bool) preg_match('/^(?:sec\s*tion|moderator|teacher-partners?|room)\b/i', $line)
            || (bool) preg_match('/^grade level leader/i', $line)
            || (bool) preg_match('/^prepared by/i', $line)
            || (bool) preg_match('/^(?:ateneo|junior high|accredited|academic year|list of class)/i', $line)
            || preg_match('/^' . self::HONORIFICS . '\s+[A-ZÑ\s.]+$/u', $line) === 1;
    }

    private function isSectionStart(string $line): bool
    {
        return (bool) preg_match('/^(?:Saint|St\.|Blessed)\s+/u', $line);
    }

    /**
     * Turn one section's buffered lines into a row. Everything is recovered by
     * marker: the room is the trailing 3-digit number, the two people are the
     * honorific-led fragments in order, and the section is what remains.
     *
     * @param  array<int, string>  $buffer
     * @return array<int, array<string, mixed>>
     */
    private function flush(array $buffer, ?string $grade): array
    {
        if ($buffer === [] || $grade === null || ! $this->isSectionStart($buffer[0] ?? '')) {
            return [];
        }

        $joined = implode(' ', $buffer);
        $flags = [];

        $isMagis = (bool) preg_match('/\(\s*Magis\s+Class\s*\)/i', $joined);
        $joined = preg_replace('/\(\s*Magis\s+Class\s*\)/i', ' ', $joined);

        $room = null;
        if (preg_match('/\b(\d{3})\s*$/', trim($joined), $m)) {
            $room = $m[1];
            $joined = preg_replace('/\b\d{3}\s*$/', ' ', trim($joined));
        } else {
            $flags['room'] = 'No room number found on this row.';
        }

        // Split on the honorifics: everything before the first is the section,
        // then the moderator, then the teacher-partner.
        $parts = preg_split('/\s+(?=' . self::HONORIFICS . '\s+\p{Lu})/u', trim($joined));

        $fullName = trim($parts[0] ?? '');
        $moderator = isset($parts[1]) ? $this->person($parts[1]) : null;
        $partner = isset($parts[2]) ? $this->person($parts[2]) : null;

        if ($moderator === null) {
            $flags['moderator_name'] = 'No moderator found for this section.';
        }
        if ($partner === null) {
            $flags['teacher_partner_name'] = 'No teacher-partner found for this section.';
        }

        [$proposed, $nameFlag] = $this->proposeShortName($fullName);
        if ($nameFlag) {
            $flags['name'] = $nameFlag;
        }

        return [[
            'grade_level' => $grade,
            'full_name' => $fullName,
            'name' => $proposed,
            'room' => $room,
            'is_magis' => $isMagis,
            'moderator_name' => $moderator,
            'teacher_partner_name' => $partner,
            'flagged' => $flags !== [],
            'flags' => $flags,
        ]];
    }

    /** Strip the honorific, the ", SJ" suffix and a trailing "(GLL)" marker. */
    private function person(string $raw): ?string
    {
        $name = preg_replace('/^' . self::HONORIFICS . '\s+/u', '', trim($raw));
        $name = preg_replace('/\(\s*GLL\s*\)/i', ' ', $name);
        $name = preg_replace('/,?\s*\bSJ\b\.?/u', ' ', $name);
        $name = trim(preg_replace('/\s+/u', ' ', $name));

        return $name === '' ? null : $name;
    }

    /**
     * Propose a short name, and say so when the call is not ours to make.
     * "Saint John de Britto" is stored as "De Britto" but "Saint Jose de
     * Anchieta" as "Anchieta" — a connective means a human decides.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function proposeShortName(string $fullName): array
    {
        $stripped = trim(preg_replace('/^(?:Saint|St\.|Blessed)\s+/u', '', $fullName));

        if ($stripped === '') {
            return [null, 'Could not read a section name on this row.'];
        }

        $tokens = preg_split('/\s+/u', $stripped) ?: [];

        foreach ($tokens as $i => $token) {
            if (in_array(mb_strtolower($token), ['de', 'la', 'of', 'del'], true)) {
                $proposal = implode(' ', array_slice($tokens, $i));

                return [$proposal, "\"{$fullName}\" contains \"{$token}\" — confirm the short name; the roster is inconsistent about keeping it."];
            }
        }

        return [end($tokens), null];
    }
}
```

- [ ] **Step 5: Run the tests**

Run: `php artisan test --filter=RosterExtractionServiceTest`
Expected: PASS (10 tests). If a row-splitting assertion fails, dump the parsed lines with `php -r` against the fixture and adjust `isNoise()`/`flush()` — do not weaken the assertions.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Roster/RosterExtractionService.php tests/Unit/RosterExtractionServiceTest.php tests/Fixtures/class-moderators.pdf
git commit -m "feat: extract the registrar roster PDF into section rows"
```

---

### Task 4: Golden test — extraction reproduces the verified seeder data

The correctness proof for the whole module. `SectionSeeder`'s 36 rows were hand-transcribed and verified; extraction must reproduce them, modulo short names (which the review step owns).

**Files:**
- Test: `tests/Unit/RosterExtractionGoldenTest.php`

**Interfaces:**
- Consumes: `RosterExtractionService::extract()` from Task 3; `SectionSeeder`'s seeded rows.
- Produces: nothing consumed by later tasks.

- [ ] **Step 1: Write the test**

Create `tests/Unit/RosterExtractionGoldenTest.php`:

```php
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
```

- [ ] **Step 2: Run it**

Run: `php artisan test --filter=RosterExtractionGoldenTest`
Expected: PASS. Any failure names the exact section and field that disagrees — fix the extractor, never the assertion. Note the seeder's `full_name` for Brebeuf is `Saint John de Brebeuf` while the PDF has a double space; normalising whitespace in `lines()` (Task 3) already handles that.

- [ ] **Step 3: Commit**

```bash
git add tests/Unit/RosterExtractionGoldenTest.php
git commit -m "test: prove roster extraction reproduces the verified seeder data"
```

---

### Task 5: Staging table and upload flow

Persists an upload and its extracted rows so an Admin can review before anything touches `sections`.

**Files:**
- Create: `database/migrations/2026_09_04_000002_create_roster_imports_table.php`
- Create: `database/migrations/2026_09_04_000003_create_roster_extraction_rows_table.php`
- Create: `app/Models/RosterImport.php`
- Create: `app/Models/RosterExtractionRow.php`
- Create: `app/Http/Requests/Admin/StoreRosterImportRequest.php`
- Test: `tests/Feature/Admin/RosterImportUploadTest.php`

**Interfaces:**
- Consumes: `RosterExtractionService::extract()` from Task 3.
- Produces: `RosterImport` (`school_year`, `file_path`, `original_filename`, `extraction_status`, `extracted_at`; `rows()` hasMany); `RosterExtractionRow` (`roster_import_id`, `row_json` array cast, `row_status` cast to `ExtractionRowStatus`; `import()` belongsTo).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Admin/RosterImportUploadTest.php`:

```php
<?php

namespace Tests\Feature\Admin;

use App\Models\RosterImport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RosterImportUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_uploads_the_roster_and_rows_are_staged(): void
    {
        Storage::fake('local');
        $this->seed();
        $admin = User::where('email', 'admin@jhs.test')->first();

        $pdf = new UploadedFile(base_path('tests/Fixtures/class-moderators.pdf'), 'roster.pdf', 'application/pdf', null, true);

        $this->actingAs($admin)
            ->post(route('admin.roster.store'), ['pdf' => $pdf])
            ->assertRedirect(route('admin.roster.review'));

        $import = RosterImport::latest('id')->first();
        $this->assertNotNull($import);
        $this->assertSame('extracted', $import->extraction_status);
        $this->assertSame(36, $import->rows()->count());
    }

    public function test_a_chair_may_not_upload_a_roster(): void
    {
        $this->seed();
        $chair = User::where('email', 'chair.fil@jhs.test')->first();

        $this->actingAs($chair)->get(route('admin.roster.create'))->assertForbidden();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=RosterImportUploadTest`
Expected: FAIL — route `admin.roster.store` is not defined.

- [ ] **Step 3: Create the migrations**

`database/migrations/2026_09_04_000002_create_roster_imports_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roster_imports', function (Blueprint $table) {
            $table->id();
            $table->string('school_year', 9);
            $table->string('file_path');
            $table->string('original_filename');
            $table->string('extraction_status')->default('pending');
            $table->timestamp('extracted_at')->nullable();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roster_imports');
    }
};
```

`database/migrations/2026_09_04_000003_create_roster_extraction_rows_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roster_extraction_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('roster_import_id')->constrained()->cascadeOnDelete();
            // Fields live as JSON keys, as with plantilla_extraction_rows, so a
            // new field needs no migration. Never authoritative.
            $table->json('row_json');
            $table->string('row_status')->default('extracted');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roster_extraction_rows');
    }
};
```

- [ ] **Step 4: Create the models**

`app/Models/RosterImport.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RosterImport extends Model
{
    protected $fillable = [
        'school_year', 'file_path', 'original_filename',
        'extraction_status', 'extracted_at', 'uploaded_by_user_id',
    ];

    protected function casts(): array
    {
        return ['extracted_at' => 'datetime'];
    }

    public function rows(): HasMany
    {
        return $this->hasMany(RosterExtractionRow::class);
    }
}
```

`app/Models/RosterExtractionRow.php`:

```php
<?php

namespace App\Models;

use App\Enums\ExtractionRowStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RosterExtractionRow extends Model
{
    protected $fillable = ['roster_import_id', 'row_json', 'row_status'];

    protected function casts(): array
    {
        return [
            'row_json' => 'array',
            'row_status' => ExtractionRowStatus::class,
        ];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(RosterImport::class, 'roster_import_id');
    }
}
```

- [ ] **Step 5: Create the form request**

`app/Http/Requests/Admin/StoreRosterImportRequest.php`:

```php
<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreRosterImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isAdmin();
    }

    public function rules(): array
    {
        return [
            'pdf' => ['required', 'file', 'mimes:pdf', 'max:10240'],
        ];
    }
}
```

- [ ] **Step 6: Create the controller**

`app/Http/Controllers/Admin/RosterImportController.php`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ExtractionRowStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreRosterImportRequest;
use App\Models\RosterImport;
use App\Models\SystemConstant;
use App\Services\Plantilla\ExtractionFailedException;
use App\Services\Roster\RosterExtractionService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class RosterImportController extends Controller
{
    public function __construct(private RosterExtractionService $extractor) {}

    public function create(): View
    {
        return view('admin.roster.upload');
    }

    public function store(StoreRosterImportRequest $request): RedirectResponse
    {
        $schoolYear = SystemConstant::get('current_school_year');
        $path = $request->file('pdf')->store('rosters', 'local');

        $import = RosterImport::create([
            'school_year' => $schoolYear,
            'file_path' => $path,
            'original_filename' => $request->file('pdf')->getClientOriginalName(),
            'extraction_status' => 'pending',
            'uploaded_by_user_id' => $request->user()->id,
        ]);

        try {
            foreach ($this->extractor->extract($request->file('pdf')->getRealPath()) as $row) {
                $import->rows()->create([
                    'row_json' => $row,
                    'row_status' => $row['flagged'] ? ExtractionRowStatus::Flagged : ExtractionRowStatus::Extracted,
                ]);
            }

            $import->update(['extraction_status' => 'extracted', 'extracted_at' => now()]);

            return redirect()->route('admin.roster.review')
                ->with('status', $import->rows()->count() . ' sections extracted. Review them before importing.');
        } catch (ExtractionFailedException $e) {
            $import->update(['extraction_status' => 'failed']);

            return redirect()->route('admin.roster.review')
                ->with('warning', "Couldn't read that PDF automatically. Enter the roster manually instead.");
        }
    }
}
```

- [ ] **Step 7: Add the routes**

In `routes/web.php`, inside the `admin.` group, immediately after the `sections` routes:

```php
    Route::get('roster/upload', [Admin\RosterImportController::class, 'create'])->name('roster.create');
    Route::post('roster', [Admin\RosterImportController::class, 'store'])->name('roster.store');
```

- [ ] **Step 8: Create the upload view**

`resources/views/admin/roster/upload.blade.php`:

```blade
<x-app-layout>
    <x-page-header eyebrow="Registrar Reference Data" title="Import Class Moderator List" />

    <x-flash />

    <div class="card p-6 max-w-2xl">
        <p class="text-slate-brand text-sm mb-5">
            Upload the registrar's <span class="font-semibold">List of Class Moderators</span> PDF for
            {{ \App\Models\SystemConstant::get('current_school_year') }}. Nothing is saved until you review
            and confirm it &mdash; section short names in particular need your confirmation.
        </p>

        <form method="POST" action="{{ route('admin.roster.store') }}" enctype="multipart/form-data" class="flex flex-col gap-4">
            @csrf
            <div>
                <label class="field-label">Roster PDF</label>
                <input type="file" name="pdf" accept="application/pdf" required class="field-input">
                @error('pdf') <p class="text-rose-brand text-[13px] mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <button type="submit" class="btn-hero">Upload &amp; extract</button>
            </div>
        </form>
    </div>
</x-app-layout>
```

- [ ] **Step 9: Add a placeholder review route so the redirect resolves**

Task 6 builds the real screen. For now, add to `routes/web.php` inside the admin group:

```php
    Route::get('roster/review', [Admin\RosterImportController::class, 'review'])->name('roster.review');
```

and to `RosterImportController`:

```php
    public function review(): View
    {
        $import = RosterImport::latest('id')->first();

        return view('admin.roster.review', [
            'import' => $import,
            'rows' => $import?->rows()->orderBy('id')->get() ?? collect(),
        ]);
    }
```

Create a minimal `resources/views/admin/roster/review.blade.php` so the route renders:

```blade
<x-app-layout>
    <x-page-header eyebrow="Registrar Reference Data" title="Review Roster" />
    <x-flash />
    <p class="text-slate-brand text-sm">{{ $rows->count() }} sections staged.</p>
</x-app-layout>
```

- [ ] **Step 10: Run the tests**

Run: `php artisan test --filter=RosterImportUploadTest`
Expected: PASS (2 tests)

- [ ] **Step 11: Commit**

```bash
git add database/migrations/2026_09_04_000002_create_roster_imports_table.php database/migrations/2026_09_04_000003_create_roster_extraction_rows_table.php app/Models/RosterImport.php app/Models/RosterExtractionRow.php app/Http/Requests/Admin/StoreRosterImportRequest.php app/Http/Controllers/Admin/RosterImportController.php resources/views/admin/roster routes/web.php tests/Feature/Admin/RosterImportUploadTest.php
git commit -m "feat: stage an uploaded registrar roster for review"
```

---

### Task 6: Admin review screen

Where the Admin confirms short names and clears flags. Mirrors `chair/plantilla/review.blade.php`, including the per-field flag rendering added in the last checkpoint.

**Files:**
- Modify: `app/Http/Controllers/Admin/RosterImportController.php` (add `updateRow`)
- Create: `app/Http/Requests/Admin/UpdateRosterRowRequest.php`
- Modify: `resources/views/admin/roster/review.blade.php` (replace the placeholder)
- Modify: `routes/web.php`
- Test: `tests/Feature/Admin/RosterReviewTest.php`

**Interfaces:**
- Consumes: `RosterExtractionRow` from Task 5.
- Produces: route `admin.roster.rows.update`; `UpdateRosterRowRequest::rowData(): array` returning the seven editable keys plus `flags`/`flagged` preserved.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Admin/RosterReviewTest.php`:

```php
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=RosterReviewTest`
Expected: FAIL — route `admin.roster.rows.update` is not defined.

- [ ] **Step 3: Create the form request**

`app/Http/Requests/Admin/UpdateRosterRowRequest.php`:

```php
<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRosterRowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isAdmin();
    }

    public function rules(): array
    {
        return [
            'grade_level' => ['required', 'in:G7,G8,G9,G10'],
            'full_name' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'room' => ['nullable', 'string', 'max:20'],
            'is_magis' => ['nullable', 'boolean'],
            'moderator_name' => ['nullable', 'string', 'max:255'],
            'teacher_partner_name' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** The editable fields; the Admin's edit clears the row's flags. */
    public function rowData(): array
    {
        return [
            'grade_level' => $this->input('grade_level'),
            'full_name' => $this->input('full_name'),
            'name' => $this->input('name'),
            'room' => $this->input('room'),
            'is_magis' => $this->boolean('is_magis'),
            'moderator_name' => $this->input('moderator_name'),
            'teacher_partner_name' => $this->input('teacher_partner_name'),
            'flagged' => false,
            'flags' => [],
        ];
    }
}
```

- [ ] **Step 4: Add the controller action and route**

Add to `app/Http/Controllers/Admin/RosterImportController.php`:

```php
    public function updateRow(UpdateRosterRowRequest $request, RosterExtractionRow $row): RedirectResponse
    {
        $row->update([
            'row_json' => $request->rowData(),
            'row_status' => ExtractionRowStatus::Extracted,
        ]);

        return back()->with('status', 'Row updated.');
    }
```

with imports `use App\Http\Requests\Admin\UpdateRosterRowRequest;` and `use App\Models\RosterExtractionRow;`.

Add to `routes/web.php` in the admin group:

```php
    Route::patch('roster/rows/{row}', [Admin\RosterImportController::class, 'updateRow'])->name('roster.rows.update');
```

- [ ] **Step 5: Replace the review view**

Replace `resources/views/admin/roster/review.blade.php` entirely:

```blade
<x-app-layout>
    <x-page-header eyebrow="Registrar Reference Data" title="Review Roster">
        <x-slot:actions>
            @if ($rows->isNotEmpty())
                <form method="POST" action="{{ route('admin.roster.confirm') }}"
                      onsubmit="return confirm('Import this roster as the section list for {{ $import->school_year }}?')">
                    @csrf
                    <button type="submit" class="btn-hero">Confirm &amp; import</button>
                </form>
            @endif
        </x-slot:actions>
    </x-page-header>

    <x-flash />

    @php
        $flaggedCount = $rows->filter(fn ($r) => ! empty(($r->row_json['flags'] ?? [])))->count();
    @endphp

    @if ($rows->isNotEmpty())
        <div class="card px-6 py-5 mb-5 flex flex-wrap items-center gap-x-10 gap-y-4">
            <div>
                <span class="stat-mini-number">{{ $rows->count() }}</span>
                <span class="stat-mini-label">Sections extracted</span>
            </div>
            <div>
                <span class="stat-mini-number {{ $flaggedCount ? 'text-amber-brand' : '' }}">{{ $flaggedCount }}</span>
                <span class="stat-mini-label">Need attention</span>
            </div>
            <p class="text-[12.5px] text-slate-brand flex-1 min-w-[220px]">
                Short names are how every plantilla resolves its sections &mdash; confirm each one
                against the roster before importing.
            </p>
        </div>
    @endif

    <div class="flex flex-col gap-3">
        @forelse ($rows as $row)
            @php
                $data = is_array($row->row_json) ? $row->row_json : [];
                $flags = $data['flags'] ?? [];
            @endphp
            <details class="ledger-row @if ($flags) ledger-row-flagged @endif" @if ($flags) open @endif>
                <summary class="ledger-summary">
                    <span class="ledger-ordinal">{{ $loop->iteration }}</span>
                    <span class="flex-1 min-w-0">
                        <span class="flex flex-wrap items-center gap-x-3 gap-y-1">
                            <span class="font-bold text-[15px] text-ink truncate">
                                {{ $data['grade_level'] ?? '' }}: {{ $data['name'] ?: 'Unnamed section' }}
                            </span>
                            @if ($data['is_magis'] ?? false)
                                <span class="pill-draft">Magis</span>
                            @endif
                            @if ($flags)
                                <span class="flag flag-warn">Needs review</span>
                            @endif
                        </span>
                        <span class="block font-data text-[13px] text-slate-brand truncate mt-1">
                            {{ $data['full_name'] ?? '' }} &middot; Room {{ $data['room'] ?: '—' }}
                        </span>
                    </span>
                    <svg class="ledger-chevron" width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                        <path d="M4 6l4 4 4-4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </summary>

                <div class="ledger-body">
                    @foreach ($flags as $field => $message)
                        <p class="text-[13px] text-[#8a6200] bg-[#fdf6e3] border border-[#f0e0a8] rounded px-3 py-2 mb-3">
                            <span class="font-semibold">{{ ucfirst(str_replace('_', ' ', $field)) }}:</span> {{ $message }}
                        </p>
                    @endforeach

                    <form method="POST" action="{{ route('admin.roster.rows.update', $row) }}" class="flex flex-col gap-4">
                        @csrf @method('PATCH')
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label class="field-label">Grade</label>
                                <select name="grade_level" class="field-input">
                                    @foreach (['G7', 'G8', 'G9', 'G10'] as $grade)
                                        <option value="{{ $grade }}" @selected(($data['grade_level'] ?? null) === $grade)>{{ $grade }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="field-label">Room</label>
                                <input name="room" value="{{ $data['room'] ?? '' }}" class="field-input font-data">
                            </div>
                            <div class="sm:col-span-2">
                                <label class="field-label">Registrar's full name</label>
                                <input name="full_name" value="{{ $data['full_name'] ?? '' }}" class="field-input">
                            </div>
                            <div class="sm:col-span-2">
                                <label class="field-label">Short name (used by plantilla matching)</label>
                                <input name="name" value="{{ $data['name'] ?? '' }}" class="field-input font-data">
                            </div>
                            <div>
                                <label class="field-label">Moderator</label>
                                <input name="moderator_name" value="{{ $data['moderator_name'] ?? '' }}" class="field-input">
                            </div>
                            <div>
                                <label class="field-label">Teacher-partner</label>
                                <input name="teacher_partner_name" value="{{ $data['teacher_partner_name'] ?? '' }}" class="field-input">
                            </div>
                            <div class="sm:col-span-2">
                                <label class="flex items-center gap-2 text-[13.5px]">
                                    <input type="hidden" name="is_magis" value="0">
                                    <input type="checkbox" name="is_magis" value="1" @checked($data['is_magis'] ?? false)>
                                    Magis class
                                </label>
                            </div>
                        </div>
                        <div><button type="submit" class="btn-secondary">Save section</button></div>
                    </form>
                </div>
            </details>
        @empty
            <div class="card p-10 text-center bg-parchment/60">
                <p class="font-display text-[22px] text-ink uppercase">Nothing to review</p>
                <a href="{{ route('admin.roster.create') }}" class="btn-primary mt-5">Upload roster</a>
            </div>
        @endforelse
    </div>
</x-app-layout>
```

- [ ] **Step 6: Run the tests**

Run: `php artisan test --filter=RosterReviewTest`
Expected: PASS (2 tests). The confirm route does not exist yet; Task 7 adds it — if Blade fails resolving `admin.roster.confirm`, complete Task 7 Step 4 first, then re-run.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Admin/RosterImportController.php app/Http/Requests/Admin/UpdateRosterRowRequest.php resources/views/admin/roster/review.blade.php routes/web.php tests/Feature/Admin/RosterReviewTest.php
git commit -m "feat: admin review screen for the extracted roster"
```

---

### Task 7: Validate and commit the roster

The transactional import, with the guard rails from spec §4 enforced before anything is written.

**Files:**
- Create: `app/Services/Roster/RosterReviewService.php`
- Modify: `app/Http/Controllers/Admin/RosterImportController.php` (add `confirm`)
- Modify: `routes/web.php`
- Test: `tests/Unit/RosterReviewServiceTest.php`

**Interfaces:**
- Consumes: `RosterExtractionRow`, `RosterImport` (Task 5); `Teacher::normalize(?string): string` (existing); `AuditLogService::log(string $action, Model $auditable, ?array $before, ?array $after)` (existing).
- Produces: `RosterReviewService::confirmImport(RosterImport $import): array{imported:int, errors:array<int,string>}` — writes nothing and returns errors when validation fails.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/RosterReviewServiceTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Models\RosterImport;
use App\Models\Section;
use App\Models\Teacher;
use App\Services\Roster\RosterReviewService;
use Database\Seeders\SystemConstantSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RosterReviewServiceTest extends TestCase
{
    use RefreshDatabase;

    /** Build an import of 36 valid rows, 9 per grade. */
    private function importWith(array $mutate = []): RosterImport
    {
        $import = RosterImport::create([
            'school_year' => '2027-2028',
            'file_path' => 'rosters/x.pdf',
            'original_filename' => 'x.pdf',
            'extraction_status' => 'extracted',
        ]);

        $n = 0;
        foreach (['G7', 'G8', 'G9', 'G10'] as $grade) {
            for ($i = 1; $i <= 9; $i++) {
                $n++;
                $import->rows()->create([
                    'row_json' => array_merge([
                        'grade_level' => $grade,
                        'full_name' => "Saint Section {$n}",
                        'name' => "Section{$n}",
                        'room' => (string) (100 + $n),
                        'is_magis' => false,
                        'moderator_name' => "Moderator {$n}",
                        'teacher_partner_name' => "Partner {$n}",
                        'flagged' => false,
                        'flags' => [],
                    ], $mutate[$n] ?? []),
                    'row_status' => \App\Enums\ExtractionRowStatus::Extracted,
                ]);
            }
        }

        return $import;
    }

    public function test_imports_sections_for_the_imports_school_year(): void
    {
        $this->seed(SystemConstantSeeder::class);

        $result = app(RosterReviewService::class)->confirmImport($this->importWith());

        $this->assertSame(36, $result['imported']);
        $this->assertSame([], $result['errors']);
        $this->assertSame(36, Section::where('school_year', '2027-2028')->count());
    }

    public function test_adopts_moderators_and_partners_as_teachers(): void
    {
        $this->seed(SystemConstantSeeder::class);

        app(RosterReviewService::class)->confirmImport($this->importWith());

        $this->assertSame(72, Teacher::count()); // 36 moderators + 36 partners
    }

    public function test_does_not_duplicate_an_existing_teacher(): void
    {
        $this->seed(SystemConstantSeeder::class);
        Teacher::create(['full_name' => 'Moderator 1', 'department_id' => null]);

        app(RosterReviewService::class)->confirmImport($this->importWith());

        $this->assertSame(1, Teacher::where('normalized_name', Teacher::normalize('Moderator 1'))->count());
    }

    public function test_refuses_when_a_grade_does_not_have_nine_sections(): void
    {
        $this->seed(SystemConstantSeeder::class);
        $import = $this->importWith([1 => ['grade_level' => 'G8']]); // G7 now has 8, G8 has 10

        $result = app(RosterReviewService::class)->confirmImport($import);

        $this->assertSame(0, $result['imported']);
        $this->assertNotEmpty($result['errors']);
        $this->assertSame(0, Section::where('school_year', '2027-2028')->count());
    }

    public function test_refuses_on_a_duplicate_short_name(): void
    {
        $this->seed(SystemConstantSeeder::class);
        $import = $this->importWith([2 => ['name' => 'Section1']]);

        $result = app(RosterReviewService::class)->confirmImport($import);

        $this->assertSame(0, $result['imported']);
        $this->assertStringContainsString('Section1', implode(' ', $result['errors']));
    }

    public function test_refuses_on_a_missing_room(): void
    {
        $this->seed(SystemConstantSeeder::class);
        $import = $this->importWith([3 => ['room' => null]]);

        $result = app(RosterReviewService::class)->confirmImport($import);

        $this->assertSame(0, $result['imported']);
        $this->assertNotEmpty($result['errors']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=RosterReviewServiceTest`
Expected: FAIL — class `App\Services\Roster\RosterReviewService` not found.

- [ ] **Step 3: Implement the service**

Create `app/Services/Roster/RosterReviewService.php`:

```php
<?php

namespace App\Services\Roster;

use App\Models\RosterImport;
use App\Models\Section;
use App\Models\Teacher;
use App\Services\Audit\AuditLogService;
use Illuminate\Support\Facades\DB;

/**
 * Commits a reviewed roster to the sections table for its school year, and
 * adopts the moderator/teacher-partner names into the teacher directory.
 *
 * Validation is all-or-nothing: the roster defines the section list every
 * plantilla import resolves against, so a partial or internally inconsistent
 * roster must never land. The rules mirror the invariants SeedTest asserts.
 */
class RosterReviewService
{
    public function __construct(private AuditLogService $audit) {}

    /**
     * @return array{imported:int, errors:array<int,string>}
     */
    public function confirmImport(RosterImport $import): array
    {
        $rows = $import->rows()->orderBy('id')->get()->map(fn ($row) => $row->row_json)->all();

        if ($errors = $this->validate($rows)) {
            return ['imported' => 0, 'errors' => $errors];
        }

        return DB::transaction(function () use ($import, $rows) {
            $imported = 0;

            foreach ($rows as $row) {
                Section::updateOrCreate(
                    [
                        'school_year' => $import->school_year,
                        'grade_level' => $row['grade_level'],
                        'name' => $row['name'],
                    ],
                    [
                        'full_name' => $row['full_name'],
                        'room' => $row['room'],
                        'is_magis' => (bool) ($row['is_magis'] ?? false),
                        'moderator_name' => $row['moderator_name'],
                        'teacher_partner_name' => $row['teacher_partner_name'],
                    ],
                );

                foreach ([$row['moderator_name'] ?? null, $row['teacher_partner_name'] ?? null] as $person) {
                    $this->adopt($person);
                }

                $imported++;
            }

            $import->update(['extraction_status' => 'imported']);
            $this->audit->log('roster.imported', $import, after: [
                'school_year' => $import->school_year,
                'sections' => $imported,
            ]);

            return ['imported' => $imported, 'errors' => []];
        });
    }

    /**
     * Create the registrar-named person if they aren't already on file.
     *
     * Deliberately NOT TeacherResolver: with a null department that resolver
     * skips its adoption branch (`$verdict === Same && $department`) and falls
     * through to Teacher::create(), forking anyone already in the directory.
     * This is the same normalized-name check RegistrarStaffSeeder uses, and the
     * department is left null — only a plantilla says which department someone
     * belongs to, and TeacherResolver adopts these rows when that sheet lands.
     */
    private function adopt(?string $name): void
    {
        $name = trim((string) $name);

        if ($name === '') {
            return;
        }

        $key = Teacher::normalize($name);

        if (Teacher::where('normalized_name', $key)->exists()) {
            return;
        }

        Teacher::create(['full_name' => $name, 'department_id' => null, 'source' => 'registrar']);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, string>
     */
    private function validate(array $rows): array
    {
        $errors = [];

        if (count($rows) !== 36) {
            $errors[] = 'Expected 36 sections, found ' . count($rows) . '. The roster must be complete before importing.';
        }

        foreach (['G7', 'G8', 'G9', 'G10'] as $grade) {
            $count = count(array_filter($rows, fn ($r) => ($r['grade_level'] ?? null) === $grade));
            if ($count !== 9) {
                $errors[] = "{$grade} has {$count} sections; every grade must have exactly 9.";
            }
        }

        $seen = [];
        foreach ($rows as $row) {
            $name = trim((string) ($row['name'] ?? ''));

            if ($name === '') {
                $errors[] = 'A section has no short name. Plantilla matching needs one for every section.';
                continue;
            }

            $key = mb_strtolower($name);
            if (isset($seen[$key])) {
                $errors[] = "Two sections are both named \"{$name}\". Section names must be unique school-wide, or plantilla matching cannot recover a grade from a name.";
            }
            $seen[$key] = true;

            if (! preg_match('/^\d+$/', trim((string) ($row['room'] ?? '')))) {
                $errors[] = "\"{$name}\" has no room number.";
            }
        }

        return array_values(array_unique($errors));
    }
}
```

- [ ] **Step 4: Wire the confirm action and route**

Add to `app/Http/Controllers/Admin/RosterImportController.php`:

```php
    public function confirm(RosterReviewService $rosters): RedirectResponse
    {
        $import = RosterImport::latest('id')->firstOrFail();
        $result = $rosters->confirmImport($import);

        if ($result['errors']) {
            return back()->with('warning', implode(' ', $result['errors']));
        }

        return redirect()->route('admin.sections.index')
            ->with('status', "Imported {$result['imported']} sections for {$import->school_year}.");
    }
```

with `use App\Services\Roster\RosterReviewService;`.

Add to `routes/web.php` in the admin group:

```php
    Route::post('roster/confirm', [Admin\RosterImportController::class, 'confirm'])->name('roster.confirm');
```

- [ ] **Step 5: Run the tests**

Run: `php artisan test --filter=RosterReviewServiceTest`
Expected: PASS (6 tests)

- [ ] **Step 6: Run the full suite**

Run: `php artisan test`
Expected: PASS — everything, including the plantilla tests untouched by this work.

- [ ] **Step 7: Commit**

```bash
git add app/Services/Roster/RosterReviewService.php app/Http/Controllers/Admin/RosterImportController.php routes/web.php tests/Unit/RosterReviewServiceTest.php
git commit -m "feat: validate and commit a reviewed roster"
```

---

### Task 8: Navigation and documentation

Makes the feature reachable and records what changed.

**Files:**
- Modify: `resources/views/layouts/navigation.blade.php` (or the admin sidebar partial — find it with `grep -rn "Section roster" resources/views`)
- Create: `docs/Documentation/Registrar Roster Import.md`
- Modify: `docs/Index.md`

**Interfaces:**
- Consumes: routes `admin.roster.create` from Task 5.
- Produces: nothing consumed by later tasks.

- [ ] **Step 1: Add the sidebar link**

Find the admin "Reference data" nav section:

```bash
grep -rn "Section roster" resources/views
```

Add an entry immediately after the Teacher directory link, matching the surrounding markup exactly:

```blade
<a href="{{ route('admin.roster.create') }}"
   class="relative flex items-center gap-3 px-3 py-2.5 rounded-[9px] text-[13.5px] transition {{ request()->routeIs('admin.roster.*') ? 'bg-white/[.08] text-white font-semibold' : 'text-white/80 hover:bg-navy-800 hover:text-white font-medium' }}">
    <span class="w-4 h-4 rounded-[5px] flex-none {{ request()->routeIs('admin.roster.*') ? 'bg-canary' : 'bg-white/25' }}"></span>
    Import roster
</a>
```

- [ ] **Step 2: Verify the link renders for an admin**

Run: `php artisan test --filter=RosterImportUploadTest`
Expected: PASS (unchanged).

- [ ] **Step 3: Write the documentation**

Create `docs/Documentation/Registrar Roster Import.md`:

```markdown
# Registrar Roster Import

How the registrar's **List of Class Moderators** enters the system, replacing the
hand-edited `SectionSeeder`. Implements
[[2026-09-04-registrar-roster-extraction-design]].

## Flow

Admin → Reference data → **Import roster** → upload PDF → review → Confirm & import.

Extraction stages rows in `roster_extraction_rows`; nothing reaches `sections`
until the Admin confirms. Section **short names** always need confirmation: the
registrar writes `Saint John de Britto` where the system stores `De Britto`, but
`Saint Jose de Anchieta` is stored as `Anchieta` — the call is editorial, so the
extractor proposes and flags rather than deciding.

## Sections are year-scoped

`sections` carries `school_year`, unique on `(school_year, grade_level, name)`.
Prior years stay intact when a new roster lands, and historical assignments keep
resolving to the right rows.

**This is load-bearing for plantilla extraction.** `SectionResolver` recovers a
section's grade from its name alone, which only works because names are unique —
*within a year*. Every section lookup is therefore scoped to
`current_school_year`: `SectionResolver::boot()`,
`PlantillaReviewService::importModerator()`, `SectionSeeder`, `RegistrarStaffSeeder`.
Adding an unscoped `Section::` query will silently reintroduce cross-year
collisions.

## Import is refused when

- the roster is not exactly 36 sections, or a grade does not have exactly 9
- two sections share a short name
- a section has no room number

These mirror the invariants `SeedTest` asserts, enforced at import time.

## Open questions

- Importing a roster does **not** advance `current_school_year`; rolling the year
  over is a separate deliberate act.
- English is seeded as a department but appears in no roster document. If a future
  list includes English sections, the fixed "36" validation becomes a configured
  expectation.
- Cross-source spellings (`Vendiola` on the roster, `Vendola` on the MAPEH sheet)
  are reported by `TeacherResolver`, never silently merged.
```

- [ ] **Step 4: Index the document**

In `docs/Index.md`, add to the Documentation table after the checkpoint rows:

```markdown
| [[Documentation/Registrar Roster Import\|Registrar Roster Import]] | How the registrar's class-moderator list is uploaded, reviewed and imported; why sections are year-scoped and which lookups depend on it. |
```

- [ ] **Step 5: Run the full suite**

Run: `php artisan test`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add resources/views docs/Documentation/Registrar\ Roster\ Import.md docs/Index.md
git commit -m "docs: document the registrar roster import flow"
```
