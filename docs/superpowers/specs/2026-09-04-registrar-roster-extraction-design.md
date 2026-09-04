# Design — Registrar Roster Extraction & Year-Scoped Sections

Date: 2026-09-04. Companion to [[JHS System SRS]], [[JHS Laravel System Architecture]],
and the [[Load Calculation and Review Gaps Checkpoint]]. Covers one module: ingesting
the registrar's **"List of Class Moderators"** (`docs/List of Class Mods and
Teacher-Partners 2026.pdf`) through the application instead of a hand-edited seeder.

## Purpose

**Year-over-year self-service.** Today the 36 sections, their rooms, moderators and
teacher-partners live in `database/seeders/SectionSeeder.php` — hand-transcribed,
verified, and editable only by a developer. When SY 2027's roster arrives, an Admin
must be able to upload it and have the system ingest it.

This module cannot improve on the current year's data: the existing 36 rows are
hand-verified and an extractor is strictly less reliable than that. Its value is
entirely in the rollover case.

## Scope

**In:** roster PDF upload (Admin), extraction to staging, Admin review screen,
commit to `sections`; year-scoping the `sections` table and every section lookup;
adoption of moderator/teacher-partner names into `teachers`.

**Out:** changing how plantillas are extracted or imported; promoting
`teacher_partner_name` to a relation (open gap #5, still on hold); Grade Level
Leader capture (the schema models GLL as an `OtherAssignmentRole`, not a section
field — the document's GLL lines are ignored).

---

## 1. The source document

Four grade blocks, each a 4-column table. Verified against the rendered PDF, not
just its extracted text — the document itself is clean and consistently structured.

```
Grade Level Leader: Mrs. Hazel G. Sumicad        ← ignored (not a section field)
GRADE 7                                          ← explicit block label
Section                 Moderator                Teacher-Partners           Room
Saint Edmund Arrowsmith Ms. Frizie B. Dealagdon  Ms. Angel Joy S. Lauresta  206
Saint Robert Bellarmine Ms. Nerissa T. Brigoli   Ms. Jirah R. Macalintal    302
...
```

**Strong parse anchors** — better than anything the plantillas offer:

| Field | Anchor |
|---|---|
| Grade block | literal `GRADE 7/8/9/10` header — order is never assumed |
| Section | begins `Saint ` or `Blessed ` |
| Moderator / partner | begins an honorific: `Ms. Mr. Mrs. Bb. Gng. Br. Sch.` |
| Room | bare 3-digit number, terminates the row |
| Magis flag | literal `(Magis Class)` under the section name |

**Known text-extraction artifacts** (document is fine; the parser flattens it):
grade blocks emerge out of order (8, 7, 9, 10); the two GLL lines collapse together
above `GRADE 8`; long names wrap mid-cell (`Ms. Ma. Julianna Yzabel G.` / `Ragay`);
`Section` renders as `Sec tion` in one header. All are handled by parsing on the
anchors above rather than on reading order.

**Noise to strip:** honorifics, the `, SJ` suffix, and a trailing `(GLL)` marker on
teacher-partner names (`Mrs. Hazel G. Sumicad (GLL)`).

## 2. Short names

**Corrected 2026-09-04.** An earlier draft of this section claimed short names were
"editorial, not mechanical" and that "no rule produces all five". That was wrong,
and it was drawn from the roster document alone. Checking how the seven plantillas
actually write these sections settles it:

| Section | How the sheets write it | Particle |
|---|---|---|
| De Britto | `De Britto` (FIL, CLE, MATH) · `De Brito` (TLE, SCI, MAPEH) | **kept** — no sheet writes bare `Britto` |
| Anchieta | `Anchieta` (six sheets) · `Anchietta` (SCI) | dropped |
| Brebeuf | `Brebeuf` (all sheets) | dropped |
| Colombiere | `Colombiere` · `Colombierre` (FIL) | dropped |
| Ignatius of Loyola | `Ignatius` · `Loyola` · `Ignatius of Loyola` · `8Loyola` | inconsistent |

The convention is **"drop the particle, use the surname"** — the last token — and it
is correct for **34 of the 36 sections**. Two are genuine exceptions, both settled by
usage rather than by the roster's text: `De Britto` treats the particle as part of
the surname, and `Ignatius of Loyola` (the G8 Magis class) keeps the whole phrase so
that `ignatius` and `loyola` can both alias onto it.

The short name is not a matter of taste at all: **it is whatever the plantillas
write**, because `SectionResolver` matches sheet text against it. The alias table's
*values* are therefore the authority — a section named anything else would leave
`'de brito' => 'De Britto'` and `'loyola' => 'Ignatius of Loyola'` pointing at
nothing, and every sheet using those forms would stop resolving.

Proposal rule: prefer the longest known canonical name that closes the registrar's
full name (from `SectionResolver::canonicalNames()` plus sections already on file);
otherwise take the last token. Flag only when a particle is present *and* nothing
known corroborates dropping it — so a genuinely new section is still reviewed.

Against an empty database this yields **zero incorrect proposals** for the 2026
roster, with one conservative flag (`Brebeuf`, proposed correctly). Nothing is ever
committed unreviewed regardless, mirroring FR-5's mandatory review.

## 3. The blocking architectural problem

`sections` is modelled as a **timeless** entity while the data this document carries
is inherently **per-year**:

```php
// sections — no school_year column
grade_level, name, full_name, room, is_magis,
moderator_name, teacher_partner_name        // ← all change every year
unique(grade_level, name)
```

Every *assignment* table is year-scoped and points at `section_id`. So a second
import would overwrite SY 2026's roster in place, and a renamed or retired section
would orphan historical assignments.

### Decision: year-scope `sections` (Approach A)

Add `school_year`; unique key becomes `(school_year, grade_level, name)`. Each import
creates a fresh set for its year; prior years stay intact; historical assignments
keep resolving to the right rows.

Considered and rejected:
- **Split roster into a `section_rosters` table** — more normalized, and
  `teacher_partner_name` arguably belongs there as a relation, but it drags in the
  teacher-partner redesign that is deliberately on hold.
- **Overwrite in place, no history** — simplest, matches today's seeder, but
  permanently destroys the previous year's roster, which is the document's whole
  content.

### The conflict this creates with plantilla extraction

`SectionResolver`'s premise, from its own docblock:

> *"no section name is reused across grades. That uniqueness is what lets
> PdfExtractionService recover a section's grade from its name alone"*

Year-scoping breaks that uniqueness **across years**. `SectionResolver::boot()` does
`Section::all()` and indexes by normalized name, so after two years `Arrowsmith`
collides and the later row wins — a plantilla for SY 2027 could silently resolve to
SY 2026's `section_id`.

**Every section lookup must therefore become year-scoped**, using the existing
`current_school_year` constant already used consistently for assignments. Within one
year, name-uniqueness holds and the resolver's premise is restored intact.

| Call site | Change |
|---|---|
| `SectionResolver::boot()` | scope `Section::all()` to the active year |
| `PlantillaReviewService::importModerator()` | scope the `moderator_name` lookup |
| `SectionSeeder` stale-delete (`whereNotIn('id', $keep)`) | scope, or it deletes other years |
| `RegistrarStaffSeeder` | scope the name harvest |
| `SeedTest`, `ReferenceDataTest` | count assertions become year-scoped, not absolute |

**Composition benefit:** once sections are year-scoped, importing SY 2027's roster
automatically gives `SectionResolver` the correct 36 for that year. The two modules
compose rather than conflict.

---

## 4. Architecture

Mirrors the plantilla pipeline deliberately — same shape, same guarantees, Admin
instead of Chair.

```
Roster PDF ──> RosterExtractionService ──> roster_extraction_rows (staging)
                                                    │
                                          Admin review screen
                                        (short name + conflicts)
                                                    │
                                          Confirm & import
                                                    ▼
                                    sections (year-scoped)  +  teachers
