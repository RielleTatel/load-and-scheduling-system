# JHS Teaching Load & Scheduling System — Laravel Architecture

Companion to [[JHS System SRS]]. Stack: **Laravel (PHP) + Blade + a relational SQL database** (MySQL/MariaDB or PostgreSQL — either works, examples below use generic SQL types). Pattern: **Layered Architecture + Service Layer**, chosen deliberately over heavier patterns (CQRS, full DDD, Repository-per-aggregate) because the domain is small and well-understood (3 roles, 7 fixed departments, one school year at a time) and Laravel already gives you most of a layered architecture for free through Eloquent, Form Requests, Policies, and Jobs — fighting those conventions would add complexity, not reduce it.

Role model assumed throughout: **System Administrator, Department Chair, Academic Coordinator** — exactly three, per [[JHS System SRS]] §3. No Principal account exists anywhere in this design; approval happens on a printed/exported report, outside the application entirely.

---

## 1. Layers, Top to Bottom

```
┌─────────────────────────────────────────────────────────┐
│  Presentation Layer                                      │
│  Blade views + Controllers + Form Requests + Middleware  │
├─────────────────────────────────────────────────────────┤
│  Service Layer (Application Layer)                       │
│  Plain PHP service classes — all business logic lives here│
├─────────────────────────────────────────────────────────┤
│  Domain Layer                                             │
│  Eloquent Models + relationships + light domain methods   │
├─────────────────────────────────────────────────────────┤
│  Persistence Layer                                         │
│  Migrations, the SQL schema itself, query building via     │
│  Eloquent/Query Builder                                    │
└─────────────────────────────────────────────────────────┘
        (cross-cutting: Policies/Gates, Jobs/Queue, Events)
```

**Dependency rule:** each layer only calls the layer directly below it. Controllers never contain business logic and never build raw queries. Services never format HTTP responses and never receive a `Request` object directly — they receive plain arrays/DTOs, which keeps them testable without HTTP. Models stay "thin-ish": relationships, casts, scopes, and simple derived accessors are fine on the model; multi-step business rules (e.g. "compute overload," "detect a double-booked section") belong in a Service, not the Model.

No Repository layer. Eloquent models *are* the persistence abstraction in Laravel; adding a Repository interface on top of Eloquent (which is already a Data Mapper/Active Record hybrid sitting on Query Builder) is a common over-engineering trap for a project this size. If a future need arises to swap the ORM, that's the point to reconsider — not before.

---

## 2. Folder Structure

