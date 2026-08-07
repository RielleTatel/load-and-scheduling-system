# Milestone Design — Scaffold + System Admin & Department Chair

Date: 2026-08-07. Companion to `docs/Documentation/JHS System SRS.md` and `docs/Documentation/JHS Laravel System Architecture.md`, which remain authoritative for the full system. This spec covers only the current milestone and the decisions made for it.

## Scope

**In:** Laravel app scaffold; full core database schema; System Administrator features; Department Chair features including plantilla PDF import with mandatory review; Chair-side Submit-for-Review flow.

**Out (later milestones):** Academic Coordinator (all pages, return-submission flow), Scheduling Engine, report generation, notifications/inbox, period-level timetabling (constraints doc §7).

## Decisions (settled 2026-08-07)

| Decision | Choice |
|---|---|
| Stack | Laravel 11 + Blade + Alpine.js + Tailwind (no Livewire, no Inertia) |
| Database | MySQL, served by local XAMPP/MAMP |
| Auth | Laravel Breeze (Blade flavor), public registration removed; only Admin creates accounts |
| Plantilla import | PDF upload → `smalot/pdfparser` extraction → staging table → Chair review grid → confirmed import. Never auto-commit extracted data (SRS FR-5) |
| Submit flow | Chair side built now (Draft → Submitted, locks editing); Coordinator return side deferred |
| Schema strategy | Approach A: migrate all core tables now except engine output (`schedules`, `schedule_items`); features only for Admin + Chair |
| Seed data | 7 departments, all sections, role→equivalent-hours lookup, system constants, 1 admin + 7 chair demo accounts. Teachers/assignments enter via the real import flow |
| Numeric rules | All load constants live in `system_constants` (admin-editable), never hard-coded — overload divisor and some hour rules are still stakeholder-unconfirmed |

## Phase 1 — Scaffold (checkpoint: user inspects structure before Phase 2)

1. `composer create-project laravel/laravel` in the repo root, alongside `docs/`.
2. Breeze (Blade) install → Tailwind + Alpine configured; delete registration route/views; keep password reset.
3. `.env` pointed at XAMPP/MAMP MySQL; database created.
4. Folder skeleton per architecture doc: `app/Enums/` (`UserRole`, `EmploymentStatus`, `SubmissionStatus`, `GradeLevel`), `app/Http/Controllers/Admin/`, `app/Http/Controllers/DepartmentChair/`, `app/Services/{Auth,Plantilla,Curriculum,Audit}/`, `app/Policies/`.
5. Middleware: `EnsureUserRole` (route groups `/admin/*`, `/chair/*`), `EnsureDepartmentScope`.
6. All migrations + seeders (schema below). Post-login redirect by role.
7. Deliverable: runnable app — log in as seeded admin or chair, see an empty role-appropriate dashboard.

## Database schema

All tables from architecture doc §4 except `schedules`/`schedule_items`:
`users` (role enum + nullable `department_id`), `departments`, `teachers`, `sections` (UNIQUE grade+name), `teacher_section_assignments` (UNIQUE section+department+school_year), `class_moderator_assignments` (UNIQUE section+school_year), `honors_class_assignments`, `other_assignment_roles` (explicit `is_honorarium` flag), `teacher_other_assignments`, `service_loads`, `plantilla_submissions` (UNIQUE department+school_year, status enum draft/submitted/returned/locked), `plantilla_uploads`, `system_constants`, `audit_logs`.

**Addition to the architecture doc:** `plantilla_extraction_rows` — staging for PDF-extracted data.
Columns: `id`, `plantilla_upload_id` FK, `row_json` (raw extracted fields), `row_status` enum (`extracted` / `flagged` / `confirmed`), timestamps. Extraction writes here; the Chair review grid edits here; "Confirm & Import" copies to authoritative tables in a transaction. Nothing reads staging as authoritative.

Seeders: departments (hours/section + `has_honors_class` per vault Index), sections (from Sections Directory doc), other_assignment_roles (constraints doc §2 incl. honorarium-only club roles), system_constants (`full_load_hours=21`, `overload_divisor=3` description-flagged unconfirmed, `service_load_default=3`, `current_school_year=2026-2027`), demo accounts (1 admin, 7 chairs, known passwords).

**School year:** every school-year-scoped table reads the active year from the `current_school_year` system constant (admin-editable). No year picker in this milestone — the app operates on the current year only.

## Phase 2 — System Administrator

| Page | Behavior |
|---|---|
| Admin Dashboard | Counts (users, departments, submission statuses), quick links |
| User Directory | List/search/filter by role & department; create/edit (role + department required for chairs); deactivate/reactivate via `is_active` — never delete |
| System Constants | Edit key/value rows; unconfirmed values visibly labeled |
| Role → Equivalent Hours | CRUD on `other_assignment_roles` incl. `is_honorarium` |
| Audit Log Viewer | Filterable (user, action, date) table with before/after JSON |

Admin writes go through `UserProvisioningService` + `AuditLogService`. Admin has no access to teachers/assignments (separation of duties, SRS §3.1).

## Phase 3 — Department Chair

| Page | Behavior |
|---|---|
| Department Dashboard | Submission status banner; teacher count; sections covered vs total; data-quality flags (0-section teachers, stated-vs-computed hour mismatches per constraints doc §3 formula); per-teacher load summary |
| Upload Plantilla | PDF only; stores file, runs `PdfExtractionService`, writes staging rows, redirects to review |
| Review & Correction | Editable grid over staging rows (Alpine row editing): teacher name, employment status enum, sections per grade, CM, HC, service load, other assignments. Flagged/unparseable rows highlighted; manual row add. "Confirm & Import" → transaction into authoritative tables |
| Teacher Roster | List/edit own-department teachers |
| Section Assignment Editor | Teacher ↔ section per grade; enforces one teacher per (section × dept); CM assignment; HC only where `departments.has_honors_class` |
| Submit for Review | Computed totals per teacher (formula from constraints doc §3, constants from `system_constants`); submit → status `submitted`, locks all editing |

Every Chair route/query is server-side scoped to `auth()->user()->department_id` (middleware + policies) — a Chair can never reach another department's data by URL guessing (SRS §6.1).

## Error handling

- Extraction failure/partial parse never blocks: Chair still lands on the review grid with empty or flagged rows and completes manually.
- Import confirm is one DB transaction; any row failure rolls back all.
- Validation via Form Requests; enum canonicalization (e.g. "New Teacher" → Probationary 1) happens at review-confirm time per SRS FR-6.
- Submitted datasets reject writes at the Policy layer, not just hidden buttons.

## Testing

- Feature tests: RBAC boundaries (chair ↔ other dept, admin ↔ chair pages), submission state machine (draft→submitted lock), import confirm (staging → authoritative, transactional rollback).
- Unit tests: `PdfExtractionService` against the 7 real plantilla PDFs in `docs/Plantillas/` as fixtures — expected-output assertions tolerant of known extraction ambiguity (SRS §6.2).
- Seeders run in tests; MySQL for dev, SQLite in-memory acceptable for the test suite unless MySQL-specific behavior is under test.
