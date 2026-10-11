# Timetable Layout and Valid Generation Implementation Plan

Historical implementation plan. Later confirmed corrections and special review scheduling are captured in [Scheduling Feature](../../Documentation/Scheduling%20Feature.md) and the [October 2 implementation plan](2026-10-02-approval-and-special-scheduling.md). Do not treat the older display-name and search-deadline descriptions as current requirements.

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the normal timetable match the supplied workbook's structure across web/PDF/XLSX and allow generation to save a Draft only when it has zero hard validation errors.

**Architecture:** Centralize grade ordering, schedule rows, time labels, remediation labels, and subject display names in a shared layout service used by presentation and exports. Replace the generator's greedy best-effort selection with seeded, bounded global template search and retain the existing validator as the final authority. The queue job persists and supersedes Drafts only after a zero-hard-error result.

**Tech Stack:** PHP 8.3+, Laravel 13, Blade, Alpine.js, PHPUnit 12, existing dependency-free PDF/OpenXML writers, MySQL production database and SQLite test database.

**Spec:** `docs/superpowers/specs/2026-10-01-timetable-layout-and-generation-correction-design.md`

## Global Constraints

- Preserve existing roles, permissions, plantilla workflows, editing, undo, validation, approval, and unrelated features.
- Cycle days remain Day 1 through Day 5; do not introduce weekday names.
- The visible timetable uses time ranges only and never presents Period/P1 labels.
- CLE and TLE remain abbreviated; every other subject uses its full display name.
- Grade order is always Grade 7, Grade 8, Grade 9, Grade 10.
- Special schedules and meeting scheduling remain out of scope.
- Do not add a runtime dependency or external solver.
- A generated Draft must have zero hard validation issues; otherwise generation fails without superseding an earlier Draft.
- Manual Admin edits may temporarily contain hard errors, but approval remains blocked.

## Review Focus

- All Grades with a Section filter must show only the selected section inside its owning grade block, not empty blocks for the other grades; covered in Task 2.
- Department or Teacher filters can leave cells empty and must preserve break/post-class rows and table alignment; covered in Tasks 2 and 3.
- A section with no feasible template under teacher availability must end as Failed without inserting entries or superseding an existing Draft; covered in Task 5.
- Seeded search must be deterministic even when candidate template pools contain duplicates; covered in Task 4.
- Long subject and teacher names must wrap inside PDF cells without clipping or crossing borders; covered by export assertions and rendered PDF inspection in Tasks 3 and 6.

---

### Task 1: Shared Timetable Layout Model

**Files:**
- Create: `app/Services/Scheduling/TimetableLayout.php`
- Modify: `app/Services/Scheduling/TimetablePresentationService.php`
- Modify: `app/Http/Controllers/Scheduling/TimetableController.php`
- Create: `tests/Unit/TimetableLayoutTest.php`
- Modify: `tests/Feature/Scheduling/TimetableAccessTest.php`

**Interfaces:**
- Consumes: `Section` collections, `Department` code/name values, selected cycle day.
- Produces: `TimetableLayout::gradeOrder(): array`, `TimetableLayout::rows(int $day): array`, `TimetableLayout::subjectName(string $code, ?string $fallback = null): string`, and `TimetableLayout::groupSections(Collection $sections): array<string, Collection>`.
- `TimetablePresentationService::prepare()` adds `gradeGroups` and `scheduleRows` while retaining existing `sections`, `grid`, filters, and editing data.

- [ ] **Step 1: Write failing unit tests for canonical labels and row order**

Assert literal grade order `['G7', 'G8', 'G9', 'G10']`; time-only subject rows; Recess after period 2; Lunch after period 5; three post-class rows; and exact display names including `CLE`, `TLE`, `Science and Technology`, and `Music, Arts, Physical Education and Health`.

- [ ] **Step 2: Write a failing feature test for database-independent grade ordering**