```
app/
├── Console/
│   └── Commands/
│
├── Enums/
│   ├── UserRole.php                   # system_admin | department_chair | academic_coordinator
│   ├── EmploymentStatus.php           # Permanent | Probationary1-3 | Substitute | Retiree
│   ├── SubmissionStatus.php           # draft | submitted | returned | locked
│   ├── ScheduleStatus.php             # draft | finalized | superseded
│   └── GradeLevel.php                 # G7 | G8 | G9 | G10
│
├── Http/
│   ├── Controllers/
│   │   ├── Admin/
│   │   │   ├── UserController.php
│   │   │   ├── DepartmentController.php
│   │   │   └── SystemConstantController.php
│   │   ├── DepartmentChair/
│   │   │   ├── DashboardController.php
│   │   │   ├── PlantillaUploadController.php
│   │   │   ├── PlantillaReviewController.php
│   │   │   ├── TeacherController.php
│   │   │   ├── SectionAssignmentController.php
│   │   │   └── SubmissionController.php
│   │   └── AcademicCoordinator/
│   │       ├── DashboardController.php
│   │       ├── SubmissionReviewController.php
│   │       ├── MasterDirectoryController.php      # teacher & section master views
│   │       ├── WorkloadAnalyticsController.php
│   │       ├── ScheduleGenerationController.php
│   │       ├── ScheduleReviewController.php
│   │       └── ReportController.php
│   │
│   ├── Requests/
│   │   ├── Chair/
│   │   │   ├── StorePlantillaUploadRequest.php
│   │   │   ├── UpdatePlantillaReviewRequest.php
│   │   │   └── StoreSectionAssignmentRequest.php
│   │   ├── Coordinator/
│   │   │   ├── ReturnSubmissionRequest.php
│   │   │   └── OverrideScheduleItemRequest.php
│   │   └── Admin/
│   │       └── StoreUserRequest.php
│   │
│   ├── Middleware/
│   │   ├── EnsureUserRole.php          # role gate, e.g. ->middleware('role:academic_coordinator')
│   │   └── EnsureDepartmentScope.php   # blocks a Chair from touching another dept's route params
│   │
│   └── ViewModels/                      # optional thin DTOs assembled for Blade, see §5
│       ├── DepartmentDashboardViewModel.php
│       └── ScheduleReviewViewModel.php
│
├── Models/
│   ├── User.php
│   ├── Department.php
│   ├── Teacher.php
│   ├── Section.php
│   ├── TeacherSectionAssignment.php     # the Chair-owned "load data" record
│   ├── ClassModeratorAssignment.php
│   ├── HonorsClassAssignment.php
│   ├── OtherAssignmentRole.php          # lookup: role name -> equivalent hours, is_honorarium
│   ├── TeacherOtherAssignment.php
│   ├── PlantillaSubmission.php
│   ├── PlantillaUpload.php
│   ├── SystemConstant.php
│   ├── Schedule.php
│   ├── ScheduleItem.php
│   └── AuditLog.php
│
├── Policies/
│   ├── PlantillaSubmissionPolicy.php
│   ├── TeacherSectionAssignmentPolicy.php
│   ├── SchedulePolicy.php
│   └── UserPolicy.php
│
├── Services/
│   ├── Auth/
│   │   └── UserProvisioningService.php          # FR-1–FR-3
│   ├── Plantilla/
│   │   ├── PdfExtractionService.php              # FR-4, wraps smalot/pdfparser
│   │   ├── PlantillaReviewService.php            # FR-5, FR-6, FR-7
│   │   └── SubmissionService.php                 # FR-8, FR-9, FR-11
│   ├── Curriculum/
│   │   ├── SectionAssignmentService.php          # FR-8a — teacher<->section only, never touches schedule
│   │   └── ValidationService.php                 # FR-10 — hour-total mismatch checks
│   ├── Scheduling/
│   │   ├── ScheduleGenerationService.php          # FR-12–FR-16, the "engine"
│   │   ├── ConflictDetector.php                   # used by ScheduleGenerationService
│   │   └── WorkloadBalancer.php                   # used by ScheduleGenerationService
│   ├── Reporting/
│   │   └── ReportGenerationService.php            # FR-18, wraps barryvdh/laravel-dompdf
│   └── Audit/
│       └── AuditLogService.php                    # §6.3
│
├── Jobs/
│   └── GenerateScheduleJob.php          # queues ScheduleGenerationService — see §6
│
├── Events/ (optional, only if needed)
│   ├── PlantillaSubmitted.php
│   └── ScheduleGenerated.php
│
└── Providers/
    └── AppServiceProvider.php           # Policy registration, Gate::before for admin bypass

database/
├── migrations/                          # one SQL schema, see §4
├── seeders/
│   ├── DepartmentSeeder.php              # seeds the fixed 7 departments
│   └── OtherAssignmentRoleSeeder.php     # seeds the role -> equivalent-hours lookup
└── factories/                            # for tests

resources/
└── views/
    ├── layouts/
    │   ├── app.blade.php
    │   └── partials/ (nav, flash-messages)
    ├── admin/
    ├── department-chair/
    │   ├── dashboard.blade.php
    │   ├── plantilla/upload.blade.php
    │   ├── plantilla/review.blade.php
    │   ├── sections/index.blade.php
    │   └── submission/review.blade.php
    ├── academic-coordinator/
    │   ├── dashboard.blade.php
    │   ├── directories/teachers.blade.php
    │   ├── directories/sections.blade.php
    │   ├── analytics/workload.blade.php
    │   ├── schedule/review.blade.php
    │   └── report/show.blade.php
    └── components/                       # Blade components: conflict-flag, hours-badge, status-pill

routes/
└── web.php                               # grouped by role middleware, see §3
```

---

## 3. RBAC Implementation

**Users table carries the role directly** — a single `role` enum column plus a nullable `department_id` FK (populated only for `department_chair`). No `spatie/laravel-permission` package: with exactly 3 fixed roles and no dynamic permission editing requirement, a plain enum + Laravel's built-in `Gate`/`Policy` system is simpler, faster, and easier for a small team to reason about than a permissions-table abstraction built for far more flexible role models. Add that package later only if the school later asks for custom per-user permission overrides — not before.

