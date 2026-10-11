# Timetable Layout and Valid Generation Design

Historical design. Later confirmed changes, including Science/MAPEH labels, All days, resumable generation, refreshed approval checks, and special review schedules, are documented in [Scheduling Feature](../../Documentation/Scheduling%20Feature.md). Where they conflict, use that current reference; retain this document for design history.

**Date:** 2026-10-01  
**Status:** Approved design, awaiting written-spec review  
**Scope:** Normal timetable display, PDF/XLSX exports, and zero-hard-error generation

## Purpose

The scheduling feature must automate the school's existing normal timetable without replacing the structure staff already recognize from the SY 2026-2027 workbook. The on-screen timetable and downloads should feel like the supplied Excel/PDF schedule, while preserving the system's existing access rules, editing workflow, validation, approval, and unrelated features.

Generation must also become operationally useful. A generated version may become a draft only when it contains no hard validation errors. The current behavior of saving a complete but heavily conflicted candidate as a draft is not acceptable.

Special schedules and meeting scheduling remain outside this change.

## Reference Structure

The supplied workbook establishes the normal timetable's presentation:

- Each grade is a wide timetable whose section names run across the columns.
- The first column is labeled `TIME` and contains time ranges only.
- The section headings use `Grade - Section`, such as `7 - Arrowsmith`.
- Recess and lunch are explicit full-width rows.
- Examen, classroom clean-up, and remediation are separate full-width rows after the final subject period.
- Subject and teacher occupy separate lines within a section/time cell.
- Cycle days are called Day 1 through Day 5, not weekdays.

The application may use its existing typography and controls around the timetable, but the grid itself must preserve this structure and hierarchy.

## Website Timetable

### Filters

- Grade and cycle day are each selected through one dropdown in the existing filter form.
- The redundant row of Grade and Day shortcut buttons is removed.
- Existing Section, Department/Subject, Teacher, and Department Chair scope filters remain functional.
- Grade options are ordered: All Grades, Grade 7, Grade 8, Grade 9, Grade 10.
- Day options are ordered Day 1 through Day 5.

### Grade ordering and grouping

- Grade order is explicit and must never rely on lexical ordering of values such as `G10` and `G7`.
- A single-grade selection shows that grade's timetable.
- All Grades shows four separate full-width timetable blocks stacked vertically in this order:
  1. Grade 7 schedule
  2. Grade 8 schedule
  3. Grade 9 schedule
  4. Grade 10 schedule
- Blocks have a clear heading and vertical spacing/divider between grades. Sections from different grades are never mixed in one table.

### Grid structure

Each grade block contains:

1. A full-width `DAY N` banner.
2. A header row with `TIME` followed by `Grade - Section` columns.
3. Subject rows for these seven time ranges:
   - 7:30 - 8:20
   - 8:20 - 9:10
   - 9:50 - 10:40
   - 10:40 - 11:30
   - 11:30 - 12:20
   - 1:20 - 2:10
   - 2:10 - 3:00
4. A full-width `RECESS` row for 9:10 - 9:50, placed after the second subject row.
5. A full-width `LUNCH` row for 12:20 - 1:20, placed after the fifth subject row.
6. Three separate full-width rows after the seventh subject row:
   - 3:00 - 3:05 — EXAMEN
   - 3:05 - 3:20 — CLASSROOM CLEAN-UP (CC)
   - 3:20 - 4:10 — REMEDIATION, including that cycle day's subject label

The grid does not display `Period`, `Period 1`, `P1`, or similar period numbering. Period numbers remain internal identifiers used by editing and validation.

### Cell labels and editing

- The subject line uses the Department name rather than its shortened code.
- `CLE` and `TLE` remain abbreviated exactly as requested.
- Other subjects use their full display name, including English, Mathematics, Science and Technology, Filipino, Social Studies, and the expanded MAPEH department name configured by the system.
- The teacher name remains visible below the subject.
- Existing Admin click, drag/swap, automatic saving, undo, validation display, and approval rules remain intact.
- Hidden period IDs continue to drive edit actions even though period numbers are not presented in the grid.

### Visual treatment

- Header and banner text must meet readable contrast; dark backgrounds use white text.
- Subject cells use light backgrounds with dark text.
- Recess and lunch use a consistent contrasting break band inspired by the workbook.
- The three post-class rows align to the timetable columns and do not float beneath the table.
- The result remains horizontally scrollable on smaller screens.

## PDF Export

