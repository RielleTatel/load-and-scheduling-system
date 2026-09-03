# Checkpoint — Plantilla Extraction & Registrar Reference Data

Date: 2026-09-03. Covers the work done after the admin-chair milestone
([[2026-08-07-admin-chair-milestone-design|milestone spec]]) and records what is
still missing. Related: [[Plantilla Extraction Blocker]], [[JHS Sections Directory]],
[[JHS System SRS]], [[JHS Scheduling Constraints]].

Commits: `a4704f3`, `cb6cf64`, `6ce980e` on `main`. **170 tests passing.**

---

## 1. What changed, and why

The trigger was a new source document: the registrar's **List of Class Mods and
Teacher-Partners 2026** (`docs/`). It is the authoritative roster — 36 sections,
nine per grade, each with its room, class moderator and teacher-partner.

Two things followed from it.

**The extraction blocker was based on a false premise.** [[Plantilla Extraction Blocker]]
had concluded that section names repeat so pervasively across grades that a
name→grade lookup could not recover a section's grade, and deferred the work. In
fact **no section name is reused across grades**, so the lookup is exact. The
blocker's evidence was drawn from [[JHS Sections Directory]], which was itself the
output of the broken attribution — it inferred "names collide" from data corrupted
by the bug it was diagnosing. The TLE sheet embeds grades inline (`7Rubio`,
`8Loyola`, `10Colombiere`) and agrees with the registrar on all 36, disproving it
independently. The one token that genuinely repeats — `Magis` — is not a section
name but a modifier on three sections.

**This was never an OCR problem.** The PDFs are Word exports with an embedded text
layer; no character was ever misread. What was lost is 2D table geometry — a token
read perfectly but no longer under its column header. The spelling variants
(`De Brito`, `Anchietta`, `Colombierre`) are human typos in the source, not
extraction noise.

**The deeper issue was architectural, and the SRS had already called it.** The
plantilla had become the sole source for data it cannot carry — a section's grade,
and who moderates it. SRS §6.2 doesn't just permit an admin-owned reference layer,
it recommends it directly: *"a structured web form... should be the primary
encoding path, with PDF upload as a convenience import that pre-fills the form for
review rather than the sole entry method."* That was written before any of this
work started. There was no admin screen for sections or teachers, so that reference
data had nowhere to live — this milestone is that recommendation being built, not
a side-effect discovered while fixing the extraction blocker. Admin-owned
sections/teachers is the foundation; PDF extraction is the convenience layer
reconciling *against* it, per design, not the other way around.

## 2. What now works

### Reference data (registrar-sourced, admin-owned) — core, not incidental

Per SRS §6.2, this is the primary encoding path the spec called for, not a
byproduct of unblocking extraction. Sections and teachers change every school
year — new rosters, reassigned moderators, sheets that arrive late or not at all —
so admin ownership of this data, independent of any single PDF, is what makes the
system tolerate that churn instead of re-breaking on it annually.

- **36 canonical sections** with grade, room, official name, Magis flag, moderator
  and teacher-partner. Replaces 85 seeded rows, of which 49 were the same section
  filed under a wrong grade. `SeedTest` asserts nine per grade and school-wide name
  uniqueness, so a future year that breaks the assumption fails the build rather
  than silently misfiling.
- **Admin → Reference data**: Section Roster and Teacher Directory, both editable.
  Chairs remain scoped to their own department (SRS §3.2); RBAC verified.
- **73 registrar-named staff** seeded without a department, adopted when their
  sheet is imported.

### Extraction

`PdfExtractionService` now fills all seven staging fields (was two). It parses by
marker rather than column geometry, and resolves every section name against the
roster via `SectionResolver`, which:

- strips embedded grade prefixes and cross-checks them against the roster;
- rejoins names wrapped mid-line (`Ignatius of` / `Loyola`, and `Ignatius` /
  `of Loyola`);
- applies an explicit alias table for known misspellings;
- fuzzy-matches at **one edit only** — `Regis`/`Lewis` and `Faber`/`Mayer` are two
  edits apart, so a looser bound could land on a different real section;
- never invents a section.

Rejoining must run before fuzzy matching: a bare `Ignatius` is four edits from
`Canisius`, and a bare `Loyola` four from `Borgia`.

### Import

- `Section::firstOrCreate` is gone — that call is how 85 sections came to exist in
  a 36-section school. Unknown names are skipped with a reason.
- `TeacherResolver` gives teacher identity a stable key. Re-importing a corrected
  sheet used to fork the teacher and migrate their load onto the new row.
- Moderators come from the roster, not the sheets. Four of seven sheets never
  record one and Math records only a count, so marker parsing recovered 8 of 36.
- A missing employment status no longer discards the row — no Social Studies row
  states one, which was rejecting that whole department.
- `honors_class_assignments` gained `unique(section_id, school_year)`, matching
  what `class_moderator_assignments` always had.

### End-to-end result

Importing the **seven available sheets** (English outstanding) against a clean
database:

| | |
|---|---|
| Teacher rows extracted | 75 of 75 imported |
| Teachers in directory | 87 (75 with a department, 12 awaiting a plantilla) |
| Section-teacher assignments | 242 |
| Class moderators assigned | **32 / 36** (was 8) |
| Honor's class assignments | 3 |
| Duplicate teacher names | 0 |
| Sections outside the roster | 0 |
| Items needing Chair attention | 23 |