```

**`RosterExtractionService`** — parses on the anchors in §1; returns one row per
section with `grade_level`, `full_name`, proposed `name`, `room`, `is_magis`,
`moderator_name`, `teacher_partner_name`, plus a `flags` map. Invents nothing;
anything ambiguous is flagged for the Admin, exactly as `PdfExtractionService` does.

**`roster_extraction_rows`** — staging table, `row_json` + `row_status`, following
`plantilla_extraction_rows` (which stores its fields as JSON keys, not columns —
so new fields need no migration).

**`RosterReviewService::confirmImport()`** — one transaction: writes the year's
sections, then adopts moderator/teacher-partner names into `teachers` (reusing
`TeacherResolver` so an existing person is matched, never duplicated — the same
behaviour `RegistrarStaffSeeder` has today). Audit-logged via `AuditLogService`.

**Admin UI** — `Admin → Reference data → Import roster`: upload, review grid, commit.
Reuses the existing review-screen patterns from `chair/plantilla/review.blade.php`,
including the per-field flag rendering added in the last checkpoint.

### Validation before commit

The import refuses to commit, reporting rather than writing, when:
- a grade block yields ≠ 9 sections
- the year's total ≠ 36
- two sections in the same year share a short name (breaks `SectionResolver`)
- a room is missing or non-numeric

These mirror the invariants `SeedTest` asserts today, enforced at import instead of
build time.

## 5. Error handling

Same contract as plantilla extraction: an unreadable or textless PDF raises
`ExtractionFailedException` and the Admin is told to enter data manually; a partially
parsed sheet still produces staging rows with flags. Nothing is auto-committed, and
no section is ever invented — an unresolvable row is reported, never guessed.

## 6. Testing

- `RosterExtractionServiceTest` against the real `List of Class Mods and
  Teacher-Partners 2026.pdf` fixture: 36 rows, 9 per grade, correct grade attribution
  despite out-of-order blocks, honorific and `(GLL)` stripping, `(Magis Class)`
  detection on exactly 3 sections, wrapped-name rejoining.
- **Golden test:** extracting the 2026 PDF reproduces the hand-verified
  `SectionSeeder` data exactly — modulo short names, which the review step owns.
  This is the correctness proof for the whole module.
- `RosterReviewServiceTest`: teacher adoption without duplication; each validation
  rule blocks commit; import is idempotent.
- Year-scoping regression: two years of sections coexist, and `SectionResolver`
  resolves a name to the active year's row — the specific bug §3 identifies.

## 7. Open questions

1. **What becomes the active year on import?** Does importing SY 2027 also advance
   the `current_school_year` constant, or is that a separate deliberate Admin action?
   Recommend separate — rolling the year over is a bigger act than loading a roster.
2. **English.** The department now exists (seeded 2026-09-04 with inferred
   `hours_per_section = 5`), but has no sections and appears nowhere in this roster
   document. If SY 2027's list includes English sections, the 36-total validation in
   §4 must become a configured expectation rather than a constant.
3. **Cross-source name conflicts.** The registrar writes `Vendiola`; the MAPEH
   plantilla writes `Vendola`. `TeacherResolver` currently reports these rather than
   merging. Import should surface them, not silently pick a spelling.
