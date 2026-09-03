# Checkpoint — Class Moderator Hours Fix & Review-Screen Gaps

Date: 2026-09-03. Follows [[Extraction and Reference Data Checkpoint]] directly —
that checkpoint's "stated totals" gap turned out to have a root cause worth
recording on its own. **173 → 180 tests passing.**

---

## 1. The class moderator hours bug

The prior checkpoint read the Math sheet's ~1h-per-row discrepancy (stated
totals running above the computed formula) as registrar data-entry error. It
wasn't. Cross-checking all seven sheets against `LoadCalculationService`'s
formula showed one exact, consistent pattern: **every row with a class
moderator disagreed by exactly 1 hour; every row without one matched exactly.**

The system credits a class moderator `class_moderator_hours = 3` (a
`SystemConstant`, seeded). Every sheet's own arithmetic computes with **4** —
confirmed independently on Filipino, CLE, Math, Science, Social Studies, and
MAPEH (the last two print the literal digit `4` directly in the Class
Moderator cell, contradicting their own printed "(3 hours)" column header on
the same page). TLE showed no counter-evidence but couldn't be isolated as
cleanly.

This was never an extraction problem — sections and teachers were already
resolving correctly, which is exactly why the comparison was possible. It was
one wrong constant.

**Fixed:**
- `class_moderator_hours` updated `3 → 4` in the database (via
  `SystemConstant::update()`, audit-logged as `constant.updated`, same path
  the admin UI uses).
- `app/Console/Commands/BackfillClassModeratorHours.php` — `php artisan
  plantilla:backfill-cm-hours` — re-stamps existing `class_moderator_assignments`
  rows to the current constant. Idempotent, `--dry-run` supported, each change
  audit-logged as `class_moderator_assignment.hours_backfilled`.
  **Dry-run confirmed 32 affected rows. Not yet run for real** — holding until
  spot-checked or explicitly greenlit.

Open item carried forward: TLE books the moderator's hours as *non-teaching*
in its own layout, where every other department books it as *teaching*. Total
load is unaffected either way (same number, different bucket), but the
teaching/non-teaching split may be wrong for TLE specifically. Not
investigated further.

## 2. Review-screen gaps closed

Three of the six gaps from the prior checkpoint, all bounded fixes against
existing flows:

**Gap 1 — stated totals now captured.** The column layout after Sections/CM/HC
isn't consistent enough across sheets to split into named fields (some print
an Equivalent Hours figure, some don't, shifting positions) — attempting that
split was judged not worth the fragility. Instead, `PdfExtractionService::
statedTotals()` captures the full trailing numeric cluster verbatim (e.g.
`"20 3 3 23 0.67"`) into a new `stated_totals` field, threaded through
`UpdateExtractionRowRequest`, the blank-row template, and a hidden form field
so it survives row edits. Shown to the Chair as "Sheet states: …" per row.

**Gap 3 — extraction flags rendered.** `review.blade.php` now reads the
`flags` map `PdfExtractionService::finalize()` already wrote into `row_json`
(unresolved section, roster/sheet grade conflict, the Miki/registrar
question) and displays each message inline under the relevant field. The
"needs attention" count now keys off real flags, not just missing sections.
Also corrected a stale hint ("Grade/section columns don't survive PDF
extraction") left over from before `SectionResolver` existed.

**Gap 4 — returned submissions surfaced.** Root cause:
`SubmissionStatus::isEditable()` returns true for `Returned`, so
`chair/submission/show.blade.php` fell into the same branch as a fresh draft
— `returned_comment` was written to the database but never reached the Chair.
Added `PlantillaSubmission::returnedBy()` and a banner shown when
`status === Returned`, naming who returned it and their comment, above the
normal submit flow.

## 3. Held, per explicit decision

- **Gap 2 (FR-10 stated-vs-computed comparison)** — held. The one concrete
  case driving this (Math's discrepancy) was the CM-hours bug, now fixed at
  the config level; no standing comparison feature was judged necessary on
  top of that for now. `stated_totals` (gap 1) exists as raw reference data if
  this is revisited later.
- **Gap 5 (teacher-partner carries no load)** — held, blocked on a
  requirements decision, not engineering.
- **Gap 6 (`sections.room` inert)** — no action; correctly staged for the
  future Scheduling Engine.

## 4. Framing correction carried into the prior checkpoint

[[Extraction and Reference Data Checkpoint]] was edited in place (not this
doc) to reflect: admin-owned reference data is not an incidental discovery —
SRS §6.2 already recommended a structured admin form as the primary encoding
path, with PDF upload as convenience import over it. Likewise, a missing
department plantilla (English) is expected recurring data-completeness
churn, not a design defect — rosters and moderators change yearly by nature.

## 5. How to re-verify

```bash
php artisan test                          # 180 tests
php artisan plantilla:backfill-cm-hours --dry-run   # should report 32, until run for real
```