```php
// app/Enums/UserRole.php
enum UserRole: string {
    case SystemAdmin = 'system_admin';
    case DepartmentChair = 'department_chair';
    case AcademicCoordinator = 'academic_coordinator';
}
```

**Route-level gating** via middleware groups, mirroring §3.2's RBAC matrix directly:

```php
// routes/web.php
Route::middleware(['auth', 'role:system_admin'])->prefix('admin')->group(function () {
    // UserController, DepartmentController, SystemConstantController
});

Route::middleware(['auth', 'role:department_chair'])->prefix('chair')->group(function () {
    // Dashboard, PlantillaUpload, PlantillaReview, Sections, Submission
    // EnsureDepartmentScope additionally binds every query to auth()->user()->department_id
});

Route::middleware(['auth', 'role:academic_coordinator'])->prefix('coordinator')->group(function () {
    // Dashboard, SubmissionReview, MasterDirectory, WorkloadAnalytics,
    // ScheduleGeneration, ScheduleReview, Report
});
```

**Object-level authorization** via Policies for anything scoped to a specific record (a specific `PlantillaSubmission`, a specific `Schedule`), so a Chair can never reach another department's data even by guessing a URL:

```php
class PlantillaSubmissionPolicy {
    public function view(User $user, PlantillaSubmission $submission): bool {
        return $user->role === UserRole::AcademicCoordinator
            || ($user->role === UserRole::DepartmentChair
                && $user->department_id === $submission->department_id);
    }

    public function update(User $user, PlantillaSubmission $submission): bool {
        return $user->role === UserRole::DepartmentChair
            && $user->department_id === $submission->department_id
            && $submission->status !== SubmissionStatus::Locked;
    }
}
```

This is the concrete implementation of the SRS's repeated point that department scoping "must be enforced at the data layer, not just hidden in the UI" (SRS §6.1) — the Policy runs server-side on every controller action via `$this->authorize()`, independent of what the Blade view happens to render.

**No Principal account, no Principal middleware, no Principal policy** — there is nothing in the `routes/`, `Policies/`, or `Enums/UserRole` to build for that actor, by design.

---

## 4. Database Schema (SQL)

One relational database — no split "Central" vs. "Schedule" database (that was a placeholder in the pre-Laravel draft of the SRS; a single Laravel app with one database and clearly separated tables achieves the same isolation without the operational overhead of two databases and cross-database joins).