Create G10 before G7 in the fixture, call the timetable page/presentation boundary, and assert Grade 7 occurs before Grade 10 in returned groups/options.

- [ ] **Step 3: Run the focused tests and confirm RED**

Run: `php artisan test tests/Unit/TimetableLayoutTest.php tests/Feature/Scheduling/TimetableAccessTest.php`

Expected: failure because `TimetableLayout` and the grouped/row presentation data do not exist.

- [ ] **Step 4: Implement the shared layout model and presentation wiring**

Use row records with exact keys `type`, `time`, `label`, and nullable `period`; types are `subject`, `break`, and `post_class`. Sort collections by the numeric grade rank and section name in PHP so behavior is identical on MySQL and SQLite.

- [ ] **Step 5: Run the focused tests and confirm GREEN**

Run: `php artisan test tests/Unit/TimetableLayoutTest.php tests/Feature/Scheduling/TimetableAccessTest.php`

Expected: all selected tests pass.

- [ ] **Step 6: Commit Task 1**

Commit only Task 1 files with message `feat: centralize timetable layout semantics`.

---

### Task 2: Workbook-Structured Website Timetable

**Files:**
- Modify: `resources/views/scheduling/show.blade.php`
- Modify: `tests/Feature/Scheduling/TimetableAccessTest.php`
- Modify: `tests/Feature/Scheduling/TimetableEditingTest.php`

**Interfaces:**
- Consumes: Task 1 `gradeGroups`, `scheduleRows`, `grid`, and existing edit route/template data.
- Produces: one grade table per non-empty grade group with hidden internal day/period IDs preserved for Admin editing.

- [ ] **Step 1: Write failing response assertions for the workbook structure**

Assert the page contains `DAY 1`, `TIME`, `7 - Arrowsmith`, `RECESS`, `LUNCH`, `EXAMEN`, `CLASSROOM CLEAN-UP (CC)`, and the Day 1 remediation label. Assert it does not contain `Period / time`, `Period 1`, or a duplicate quick-filter link group.

- [ ] **Step 2: Write failing All Grades and filtered-empty-cell tests**

Assert grade headings occur in G7/G8/G9/G10 order as separate table blocks; a selected Section produces only its owning block; Department/Teacher filtering keeps break and post-class rows even when subject cells are empty.

- [ ] **Step 3: Run the focused feature tests and confirm RED**

Run: `php artisan test tests/Feature/Scheduling/TimetableAccessTest.php tests/Feature/Scheduling/TimetableEditingTest.php`

Expected: failures on current mixed-grade table, button filters, period labels, and missing break rows.

- [ ] **Step 4: Replace the single table with per-grade layout loops**

Render dropdown-only Grade/Day controls, a day banner, time-only rows, merged break/post-class rows, `Grade - Section` headers, full subject names, and readable workbook-like bands. Keep edit/swap/drag attributes only on `subject` rows and pass the row's internal `period` value to existing JavaScript.

- [ ] **Step 5: Run frontend build and focused tests**

Run: `npm run build`

Run: `php artisan test tests/Feature/Scheduling/TimetableAccessTest.php tests/Feature/Scheduling/TimetableEditingTest.php`

Expected: build succeeds and all selected tests pass.

- [ ] **Step 6: Commit Task 2**

Commit only Task 2 files with message `feat: match timetable page to workbook layout`.

---

### Task 3: Workbook-Structured PDF and Excel Exports

**Files:**
- Modify: `app/Services/Scheduling/TimetableExportService.php`
- Modify: `tests/Unit/TimetableExportServiceTest.php`
- Modify: `tests/Feature/Scheduling/TimetableAccessTest.php`

**Interfaces:**
- Consumes: Task 1 `TimetableLayout` and presentation payload.
- Produces: `TimetableExportService::pdf(TimetableVersion $timetable, array $data): string` and `xlsx(...): string` with unchanged public signatures and workbook-like content.

- [ ] **Step 1: Write failing PDF structure tests**

