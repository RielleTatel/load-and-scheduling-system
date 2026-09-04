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

## Parsing

The document is a clean 4-column table, but PDF extraction flattens its geometry:
grade blocks come out of order (the file prints GRADE 8 before GRADE 7), the Grade
Level Leader lines collapse together, and long names wrap mid-cell. So
`RosterExtractionService` parses on markers, never on reading order:

| Field | Anchor |
|---|---|
| Grade | the literal `GRADE n` block label |
| Section | begins `Saint` / `Blessed` |
| Moderator, teacher-partner | begins an honorific (`Ms. Mr. Mrs. Bb. Gng. Br. Sch.`) |
| Room | bare 3-digit number, terminates the row |
| Magis | literal `(Magis Class)` |

Grade Level Leader lines are ignored — the schema models GLL as an
`OtherAssignmentRole`, not a section field.

Two details worth keeping: a `, SJ` suffix is **kept** (it is part of the name, and
`Teacher::normalize()` drops it when matching anyway), and the signature-block
guard must allow hyphens — `Bb. JESSA JANE S. SUPILANAS-SEÑO` otherwise falls
through into the last section's row and pushes its room number off the end.

`RosterExtractionGoldenTest` asserts extraction reproduces the hand-verified
`SectionSeeder` data field for field. It is the correctness proof for the module;
if it fails, the extractor is wrong, not the seeder.

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
collisions, and the failure is quiet: a later year's row wins the name lookup and
load attaches to the wrong `section_id`.

## Import is refused when

- the roster is not exactly 36 sections, or a grade does not have exactly 9
- two sections share a short name
- a section has no room number

These mirror the invariants `SeedTest` asserts, enforced at import time.

## Teacher adoption

Moderators and teacher-partners are created without a department — only a
plantilla says which department someone belongs to. This deliberately does **not**
use `TeacherResolver`: called with a null department, that resolver skips its
adoption branch and falls through to `Teacher::create()`, forking anyone already
in the directory. `RosterReviewService::adopt()` uses the same normalized-name
check `RegistrarStaffSeeder` does.

## Open questions

- Importing a roster does **not** advance `current_school_year`; rolling the year
  over is a separate deliberate act.
- English is seeded as a department but appears in no roster document. If a future
  list includes English sections, the fixed "36" validation becomes a configured
  expectation.
- Cross-source spellings (`Vendiola` on the roster, `Vendola` on the MAPEH sheet)
  are reported by `TeacherResolver`, never silently merged.