- PDF pages use the same `DAY N`, `TIME`, `Grade - Section`, break-row, subject-label, and post-class-row structure as the website.
- Every timetable page has readable header contrast.
- Recess and lunch appear as explicit merged rows.
- Examen, classroom clean-up, and remediation are drawn as three aligned table rows.
- Period numbers are not printed.
- Draft/approved marking remains visible without covering schedule content.
- Exports preserve selected filters.
- When several grades are exported, grade pages are ordered Grade 7 through Grade 10.
- Page layout remains landscape and must be visually inspected after rendering.

## Excel Export

- Grade tabs remain ordered Grade 7 through Grade 10.
- Each populated grade tab follows the same workbook-like structure and uses `TIME`, explicit break rows, full subject labels, and separate Examen/clean-up/remediation rows.
- Section headings use `Grade - Section`.
- Period numbering is omitted.
- Existing filters continue to determine which days, sections, departments, and teachers are included.

## Generation Correctness

### Hard constraints during search

The generator must coordinate all sections globally. It must treat these existing validator rules as hard constraints while constructing and repairing candidates:

- one subject per section/day/slot;
- required subject frequency per five-day cycle;
- required TLE, MAPEH, and Grade 10 English block patterns;
- assigned teacher for every scheduled subject;
- no teacher teaching more than one section in the same slot;
- no assignment during a configured teacher-unavailable slot;
- the existing teacher-break rule around recess/lunch and three consecutive subject periods.

The validator remains the final authority. The generator must not duplicate a different definition of schedule validity that can drift from validation behavior.

### Search approach

The existing locally valid per-section templates remain useful building blocks. Generation changes from greedy, section-by-section best effort to a global dependency-free constraint search:

1. Generate a diverse candidate pool of locally valid templates for each section.
2. Order section decisions by difficulty, prioritizing shared teachers and restricted availability.
3. Reject template placements that immediately violate teacher conflicts, unavailability, or teacher-break rules against already placed sections.
4. Use bounded backtracking and randomized restarts to revisit earlier section choices instead of accepting unavoidable downstream conflicts.
5. Run the authoritative validator on every complete candidate.
6. Return success only when the validator reports zero hard issues.

Search remains deterministic for the same seed and input data. The queue-job timeout remains bounded.

### Success and failure behavior

- A zero-hard-error result is saved as a new Draft.
- Only after that successful save are older Draft versions superseded, preserving the current versioning behavior.
- A complete candidate with one or more hard errors is not saved as a usable Draft.
- If the time/attempt limit is reached without a valid timetable, the generated version is marked Failed with a concise explanation that no zero-error timetable was found.
- Failure does not supersede or remove the previous Draft.
- Manual Admin edits may temporarily introduce hard errors and show them immediately, as already designed.
- Approval and publication remain blocked until all hard errors are resolved.

## New Draft and Existing Data

After the implementation and automated checks pass, generation will be run against the current 2026-2027 test data to produce a new version. The new version must satisfy all of the following before it is reported as ready:

- 1,260 timetable entries for 36 sections across 35 cycle slots;
- zero persisted hard validation issues;
- Grade 7 through Grade 10 presentation order;
- successful website rendering;
- successful PDF and Excel generation.

The existing conflicted Draft remains historical data unless the application's ordinary versioning rules supersede it after the valid Draft is created. No timetable is automatically approved or published.

## Testing and Verification

Automated coverage will include:

- explicit Grade 7-to-Grade 10 ordering, including All Grades;
- separate grade blocks in the web view;
- dropdown-only Grade and Day controls;
- time-only first-column labels;
- Recess and Lunch placement;
- aligned Examen, classroom clean-up, and remediation rows;
- full subject labels with CLE and TLE abbreviations;
- PDF text/structure and landscape output;
- XLSX grade-tab order and row labels;
- generator rejection of conflicted candidates;
- generator success only with zero hard issues;
- failed-generation behavior that preserves earlier Drafts;
- deterministic output for a fixed seed;
- existing access control, manual editing, validation, and approval tests.

Final verification includes the relevant Laravel test suite, the complete project test suite, browser inspection of single-grade and All Grades views, and rendered-image inspection of the exported PDF.

## Boundaries

This change does not:

- implement Special Schedules;
- implement Department, GLL, or PLT/PLE meeting scheduling;
- change plantilla import/review rules;
- change roles or account permissions;
- automatically approve or publish a generated timetable;
- remove the Admin's ability to create temporarily invalid manual edits;
- redesign unrelated pages or features.
