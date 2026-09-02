# Plantilla Extraction — Grade Attribution & Format Variance

Status: **Resolved, 2026-09-02.** Originally raised 2026-08-08 as an open blocker.
Related: [[JHS System SRS]] §6.2, [[JHS Sections Directory]], [[JHS Department Directory]].
Code: `app/Services/Plantilla/SectionResolver.php`, `app/Services/Plantilla/PdfExtractionService.php`,
`app/Services/Plantilla/PlantillaReviewService.php`, `database/seeders/SectionSeeder.php`.

---

## 1. What the blocker was

The Review & Correct screen extracted only **Teacher name** and **Employment status**. Sections,
Class Moderator, Honor's Class, Service Load and Other Assignment all came through blank.

The original diagnosis split this in two:

1. *A false limitation (~80%)* — those fields survive text extraction and were simply never read.
2. *A real limitation (~20%)* — which grade column a section sits under is lost when the table is
   flattened, "and section names repeat so pervasively across grades that a name→grade lookup
   cannot recover it. **This is the actual blocker.**"

**Claim 1 was correct. Claim 2 was wrong**, and it was the claim that caused the work to be deferred.

## 2. Why claim 2 was wrong

The registrar's *List of Class Mods and Teacher-Partners 2026* gives the authoritative roster:
**36 sections, nine per grade, and no name reused across grades.**

That is confirmed by a second, independent source. The TLE sheet embeds the grade in the section
name, and extracting those prefixes reproduces the registrar's mapping exactly, 36 for 36:

```
7Arrowsmith 7Bellarmine 7Campion 7Claver 7De(Britto) 7Jogues 7Pongracz 7Regis 7Rubio
8Borgia 8Briant 8Goupil 8Lewis 8Loyola 8Ogilvie 8Pignatelli 8Realino 8Xavier
9Anchieta 9Brebeuf 9Evans 9Garnet 9Jerome 9Kostka 9Morse 9Owen 9Rodriguez
10Berchmans 10Canisius 10Chabanel 10Colombiere 10Daniel 10Faber 10Hurtado 10Mayer 10Southwell
```

The old §6 claimed 37 of 43 names (86%) repeated across grades. That table was built entirely from
[[JHS Sections Directory]] — which was itself the *output* of the broken attribution. It inferred
"names collide" from data corrupted by the very bug it was diagnosing. Its hedge that "any
correction can only *add* collisions, never remove them" is backwards: the correction removes all
of them.

Its one directory-independent argument — the Science sheet tagging `Magis` as G8, G9 and G10 —
does not hold either. **`Magis` is not a section name.** It is a modifier on three distinct
sections (Ignatius of Loyola G8, Kostka G9, Faber G10), none of which collide. Reading it as a name
is what made the collision look real, and `SectionResolver` now treats it as a stopword for exactly
that reason.

### It was never an OCR problem

`Smalot\PdfParser` reads the PDFs' **embedded text layer** — these are Word exports, and no
character was ever misread. What was lost is 2D table geometry: a token read perfectly, but no
longer under its column header. The spelling variants below (`De Brito`, `Anchietta`) are human
typos in the source documents, not extraction noise.

## 3. The fix

Grade is no longer read from the sheet at all. It comes from the roster, because names are unique.

- **`SectionSeeder`** holds the canonical 36 sections with grade, room, official name and Magis flag.
  `SeedTest` asserts nine per grade and school-wide name uniqueness — if a future year reuses a
  name, the build fails loudly and this strategy must be revisited rather than silently misfiling.
- **`SectionResolver`** maps a name as written to a canonical `Section`: strips embedded grade
  prefixes, rejoins names wrapped mid-line (`Ignatius of` / `Loyola`), applies an explicit alias
  table, and falls back to a fuzzy match. It **never invents a section**.
- **`PdfExtractionService`** parses by marker rather than geometry — section names are recognised
  against the roster, and the Class Moderator and Honor's Class cells by the keywords introducing
  them. This absorbs the format variance in §4 below.
- **`PlantillaReviewService`** no longer calls `Section::firstOrCreate()`. Unknown names are
  skipped with a reason. That call is how 85 sections came to exist in a 36-section school.

### Fuzzy matching is bounded at one edit

Two canonical names are only two edits apart (`Regis`/`Lewis`, `Faber`/`Mayer`), so a 2-edit
threshold could silently land on a different real section. Auto-accept requires distance ≤ 1, a
unique winner, and a margin of ≥ 2 to the runner-up. All four known variants (`De Brito`,
`Anchietta`, `Colombierre`, `Berchman`) sit at distance 1 with a margin of ≥ 4.

Rejoining wrapped names must happen **before** fuzzy matching: a bare `Ignatius` is 4 edits from
`Canisius` with a margin of 1, and a bare `Loyola` is 4 from `Borgia`. Reversing that order moves a
G8 section into G10.

A near miss (distance ≤ 2, ≥ 6 characters) is reported to the Chair as "Did you mean …?" rather
than accepted or dropped — this is how Social Studies' `De Brtio` surfaces.

## 4. Format variance across the seven sheets — still accurate

| Sheet | Section-list style | Grade in text? | Honor's col | Status format |
|---|---|---|---|---|
| **Filipino** | count + parens: `1 (Ignatius)` | ❌ column only | no | `(Permanent)` |
| **CLE** | count + newline names | ❌ column only | no | `(Retiree)`, `(FT Permanent)` |
| **Math** | count + newline names | ❌ column only | yes (header) | `(FT Probationary 1)` |
| **Science** | `3 sections` + newline names | ✅ honors `G8 Magis` | yes | `(FT Permanent)` |
| **MAPEH** | newline names, no count | ❌ column only | yes `(Magis)` | `(Permanent Teacher)` + `(MAPEH)` |
| **Social Studies** | count + names, sometimes **no status line** | ❌ column only | no | often **absent** |
| **TLE** | grade-prefixed: `5 (10Colombiere, …)` | ✅ **from text** | no | `(Permanent Teacher)` |