```sql
-- Accounts (Admin, Chair, Coordinator only — never Principal)
users
  id, name, email, password, role ENUM('system_admin','department_chair','academic_coordinator'),
  department_id NULL REFERENCES departments(id),   -- set only for department_chair
  is_active BOOLEAN DEFAULT TRUE, timestamps

-- Fixed 7 JHS departments, seeded once
departments
  id, name, code, hours_per_section INT, has_honors_class BOOLEAN, timestamps

-- Teachers are data records, not logins (no Teacher role in the system)
teachers
  id, full_name, employment_status ENUM('permanent','probationary_1','probationary_2',
      'probationary_3','substitute','retiree'), department_id REFERENCES departments(id),
  timestamps

-- Sections are (grade, name) pairs, per SRS §6.4 — never name alone
sections
  id, grade_level ENUM('G7','G8','G9','G10'), name,
  UNIQUE(grade_level, name), timestamps

-- The Chair-owned "load data": who teaches what. NOT a schedule. No period/room/time columns —
-- those don't exist in this system; see SRS §3.1 boundary note.
teacher_section_assignments
  id, teacher_id REFERENCES teachers(id), section_id REFERENCES sections(id),
  department_id REFERENCES departments(id), school_year, hours DECIMAL,
  UNIQUE(section_id, department_id, school_year),   -- one subject teacher per section per dept
  timestamps

class_moderator_assignments
  id, teacher_id REFERENCES teachers(id), section_id REFERENCES sections(id),
  school_year, hours DECIMAL,
  UNIQUE(section_id, school_year),                  -- exactly one moderator per section
  timestamps

honors_class_assignments
  id, teacher_id REFERENCES teachers(id), section_id REFERENCES sections(id),
  school_year, hours DECIMAL, timestamps

-- Other Assignment role lookup (Dept Chair, GLL, Coordinator roles, Honoraria club-mod roles, etc.)
other_assignment_roles
  id, name, equivalent_hours DECIMAL NULL, is_honorarium BOOLEAN,
  -- is_honorarium is an explicit flag, not inferred from a NULL equivalent_hours (SRS FR-7)
  timestamps

teacher_other_assignments
  id, teacher_id REFERENCES teachers(id), other_assignment_role_id REFERENCES other_assignment_roles(id),
  school_year, timestamps

service_loads
  id, teacher_id REFERENCES teachers(id), school_year, hours DECIMAL, timestamps

-- Per-department submission workflow state (FR-8, FR-9, FR-11)
plantilla_submissions
  id, department_id REFERENCES departments(id), school_year,
  status ENUM('draft','submitted','returned','locked'),
  submitted_by_user_id NULL REFERENCES users(id), submitted_at NULL,
  returned_comment TEXT NULL, returned_by_user_id NULL REFERENCES users(id),
  UNIQUE(department_id, school_year), timestamps

-- Raw PDF + extraction metadata, kept separate from authoritative data (FR-4, FR-5)
plantilla_uploads
  id, plantilla_submission_id REFERENCES plantilla_submissions(id),
  file_path, original_filename, extraction_status ENUM('pending','extracted','reviewed','failed'),
  extracted_at NULL, timestamps

-- Admin-configurable constants (SRS §6.5) — data, not hard-coded
system_constants
  id, key UNIQUE, value, description, timestamps
  -- rows: full_load_hours=21, overload_divisor=3 (unconfirmed, see SRS §9), service_load_default=3

-- Generated schedule (the Scheduling Engine's output, FR-12–FR-16)
schedules
  id, school_year, status ENUM('draft','finalized','superseded'),
  generated_by_user_id REFERENCES users(id), generated_at,
  presented_label ENUM('not_presented','presented','externally_approved') DEFAULT 'not_presented',
  -- presented_label is a tracking note only (FR-19) — never checked by any Policy or Gate
  timestamps

schedule_items
  id, schedule_id REFERENCES schedules(id), teacher_id REFERENCES teachers(id),
  section_id REFERENCES sections(id), department_id REFERENCES departments(id),
  hours DECIMAL, is_conflict BOOLEAN DEFAULT FALSE, conflict_reason TEXT NULL,
  timestamps
  -- No period/time_slot/room columns — out of scope; see note below

-- Full write history (SRS §6.3)
audit_logs
  id, user_id REFERENCES users(id), action, auditable_type, auditable_id,
  before_json JSON NULL, after_json JSON NULL, created_at
```

**Note on `schedule_items` and "faculty schedule":** the source plantillas never contain period, time-slot, or room data — only teacher × section × hours. "Schedule" in this system means the Scheduling Engine's *consolidated, conflict-checked, workload-balanced* version of the load data submitted by all 7 Chairs, not a bell-schedule timetable. If a future phase adds actual period/room timetabling, that's a new set of columns/tables on top of this — don't retrofit them into `teacher_section_assignments`, which stays Chair-owned raw input.

---

## 5. Layer Responsibilities in Practice

**Controller (thin, one job: translate HTTP ↔ Service):**
```php
class ScheduleGenerationController extends Controller
{
    public function __construct(private ScheduleGenerationService $engine) {}

    public function store(Request $request)
    {
        $this->authorize('generate', Schedule::class);

        $schedule = $this->engine->generate(schoolYear: $request->input('school_year'));

        return redirect()
            ->route('coordinator.schedule.review', $schedule)
            ->with('status', 'Schedule generated.');
    }
}
```