Assert PDF source contains `TIME`, `7 - Ignatius`, `RECESS`, `LUNCH`, `EXAMEN`, `CLASSROOM CLEAN-UP (CC)`, `REMEDIATION`, and `English`; assert it omits `PERIOD` and `P1`. Add a long MAPEH label/teacher fixture and assert all text is present in the PDF stream.

- [ ] **Step 2: Write failing XLSX structure and ordering tests**

Inspect workbook/sheet XML and assert Grade 7 through Grade 10 tab order, `TIME`, grade-prefixed section headings, explicit break/post-class rows, full subject labels, merged ranges for full-width rows, and absence of `Period / time`/`P1`.

- [ ] **Step 3: Run export tests and confirm RED**

Run: `php artisan test tests/Unit/TimetableExportServiceTest.php`

Expected: failures on period labels, absent breaks, unprefixed headings, abbreviations, and footer alignment.

- [ ] **Step 4: Implement shared-row export rendering**

Inject `TimetableLayout`; render the same row sequence in both formats. In PDF, draw every break/post-class item as a bordered full-width row and wrap subject/teacher text to measured line groups within each cell; use white text on dark fills. In XLSX, emit merged rows, workbook-like fills, centered labels, wrapped cell text, and explicit column widths without changing the dependency-free ZIP writer.

- [ ] **Step 5: Run export and access tests and confirm GREEN**

Run: `php artisan test tests/Unit/TimetableExportServiceTest.php tests/Feature/Scheduling/TimetableAccessTest.php`

Expected: all selected tests pass.

- [ ] **Step 6: Commit Task 3**

Commit only Task 3 files with message `feat: align timetable exports with workbook`.

---

### Task 4: Zero-Hard-Error Global Schedule Search

**Files:**
- Create: `app/Services/Scheduling/ScheduleSearchExhaustedException.php`
- Modify: `app/Services/Scheduling/ScheduleGenerationEngine.php`
- Modify: `tests/Unit/ScheduleGenerationEngineTest.php`
- Modify: `tests/Unit/ScheduleConstraintValidatorTest.php`

**Interfaces:**
- Consumes: existing section assignment arrays, teacher unavailability rows, seed, deadline seconds, `ScheduleTemplateGenerator`, and `ScheduleConstraintValidator`.
- Produces: unchanged successful `generate(...): array{entries, issues, score, attempts}` with `issues === []`; throws `ScheduleSearchExhaustedException` when no zero-hard-error candidate is found within bounds.

- [ ] **Step 1: Write failing tests for strict success and exhausted search**

Use a feasible multi-section fixture and assert 70 complete entries with `issues === []`. Use an intentionally impossible fixture (same teacher required for two sections for all subjects) and assert `ScheduleSearchExhaustedException`, not a conflicted result.

- [ ] **Step 2: Write failing determinism and availability tests**

Assert identical seed/input returns identical entries after duplicate candidate removal; assert a feasible fixture with unavailable slots returns no `teacher_unavailable`, `teacher_conflict`, or `teacher_break` issues.

- [ ] **Step 3: Run generator tests and confirm RED**

Run: `php artisan test tests/Unit/ScheduleGenerationEngineTest.php tests/Unit/ScheduleConstraintValidatorTest.php`

Expected: current best-effort engine returns hard issues instead of rejecting them.

- [ ] **Step 4: Implement bounded seeded global search**

Generate unique template pools per grade/section, rank sections by shared-teacher pressure and unavailable slots, apply minimum-remaining-values selection, and perform depth-first placement with bounded backtracking/random restarts. Reject any placement that introduces a teacher conflict, unavailable slot, or monotonic break-rule violation. Validate each complete candidate with `ScheduleConstraintValidator`; return only on zero hard issues and throw the dedicated exception on deadline/exhaustion.

- [ ] **Step 5: Run generator tests and confirm GREEN**