How each is handled now:

- **Five different count markers** (`1 (Ignatius)`, `5`, `1 section`, `3 sections`,
  `1 moderating class`, none) — irrelevant. Counts are dropped; names are recognised against the
  roster wherever they appear.
- **Four Class Moderator formats** — matched on `Class Moderator` / `moderating class`, which
  deliberately excludes club roles like `Sports Club Moderator` and `Punlaan Moderator`.
- **Missing status parentheticals** — the name is the leading run of lines before the status,
  section list or numeric cells. It is no longer "text before the first `(`", which used to
  swallow whole rows on Social Studies.
- **Other Assignment interleaved with the numeric cluster** — Service Load is the number
  immediately preceding the assignment text, anchored on the text rather than a column index
  because the sheets differ in column count (CLE leads with an Honor's column).

## 5. Class Moderators come from the roster, not the sheets

Surveying the Class Moderator column across all seven sheets:

| Sheet | Moderator cell | Recoverable from flat text? |
|---|---|---|
| Filipino | `G7 Class Moderator (Rubio)` | ✅ names the section |
| MAPEH | `G10 Class Moderator / Southwell` | ✅ names the section |
| Science | `Grade 8 / Magis Class / Moderator` | ⚠️ grade only; resolvable for Magis |
| TLE | bare `10Canisius`, no keyword | ❌ needs column geometry |
| Math | `1 moderating class` | ❌ a count; the section is never named |
| CLE | `0` | ❌ column is empty |
| Social Studies | blank | ❌ column is empty |

**For four of seven sheets the moderator is simply not in the document.** Marker parsing recovered
only 8 of 36. TLE is the one case geometry would help with — it puts a bare section name in the
column — but even a coordinate-aware parser leaves CLE, Math and Social Studies empty.

The registrar's list names all 36 moderators and their teacher-partners, so `sections` carries
`moderator_name` and `teacher_partner_name`, and `PlantillaReviewService` assigns the moderator by
matching an imported teacher against the roster. The sheet's own cell is kept only as a
cross-check: where it disagrees with the roster, the roster wins and the conflict is reported.

Name forms differ between the sheets and the roster (`Marycris Asdali` / `Mary Cris Asdali`,
`Fritzie Dealagdon` / `Frizie B. Dealagdon`, `SCH. JAMES RYAN C. SENERICHES, SJ`), so matching
compares given names and surname with a one-edit tolerance after dropping honorifics and middle
initials — never surname alone, which would confuse Cristie R. Delos Reyes (G7 Rubio) with
Ivy Q. Delos Reyes (G10 Southwell).

Importing all seven sheets now yields **75 teachers, 242 section assignments and 32 of 36
moderators**. The four outstanding — Pignatelli, Xavier, Anchieta, Berchmans — are moderated by
Romanggar, Bernabe, Singson and Jolapong, none of whom appear in any plantilla. They are among the
twelve people on the registrar's list with no plantilla row, which is further evidence of the
missing English department noted in [[JHS Scheduling Constraints]] §7.8.

## 6. Known source-data conflicts this surfaces

Now that extraction works, the sheets' own inconsistencies are visible:

- **`Miki` / `Paul`** appear in CLE, MAPEH, Science and Math but are absent from the 2026 roster.
  Evidence points to Saint Paul Miki having been renamed **G7 Rubio**: the sheets that name Miki
  never name Rubio and vice versa, and in CLE the G7 sections are fully covered except Rubio, whose
  slot Miki occupies. **Deliberately not aliased** — the resolver flags it for the registrar.
  Confirming it is a one-line move from `PENDING_REGISTRAR` into `ALIASES`.
- **Duplicate Honor's Class rows.** The Science sheet names *two* teachers against G8 Magis
  (Magasa and Abduraja) and two against G10 Magis (Sienes and Calumpang). There are only three
  Magis sections. `honors_class_assignments` now carries a `unique(section_id, school_year)`
  constraint — matching what `class_moderator_assignments` always had — and the importer reports
  the clash instead of writing both.

## 7. Verification

`tests/Fixtures/` holds all seven sheets. `PdfExtractionServiceTest` asserts per-sheet behaviour
plus two invariants: no sheet ever yields a section outside the 36, and every extracted Service
Load is a plausible value. `SectionResolverTest` covers the alias, wrap, prefix-conflict,
stopword, near-miss and refusal paths.

Current extraction: **75 teacher rows across seven sheets, 72 carrying at least one section**, with
three rows flagged — all three the genuine `Miki`/`Paul` registrar question.

Other fixes made while auditing against the registrar roster:

- A missing employment status no longer discards the row. No Social Studies row states one, so all
  eleven were previously rejected outright, losing the whole department's load.
- `Probationary II` (roman numerals, MAPEH) is now understood.
- A club role wrapped as `Punlaan` / `Moderator` is rejoined before the role lookup; `None` is
  treated as an empty cell rather than a role name; `TLE Coordinator` keeps its prefix.
- The signatory block no longer leaks into the last row of a sheet (the honorifics are written
  inconsistently — `BB.` and `Bb.`).
- Only a *lowercase* preposition continues a wrapped name: `of Loyola` does, `De Britto` does not.