**Service (all the actual logic, framework-agnostic where possible):**
```php
class ScheduleGenerationService
{
    public function __construct(
        private ConflictDetector $conflicts,
        private WorkloadBalancer $balancer,
        private AuditLogService $audit,
    ) {}

    public function generate(string $schoolYear): Schedule
    {
        // FR-9: block if not all 7 departments submitted (checked by caller/Job before this runs)
        $assignments = TeacherSectionAssignment::forSchoolYear($schoolYear)->get();

        $schedule = Schedule::create([
            'school_year' => $schoolYear,
            'status' => ScheduleStatus::Draft,
            'generated_by_user_id' => auth()->id(),
            'generated_at' => now(),
        ]);

        foreach ($assignments as $assignment) {
            $item = $schedule->items()->create([...]);
            $this->conflicts->check($item);   // FR-15
        }

        $this->balancer->flagImbalances($schedule); // workload balancing
        $this->audit->log('schedule.generated', $schedule);

        return $schedule;
    }
}
```

**Blade view (dumb — renders what it's given, no business logic, no direct model queries):**
```blade
{{-- resources/views/academic-coordinator/schedule/review.blade.php --}}
@extends('layouts.app')
@section('content')
  <h1>Draft Schedule — {{ $schedule->school_year }}</h1>
  @foreach ($schedule->items as $item)
    <x-schedule-item-row :item="$item" />
  @endforeach
@endsection
```

Controllers pass Models or small ViewModel DTOs to Blade — never raw query builders, and Blade templates never call `Model::where(...)` directly. This keeps the "layered" boundary real rather than nominal.

---

## 6. Scheduling Engine as a Queued Job

Schedule generation reads every department's submitted data, runs conflict detection and workload balancing — worth taking off the request/response cycle so a slow generation doesn't time out an HTTP request:

```php
class GenerateScheduleJob implements ShouldQueue
{
    public function __construct(private string $schoolYear, private int $triggeredByUserId) {}

    public function handle(ScheduleGenerationService $engine): void
    {
        $engine->generate($this->schoolYear);
        // notify the Coordinator (in-app flash / DB notification) when done
    }
}
```

`ScheduleGenerationController::store()` dispatches `GenerateScheduleJob` instead of calling the service synchronously once data volume justifies it; for JHS's actual scale (7 departments, ~65 teachers per the current data — see [[JHS Department Directory]]) a synchronous call is probably fine at launch. Laravel's `database` queue driver is enough; no Redis needed unless load grows.

---

## 7. PDF Ingestion & Report Generation — Concrete Packages

- **Plantilla PDF extraction (FR-4):** `smalot/pdfparser` — pure PHP, no external binary dependency, fits a Laravel deploy cleanly. `PdfExtractionService` wraps it and returns a structured (but *unconfirmed*) draft array; `PlantillaReviewService` is what the Chair's review screen (FR-5) writes back to after human correction. This directly operationalizes SRS §6.2's finding that extraction is unreliable enough to require a mandatory review gate — the code path makes it structurally impossible to skip that gate, since `plantilla_uploads.extraction_status` only moves to `'reviewed'` via an explicit Chair action, and nothing reads from `plantilla_uploads` as authoritative — only from the reviewed `teacher_section_assignments` etc.
- **Final report generation (FR-18):** `barryvdh/laravel-dompdf` (or `spatie/browsershot` if the Chrome-rendered fidelity is worth the extra system dependency) renders a Blade view — `resources/views/academic-coordinator/report/pdf.blade.php` — styled to mirror the existing plantilla's header/table/signature-block layout, with the Principal's "Approved by" line left blank. `ReportGenerationService::generate(Schedule $schedule): string` returns a file path/stream for download; nothing about this step requires or checks a Principal login, because there isn't one.

---

## 8. Why This Fits the Stated Constraints

- **Simple:** three roles, one database, no microservices, no repository indirection — every layer maps to a Laravel concept a Laravel developer already knows (Controller, Form Request, Policy, Eloquent Model, Job).
- **Maintainable:** business logic concentrated in `app/Services`, so `php artisan test` can exercise `ScheduleGenerationService` or `ValidationService` directly without booting HTTP, and a future rule change (e.g. the overload divisor, see SRS §9) touches one Service method plus one `system_constants` row, not scattered controller code.
- **Laravel-conventional:** Form Requests for validation, Policies for authorization, Eloquent for persistence, Jobs for the one genuinely long-running process, Blade for a straightforward server-rendered UI — nothing here fights the framework's defaults.
- **Matches the corrected role/approval model:** no Principal table, route, policy, or view exists anywhere in this architecture; the system's last responsibility is `ReportGenerationService`, full stop, exactly as [[JHS System SRS]] §4.1 now specifies.