Run: `php artisan test tests/Unit/ScheduleGenerationEngineTest.php tests/Unit/ScheduleConstraintValidatorTest.php tests/Unit/ScheduleTemplateGeneratorTest.php`

Expected: all selected tests pass with deterministic zero-issue feasible results and explicit impossible-result failure.

- [ ] **Step 6: Commit Task 4**

Commit only Task 4 files with message `feat: require conflict-free schedule generation`.

---

### Task 5: Persist Only Valid Generated Drafts

**Files:**
- Modify: `app/Jobs/GenerateTimetable.php`
- Modify: `tests/Feature/Scheduling/GenerateTimetableTest.php`

**Interfaces:**
- Consumes: Task 4 successful result or `ScheduleSearchExhaustedException`.
- Produces: zero-issue Draft on success; Failed version with no entries and preserved earlier Draft on search exhaustion.

- [ ] **Step 1: Write failing successful-generation test**

Dispatch the job against a small feasible assignment fixture and assert entries are inserted, authoritative validation persists zero hard issues, the generated version becomes Draft, and only then is an earlier Draft superseded.

- [ ] **Step 2: Write failing preservation test**

Create an earlier Draft, force search exhaustion, and assert the new version is Failed, has zero entries, contains a concise failure message, and the earlier version remains Draft.

- [ ] **Step 3: Run the job feature test and confirm RED**

Run: `php artisan test tests/Feature/Scheduling/GenerateTimetableTest.php`

Expected: current job saves the best conflicted candidate as Draft.

- [ ] **Step 4: Add the transaction-level validity gate**

Before insertion, require the engine result's issues to be empty; after insertion, require authoritative validation to return no hard issues before changing status/superseding older Drafts. Let any violation roll back and follow the existing Failed/audit path.

- [ ] **Step 5: Run scheduling feature tests and confirm GREEN**

Run: `php artisan test tests/Feature/Scheduling`

Expected: all scheduling feature tests pass.

- [ ] **Step 6: Commit Task 5**

Commit only Task 5 files with message `fix: persist only valid generated timetables`.

---

### Task 6: Full Verification and Current-Data Draft

**Files:**
- Modify only if verification exposes a tested defect in Tasks 1-5.
- Output: new database timetable version through the application's ordinary generation workflow.

**Interfaces:**
- Consumes: completed Tasks 1-5, current migrated MySQL data, running queue worker, and ordinary Admin generation flow.
- Produces: verified build plus a new non-approved 2026-2027 Draft with 1,260 entries and zero hard issues, or a precise report of an actually infeasible input constraint.

- [ ] **Step 1: Run the complete automated suite**

Run: `php artisan test`

Run: `npm run build`

Expected: all tests pass and frontend build succeeds without errors.

- [ ] **Step 2: Generate against current data and inspect authoritative counts**

Start generation through the application, process the queued job, and query the new version. Require status `draft`, exactly 1,260 entries, and zero hard issues. If search reports infeasibility, stop and report the exact conflicting input/rule rather than weakening a constraint.

- [ ] **Step 3: Verify the website in a browser**

Inspect Grade 7, All Grades, a Section filter, a Department/Teacher filter, and an Admin edit modal. Require G7-G10 block order, dropdown-only Grade/Day controls, readable colors, time-only labels, visible Recess/Lunch, full subjects, aligned post-class rows, and functional editing.

- [ ] **Step 4: Export and visually inspect PDF/XLSX**

Download the new Draft's PDF and XLSX. Render every PDF page to PNG and inspect for clipping, overlaps, contrast, break rows, and aligned post-class rows. Open/analyze the XLSX package and verify tab order, merged rows, labels, and filter-selected exports.

- [ ] **Step 5: Run final regression checks**

Run: `php artisan test`

Run: `git diff --check`

Expected: complete suite passes and no whitespace errors are reported.

- [ ] **Step 6: Commit verification fixes, if any**

If Task 6 required code changes, commit only those tested fixes with message `fix: finalize timetable layout verification`; otherwise create no empty commit.
