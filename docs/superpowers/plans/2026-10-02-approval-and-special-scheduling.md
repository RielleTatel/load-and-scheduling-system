# Approval Hardening and Special Scheduling Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Recheck current source data before timetable approval, update project documentation, and add independently published special review schedules from the newly provided sample.

**Architecture:** Extend the existing timetable pipeline with a Normal/Special kind. Reuse readiness, lifecycle, editing, and layout/export services; keep normal generation unchanged and give special review-day generation its own eight-slot graph solver. Repeat one valid review day on all five days and synchronize special manual edits across them.

**Tech Stack:** Laravel 13, PHP 8.4, Blade/Alpine, existing PDF/OpenXML exporters, MySQL, SQLite in-memory PHPUnit tests.

**Spec:** `docs/Documentation/Scheduling Feature.md`; user authorization on October 2 covers tasks 2/5 and special implementation if the sample supplies sufficient requirements. No database imports, fabricated source corrections, or approvals of existing versions are authorized by this work.

## Global Constraints

- Preserve existing roles, permissions, plantilla workflows, normal-generation constraints, and unrelated features.
- Use Day 1–5, TIME-only timetable labels, and grades 7–10 in order.
- Special: eight 50-minute subject periods, each subject once daily, identical order on all five days.
- Special: no normal frequency/block/teacher-break rules; preserve teacher/section conflicts, readiness, and availability.
- Normal and special generation/publication do not supersede one another.
- Meeting/facility/duty rules stay deferred; real English remains pending.

## Review Focus

- Source data changes after generation: approval blocks and persists errors rather than trusting stale zero-error results.
- Special review order under availability on only one day: avoid the slot on all five days.
- Generation or approval of one kind must not replace the other kind.
- Special edits/undo affect all five days atomically, including period 8.
- Existing normal versions, export names/bells, and Chair access remain compatible.

### Task 1: Approval input integrity

**Files:** `TimetableValidationService.php`, `ScheduleReadinessService.php`, `TimetableEditor.php`; `tests/Feature/Scheduling/TimetableEditingTest.php`.

**Interfaces:** `validate(TimetableVersion): array` combines period issues with current readiness and teacher-assignment issues; `refresh` persists them. Same-subject replacement can repair an outdated teacher.

- [x] Write and run failing tests for changed assignment, department, submission status, and same-subject teacher repair.
- [x] Implement current assignment/department comparisons and readiness checks; preserve errors when approval is blocked.
- [x] Verify valid approval/edit/undo still pass; test reduced major-teacher cap after a new advisory assignment.

### Task 2: Special schedule model and solver

**Files:** `TimetableKind.php`, additive October 2 migration, `TimetableVersion.php`, `ScheduleConstraintValidator.php`, `SpecialScheduleGenerator.php`, `TimetableLayout.php`; `tests/Unit/SpecialScheduleTest.php`.

**Interfaces:** `TimetableKind::periodsPerDay(): int`; kind-aware `validate(..., TimetableKind $kind = Normal)` and `rows(day, kind = Normal)`; `SpecialScheduleGenerator` implements the existing `TimetableGenerator` interface.

- [x] Write failing tests for all-grade shared-teacher generation, repeated order, one-day unavailability, impossible availability, conflicts, and sample bells.
- [x] Default old versions to Normal; construct special orders through eight-color bipartite edge coloring, with exact availability-constrained fallback.
- [x] Verify 36 sections × 8 periods × 5 days, no hard errors, and no normal-rule changes.

### Task 3: Lifecycle, editing, UI, and exports

**Files:** generation job/input/progress, version/editor/presentation/export services, timetable controller, scheduling Blade views; feature scheduling tests and unit export tests.

**Interfaces:** `startGeneration(year, quarter, actor, kind = Normal)`; same-kind generation/approval/superseding; special edit snapshots spanning five days; shared layout bells in web/PDF/Excel.

- [x] Write and run failing integration tests for special drafts, kind filters/access, independent publication, cloning, period-8 edits, multi-day undo, and exports.
- [x] Wire special generation through the existing cancellable queue pipeline and fresh authoritative validation.
- [x] Add schedule-kind selection, special rules/copy, period-8 controls, and correctly labeled exports.
- [x] Run the full tests/build and inspect rendered special/normal UI and PDF layout.

### Task 4: Documentation and handoff

**Files:** current Scheduling Feature reference, constraints/SRS/architecture addenda, index, historical design/plan notices.

- [x] Record confirmed normal/special rules, source/test data separation, updated permissions and implementation map, and deferred requirements.
- [x] Perform read-only independent code review and address substantive findings.
- [x] Apply the additive migration if MySQL is available; otherwise hand off the exact command and state the limitation. Never change existing schedule entries or publish a test draft in the real database.
- [x] Report fresh verification results and how Admin selects Special review.

No Git commits or unrelated worktree cleanup are part of the user's request.

### October 2 addon: branded scheduling dropdowns

- [x] Enhance scheduling filters, kind/quarter selectors, editing menus, and availability inputs using existing branding; preserve native forms, Alpine bindings, and no-JavaScript fallback.
- [x] Verify pointer and keyboard behavior, All grades/All days filtering, narrow-screen placement, and special edit/save/undo in isolated SQLite preview data.
- [x] Reproduce and fix popup clicks dismissing the editor before Save; keep popup click events from reaching Alpine's outside-click handler.
- [x] Reproduce and fix the unused special Target day control through a failing-then-passing feature test.

Review corrections: serialize global version-number allocation/publication across kinds; merge/style Excel dismissal correctly; let selected sections determine their grade rather than showing an empty grid when filters conflict. Regression tests failed before each correction and passed afterward. Independent follow-up review found no remaining actionable issues in the bounded corrections.

Verification uses SQLite only. A 36-section isolated special draft had 1,440 entries and zero hard errors; rendered PDF bells/centering and desktop/narrow-screen menus were inspected. The real MySQL change was only the additive kind migration; existing schedule data and plantillas were not edited or published. Real English remains pending.

Final evidence: full PHPUnit suite **274/274 passing, 1,907 assertions**; `npm run build` succeeded; `git diff --check` clean; `php artisan migrate:status` confirms the October 2 migration Ran. Browser checks verified normal/special kind selection, all-grade/all-day filters, special period-8 swap/save/undo, preserved normal target-day editing, and no console errors. Admin entry point: Schedules → Schedule: Special review → Quarter → Generate special schedule. No commits, pushes, or actual schedule approvals were performed.