Section coverage per department, of 36: Filipino 36, TLE 36, CLE 35, Social Studies
35, Mathematics 34, Science 34, MAPEH 32.

Unmatched Other-Assignment roles fell from 27 to 6.

## 3. The missing English plantilla

Still outstanding — and this is a data-completeness state, not a design or code
issue. A sheet arriving late, or a roster changing next year, is exactly the kind
of churn the admin-owned reference layer (§2) exists to absorb: it is expected
behavior, recurring by nature, not a one-time defect to close out. The design
already accommodates it and **no rework is expected**:

- The four sections without a moderator — G8 Pignatelli, G8 Xavier, G9 Anchieta,
  G10 Berchmans — are moderated by Romanggar, Bernabe, Singson and Jolapong, all
  already in the directory awaiting their sheet.
- Those 12 awaiting-plantilla teachers are exactly the people the registrar names
  who appear in no current sheet. `TeacherResolver` will adopt them rather than
  duplicate them.
- The Teacher Directory's "awaiting a plantilla" count is the live tracker.

This also confirms the English department gap flagged in
[[JHS Scheduling Constraints]] §7.8.

---

## 4. Gaps — still missing

### In scope for the current milestone

| Gap | Detail |
|---|---|
| **Stated totals are never extracted** | FR-4 requires "computed totals as stated on the sheet". The extractor returns seven fields, none of them the sheet's stated Total Teaching Hours, Total Number of Hours or Overload. `UpdateExtractionRowRequest` accepts the same seven, so there is nowhere to put them. |
| **FR-10 cannot be satisfied** | FR-10 requires flagging rows where stated Total Teaching Hours disagrees with the computed figure. `LoadCalculationService` only flags against the 21h baseline (`zero_sections`, `no_service_load`, `overloaded`, `below_full_load`) — it never compares to the sheet, because that number is discarded. Concretely: [[JHS Scheduling Constraints]] §5 records the Math sheet's stated totals running ~1h above the formula on nearly every row. That is exactly what FR-10 exists to surface, and it is currently invisible. |
| **Per-field extraction flags are not rendered** | The extractor writes a `flags` map into `row_json` — which section could not be resolved and why, the Miki/registrar question, roster-vs-sheet grade conflicts. `resources/views/chair/plantilla/review.blade.php` does not read it; it still uses the older "row flagged, or sections empty" heuristic. The data exists and the Chair cannot see it. Cheapest gap to close. |
| **Returned Submissions (chair side)** | `plantilla_submissions.returned_comment` and `SubmissionStatus::Returned` both exist; no Chair page reads either. SRS §8.3 lists this as a Chair screen (FR-11). The milestone spec defers the *Coordinator's* return action, so this is a half-gap rather than a clean deferral. |

### New, arising from the registrar document

| Gap | Detail |
|---|---|
| **Teacher-partner is a name, not an assignment** | Every section has a second adviser. Stored as `sections.teacher_partner_name`, a string — not a relationship, and carrying no hours. It appears in no SRS requirement because the SRS predates this document. **Undecided: does a teacher-partner carry load?** |
| **`sections.room` is inert** | Populated for all 36, read by nothing. It is the input the §7.5 shared-facility constraint will need (one TLE lab and one MAPEH facility school-wide), so it is correctly staged for the Scheduling Engine — just unused today. |

### Correctly deferred (milestone spec)

Academic Coordinator in full (SRS §8.4 — master directories, workload analytics,
cross-department data-quality flags, return-submission), the Scheduling Engine,
report generation, and period-level timetabling.

---

## 5. Open questions for the registrar

These are flagged in-system and cannot be resolved from the documents we hold.

1. **`Miki` / `Paul` → `Rubio`?** Saint Paul Miki appears in the CLE, MAPEH, Science
   and Math sheets but is absent from the 2026 roster. The sheets naming Miki never
   name Rubio and vice versa, and in CLE the G7 sections are fully covered except
   Rubio, whose slot Miki occupies. Deliberately **not** aliased — confirming it is a
   one-line move from `PENDING_REGISTRAR` into `ALIASES` in `SectionResolver`.
2. **Duplicate Honor's Class rows.** The Science sheet names two teachers against G8
   Magis (Magasa, Abduraja) and two against G10 Magis (Sienes, Calumpang); MAPEH adds
   more. There are only three Magis sections. The importer now reports the clash
   instead of writing both.
3. **`Gia Nicole Japalong` vs `Gia Nicole F. Jolapong`** — three edits apart.
   Plausibly one person, not certain enough to merge two staff records
   automatically, so it is reported.
4. **11 Social Studies rows have no employment status** — that sheet states none at
   all. Rows import; the Chair must set the field before submitting.
5. **Six Other-Assignment roles are not in the lookup** — `YASED`, `Boy Scout`,
   `SOCMAT` and similar. The designed path is for the Admin to add them, then
   re-import.

---

## 6. How to re-verify

```bash
php artisan migrate:fresh --seed   # 36 sections, 73 registrar staff
php artisan test                   # 170 tests
```

Fixtures for all seven available sheets live in `tests/Fixtures/`.
`PdfExtractionServiceTest` asserts per-sheet behaviour plus two invariants: no
sheet ever yields a section outside the 36, and every extracted Service Load is a
plausible value. `SectionResolverTest` and `TeacherResolverTest` cover the alias,
wrap, prefix-conflict, stopword, near-miss and refusal paths — including the seven
real name-collision pairs found during end-to-end import.
