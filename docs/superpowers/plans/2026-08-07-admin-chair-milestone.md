# Scaffold + Admin & Department Chair Milestone — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A runnable Laravel app where a seeded System Admin manages users/constants/roles/audit and each of 7 Department Chairs imports their plantilla PDF through a review gate, manages teachers/section assignments, and submits their dataset.

**Architecture:** Laravel monolith (layered + service pattern per `docs/Documentation/JHS Laravel System Architecture.md`): thin controllers → plain-PHP services → Eloquent models → MySQL. Blade + Tailwind + Alpine frontend via Breeze. PDF extraction writes to a staging table; only Chair-confirmed data reaches authoritative tables.

**Tech Stack:** PHP 8.3+, Laravel 12.x (latest stable `laravel/laravel`; spec said 11 — 12 is current, identical for our purposes), Breeze (Blade), Tailwind, Alpine.js, MySQL (XAMPP/MAMP), `smalot/pdfparser`, PHPUnit (SQLite in-memory for tests).

## Global Constraints

- Spec: `docs/superpowers/specs/2026-08-07-admin-chair-milestone-design.md`. Authoritative background: `docs/Documentation/JHS System SRS.md`, `.../JHS Laravel System Architecture.md`, `.../JHS Scheduling Constraints.md`.
- Exactly 3 roles: `system_admin`, `department_chair`, `academic_coordinator`. **No Coordinator features are built** — the enum value exists, nothing else.
- No public registration. Admin creates all accounts.
- Chair data access is server-side scoped to `auth()->user()->department_id` — never trust a department id from the request.
- Never auto-commit PDF-extracted data. Staging (`plantilla_extraction_rows`) → Chair confirm → authoritative tables, in a transaction.
- All numeric load rules come from `system_constants` (`full_load_hours=21`, `overload_divisor=3` (unconfirmed), `service_load_default=3`, `class_moderator_hours=3`, `honors_class_hours=8`, `current_school_year=2026-2027`). Never hard-code these in services.
- DB columns for enums are **strings with PHP enum casts** (not MySQL ENUM) — keeps SQLite test compatibility and painless value additions.
- Sections are (grade_level, name) pairs, unique together.
- Soft-deactivate users via `is_active`; never delete.
- Commit after every task. Run `php artisan test` before every commit; all green.
- **After Task 7, STOP for the user's structure checkpoint before Phase 2.**

## File Structure (end state)

```
app/
├── Enums/{UserRole,EmploymentStatus,SubmissionStatus,GradeLevel,ExtractionRowStatus}.php
├── Http/
│   ├── Controllers/
│   │   ├── Admin/{DashboardController,UserController,SystemConstantController,AssignmentRoleController,AuditLogController}.php
│   │   ├── DepartmentChair/{DashboardController,PlantillaUploadController,PlantillaReviewController,TeacherController,SectionAssignmentController,SubmissionController}.php
│   │   └── RoleRedirectController.php
│   ├── Middleware/EnsureUserRole.php
│   └── Requests/... (per task)
├── Models/{User,Department,Teacher,Section,TeacherSectionAssignment,ClassModeratorAssignment,HonorsClassAssignment,OtherAssignmentRole,TeacherOtherAssignment,ServiceLoad,PlantillaSubmission,PlantillaUpload,PlantillaExtractionRow,SystemConstant,AuditLog}.php
├── Policies/PlantillaSubmissionPolicy.php
└── Services/
    ├── Audit/AuditLogService.php
    ├── Auth/UserProvisioningService.php
    ├── Curriculum/{LoadCalculationService,SectionAssignmentService}.php
    └── Plantilla/{PdfExtractionService,PlantillaReviewService,SubmissionService}.php
database/
├── migrations/... (Tasks 3–4)
├── seeders/{DepartmentSeeder,SectionSeeder,OtherAssignmentRoleSeeder,SystemConstantSeeder,UserSeeder,DatabaseSeeder}.php
└── factories/{UserFactory,DepartmentFactory,TeacherFactory,SectionFactory}.php
resources/views/
├── admin/{dashboard,users/index,users/form,constants/index,roles/index,roles/form,audit/index}.blade.php
└── chair/{dashboard,plantilla/upload,plantilla/review,teachers/index,teachers/form,assignments/index,submission/show}.blade.php
routes/web.php
tests/Feature/... tests/Unit/... tests/Fixtures/filipino-plantilla.pdf
```

---

## Phase 1 — Scaffold

### Task 1: Create the Laravel app

**Files:** Create: entire Laravel skeleton at repo root; Modify: `.gitignore` (root)

**Interfaces:** Produces the base app all later tasks live in.

- [ ] **Step 1: Verify PHP + Composer exist**

Run: `php -v && composer -V`
Expected: PHP ≥ 8.2, Composer 2.x. If PHP is missing, use XAMPP's (`/Applications/XAMPP/xamppfiles/bin/php`) or MAMP's (`/Applications/MAMP/bin/php/php8.*/bin/php`) — put whichever exists on PATH for all later commands.

- [ ] **Step 2: Scaffold into the repo root**

Laravel's installer wants an empty dir, so create in a temp dir and move:

```bash
cd "/Users/tatelgabrielle/Desktop/PROJECTS/ALP/load-and-scheduling-system"
composer create-project laravel/laravel _app --no-interaction
rsync -a _app/ ./ --exclude .git && rm -rf _app
```

- [ ] **Step 3: Extend .gitignore**

Append to the generated `.gitignore`:

```
.DS_Store
.obsidian/
```

Then `git rm -r --cached docs/.DS_Store docs/Plantillas/.DS_Store .DS_Store 2>/dev/null || true` (they were committed with the docs vault).

- [ ] **Step 4: Smoke test**

Run: `php artisan test`
Expected: the default example tests PASS.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "chore: scaffold Laravel app"
```

### Task 2: Breeze auth (no registration) + MySQL wiring

**Files:** Modify: `.env`, `routes/auth.php`, `resources/views/auth/login.blade.php`, `tests/Feature/Auth/*`; Delete: registration views/controller usage

**Interfaces:** Produces working login/logout/password-reset; `users` table exists (default migration — extended in Task 3).

- [ ] **Step 1: Install Breeze (Blade stack)**

```bash
composer require laravel/breeze --dev
php artisan breeze:install blade --no-interaction
npm install && npm run build
```

- [ ] **Step 2: Remove public registration**

In `routes/auth.php`, delete the two `register` routes (GET + POST) and the `RegisteredUserController` import. Delete `resources/views/auth/register.blade.php` and the "Register" link in `login.blade.php` / `welcome.blade.php`. Delete `tests/Feature/Auth/RegistrationTest.php`.

- [ ] **Step 3: Point .env at MySQL**

Detect the running stack: XAMPP MySQL socket `/Applications/XAMPP/xamppfiles/var/mysql/mysql.sock` (port 3306, user `root`, empty password) or MAMP `/Applications/MAMP/tmp/mysql/mysql.sock` (port 8889, user/pass `root`/`root`). Set in `.env`:

```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306            # 8889 for MAMP
DB_DATABASE=jhs_load_system
DB_USERNAME=root
DB_PASSWORD=            # root for MAMP
```

Create the database: `mysql -u root -e "CREATE DATABASE IF NOT EXISTS jhs_load_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"` (use the stack's mysql binary path; ask the user to start MySQL in the XAMPP/MAMP control panel if the connection is refused — do not silently fall back to SQLite for dev).

Run: `php artisan migrate`
Expected: default + Breeze migrations run against MySQL.

- [ ] **Step 4: Confirm tests still use SQLite in-memory**

Check `phpunit.xml` contains `<env name="DB_CONNECTION" value="sqlite"/>` and `<env name="DB_DATABASE" value=":memory:"/>` (Laravel default). Add if missing.

Run: `php artisan test`
Expected: PASS (auth tests minus registration).

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat: Breeze auth without registration, MySQL wiring"
```

### Task 3: Enums + reference-table migrations, models, factories

**Files:**
- Create: `app/Enums/UserRole.php`, `EmploymentStatus.php`, `SubmissionStatus.php`, `GradeLevel.php`, `ExtractionRowStatus.php`
- Create migrations: `create_departments_table`, `create_sections_table`, `create_teachers_table`, `add_role_fields_to_users_table`
- Create: `app/Models/{Department,Section,Teacher}.php`; Modify: `app/Models/User.php`
- Create factories: `DepartmentFactory`, `SectionFactory`, `TeacherFactory`; Modify `UserFactory`
- Test: `tests/Feature/SchemaTest.php`, `tests/Unit/EmploymentStatusTest.php`

**Interfaces (produces — later tasks depend on these exact names):**
- `UserRole: string { SystemAdmin='system_admin'; DepartmentChair='department_chair'; AcademicCoordinator='academic_coordinator' }`
- `EmploymentStatus: string { Permanent='permanent'; Probationary1='probationary_1'; Probationary2='probationary_2'; Probationary3='probationary_3'; Substitute='substitute'; Retiree='retiree' }` + `public static function fromLabel(string $raw): ?self`
- `SubmissionStatus: string { Draft='draft'; Submitted='submitted'; Returned='returned'; Locked='locked' }`
- `GradeLevel: string { G7='G7'; G8='G8'; G9='G9'; G10='G10' }`
- `ExtractionRowStatus: string { Extracted='extracted'; Flagged='flagged'; Confirmed='confirmed' }`
- `User`: `role` (cast `UserRole`), `department_id` (nullable FK), `is_active` (bool), helpers `isAdmin(): bool`, `isChair(): bool`, relation `department()`
- `Department`: `name`, `code`, `hours_per_section` (int), `has_honors_class` (bool); relations `teachers()`, `chair()` (hasOne User where role=chair)
- `Section`: `grade_level` (cast `GradeLevel`), `name`; unique (grade_level, name)
- `Teacher`: `full_name`, `employment_status` (cast `EmploymentStatus`), `department_id`; relation `department()`

- [ ] **Step 1: Write failing tests**

`tests/Unit/EmploymentStatusTest.php`:

```php
<?php
use App\Enums\EmploymentStatus;
use PHPUnit\Framework\TestCase;

class EmploymentStatusTest extends TestCase
{
    public function test_from_label_canonicalizes_source_variants(): void
    {
        $this->assertSame(EmploymentStatus::Permanent, EmploymentStatus::fromLabel('FT Permanent'));
        $this->assertSame(EmploymentStatus::Permanent, EmploymentStatus::fromLabel('Permanent Teacher'));
        $this->assertSame(EmploymentStatus::Probationary1, EmploymentStatus::fromLabel('New Teacher'));
        $this->assertSame(EmploymentStatus::Probationary2, EmploymentStatus::fromLabel('FT Probationary 2'));
        $this->assertSame(EmploymentStatus::Substitute, EmploymentStatus::fromLabel('Substitute (Probationary 1)'));
        $this->assertSame(EmploymentStatus::Retiree, EmploymentStatus::fromLabel('Retiree'));
        $this->assertNull(EmploymentStatus::fromLabel('garbage'));
    }
}
```

`tests/Feature/SchemaTest.php`:

```php
<?php
use App\Enums\{GradeLevel, UserRole};
use App\Models\{Department, Section, Teacher, User};
use Illuminate\Foundation\Testing\RefreshDatabase;

uses_or_extends: // use PHPUnit class style:
class SchemaTest extends Tests\TestCase
{
    use RefreshDatabase;

    public function test_core_models_create_and_relate(): void
    {
        $dept = Department::factory()->create(['code' => 'FIL', 'hours_per_section' => 4]);
        $teacher = Teacher::factory()->create(['department_id' => $dept->id]);
        $section = Section::factory()->create(['grade_level' => GradeLevel::G7, 'name' => 'Ignatius']);
        $chair = User::factory()->chair($dept)->create();

        $this->assertTrue($teacher->department->is($dept));
        $this->assertSame('G7', $section->grade_level->value);
        $this->assertSame(UserRole::DepartmentChair, $chair->role);
        $this->assertTrue($chair->is_active);
    }

    public function test_section_grade_name_unique(): void
    {
        Section::factory()->create(['grade_level' => GradeLevel::G7, 'name' => 'Xavier']);
        $this->expectException(\Illuminate\Database\QueryException::class);
        Section::factory()->create(['grade_level' => GradeLevel::G7, 'name' => 'Xavier']);
    }
}
```

(Write it as a proper class extending `Tests\TestCase` — the `uses_or_extends` line above is a note, not code.)

- [ ] **Step 2: Run tests, verify failure** — `php artisan test` → FAIL (missing enums/models).

- [ ] **Step 3: Implement**

`app/Enums/EmploymentStatus.php` (the other four enums are plain backed enums with the cases listed in Interfaces — write all five):

```php
<?php
namespace App\Enums;

enum EmploymentStatus: string
{
    case Permanent = 'permanent';
    case Probationary1 = 'probationary_1';
    case Probationary2 = 'probationary_2';
    case Probationary3 = 'probationary_3';
    case Substitute = 'substitute';
    case Retiree = 'retiree';

    public static function fromLabel(string $raw): ?self
    {
        $label = strtolower(trim($raw));
        if (str_contains($label, 'substitute')) return self::Substitute;
        if (str_contains($label, 'retiree')) return self::Retiree;
        if (str_contains($label, 'new teacher')) return self::Probationary1;
        if (preg_match('/probationary\s*([1-3])/', $label, $m)) {
            return self::from('probationary_' . $m[1]);
        }
        if (str_contains($label, 'permanent')) return self::Permanent;
        return null;
    }

    public function label(): string
    {
        return match ($this) {
            self::Permanent => 'Permanent',
            self::Probationary1 => 'Probationary 1',
            self::Probationary2 => 'Probationary 2',
            self::Probationary3 => 'Probationary 3',
            self::Substitute => 'Substitute',
            self::Retiree => 'Retiree',
        };
    }
}
```

Migrations (string columns for enums; FKs constrained):

```php
// create_departments_table
Schema::create('departments', function (Blueprint $t) {
    $t->id(); $t->string('name'); $t->string('code')->unique();
    $t->unsignedTinyInteger('hours_per_section');
    $t->boolean('has_honors_class')->default(false); $t->timestamps();
});
// create_sections_table
Schema::create('sections', function (Blueprint $t) {
    $t->id(); $t->string('grade_level', 3); $t->string('name');
    $t->unique(['grade_level', 'name']); $t->timestamps();
});
// create_teachers_table
Schema::create('teachers', function (Blueprint $t) {
    $t->id(); $t->string('full_name'); $t->string('employment_status');
    $t->foreignId('department_id')->constrained(); $t->timestamps();
});
// add_role_fields_to_users_table
Schema::table('users', function (Blueprint $t) {
    $t->string('role')->default('department_chair')->after('password');
    $t->foreignId('department_id')->nullable()->after('role')->constrained();
    $t->boolean('is_active')->default(true)->after('department_id');
});
```

Models: `$fillable` for every listed column, casts (`role` => UserRole::class etc.), relations as named in Interfaces. `User::isAdmin()` = `$this->role === UserRole::SystemAdmin`; `isChair()` analogous. `Department::chair()` = `hasOne(User::class)->where('role', UserRole::DepartmentChair)`.

Factories: `DepartmentFactory` (fake name, unique 3–5 char code, hours 4, honors false), `SectionFactory` (grade G7, unique word name), `TeacherFactory` (name, status permanent, department factory), `UserFactory` add `'role' => UserRole::SystemAdmin, 'department_id' => null, 'is_active' => true` defaults plus state:

```php
public function chair(?Department $d = null): static
{
    return $this->state(fn () => [
        'role' => UserRole::DepartmentChair,
        'department_id' => ($d ?? Department::factory()->create())->id,
    ]);
}
```

- [ ] **Step 4: Run tests, verify pass** — `php artisan test` → PASS.
- [ ] **Step 5: Commit** — `git add -A && git commit -m "feat: enums, reference tables, core models"`

### Task 4: Remaining core tables + models

**Files:**
- Create migrations: `create_teacher_section_assignments_table`, `create_class_moderator_assignments_table`, `create_honors_class_assignments_table`, `create_other_assignment_roles_table`, `create_teacher_other_assignments_table`, `create_service_loads_table`, `create_plantilla_submissions_table`, `create_plantilla_uploads_table`, `create_plantilla_extraction_rows_table`, `create_system_constants_table`, `create_audit_logs_table`
- Create models: `TeacherSectionAssignment`, `ClassModeratorAssignment`, `HonorsClassAssignment`, `OtherAssignmentRole`, `TeacherOtherAssignment`, `ServiceLoad`, `PlantillaSubmission`, `PlantillaUpload`, `PlantillaExtractionRow`, `SystemConstant`, `AuditLog`
- Test: `tests/Feature/SchemaAssignmentsTest.php`

**Interfaces (produces):**
- `TeacherSectionAssignment`: `teacher_id, section_id, department_id, school_year, hours` (decimal); UNIQUE(section_id, department_id, school_year); relations `teacher()`, `section()`, `department()`
- `ClassModeratorAssignment`: `teacher_id, section_id, school_year, hours`; UNIQUE(section_id, school_year)
- `HonorsClassAssignment`: `teacher_id, section_id, school_year, hours`
- `OtherAssignmentRole`: `name` (unique), `equivalent_hours` (nullable decimal), `is_honorarium` (bool)
- `TeacherOtherAssignment`: `teacher_id, other_assignment_role_id, school_year`; relation `role()`
- `ServiceLoad`: `teacher_id, school_year, hours`
- `PlantillaSubmission`: `department_id, school_year, status` (cast SubmissionStatus, default draft), `submitted_by_user_id?, submitted_at?, returned_comment?, returned_by_user_id?`; UNIQUE(department_id, school_year); relation `department()`; static `currentFor(int $departmentId): self` (firstOrCreate for `SystemConstant::get('current_school_year')`)
- `PlantillaUpload`: `plantilla_submission_id, file_path, original_filename, extraction_status` (string: pending/extracted/reviewed/failed), `extracted_at?`; relations `submission()`, `rows()`
- `PlantillaExtractionRow`: `plantilla_upload_id, row_json` (cast array), `row_status` (cast ExtractionRowStatus)
- `SystemConstant`: `key` (unique), `value` (string), `description`; static `get(string $key, mixed $default = null): mixed` and `set(string $key, string $value): void`
- `AuditLog`: `user_id, action, auditable_type, auditable_id, before_json?, after_json?` (casts array), `created_at` only (`$timestamps = false`, `const UPDATED_AT = null` — use `$table->timestamp('created_at')`)

- [ ] **Step 1: Write failing test** — `tests/Feature/SchemaAssignmentsTest.php`:

```php
public function test_assignment_uniqueness_and_constant_lookup(): void
{
    $dept = Department::factory()->create(['hours_per_section' => 5]);
    $t = Teacher::factory()->create(['department_id' => $dept->id]);
    $s = Section::factory()->create();

    TeacherSectionAssignment::create(['teacher_id' => $t->id, 'section_id' => $s->id,
        'department_id' => $dept->id, 'school_year' => '2026-2027', 'hours' => 5]);
    $this->expectException(QueryException::class);
    TeacherSectionAssignment::create(['teacher_id' => Teacher::factory()->create(['department_id' => $dept->id])->id,
        'section_id' => $s->id, 'department_id' => $dept->id, 'school_year' => '2026-2027', 'hours' => 5]);
}

public function test_system_constant_get_set(): void
{
    SystemConstant::set('full_load_hours', '21');
    $this->assertSame('21', SystemConstant::get('full_load_hours'));
    $this->assertSame('x', SystemConstant::get('missing', 'x'));
}

public function test_submission_current_for_creates_draft(): void
{
    SystemConstant::set('current_school_year', '2026-2027');
    $dept = Department::factory()->create();
    $sub = PlantillaSubmission::currentFor($dept->id);
    $this->assertSame(SubmissionStatus::Draft, $sub->status);
    $this->assertSame($sub->id, PlantillaSubmission::currentFor($dept->id)->id);
}
```

- [ ] **Step 2: Run, verify FAIL.**
- [ ] **Step 3: Implement** migrations exactly per Interfaces (all school_year columns `string, 9`; hours `decimal(4,1)`; unique indexes as listed) and models with `$fillable`, casts, relations. `SystemConstant::get` reads with a per-request static cache; `PlantillaSubmission::currentFor` uses `firstOrCreate(['department_id' => $id, 'school_year' => SystemConstant::get('current_school_year')], ['status' => SubmissionStatus::Draft])`.
- [ ] **Step 4: Run, verify PASS.**
- [ ] **Step 5: Commit** — `git commit -am "feat: assignment, submission, staging, constants, audit tables"`

### Task 5: Role middleware, route groups, role-based redirect, empty dashboards

**Files:**
- Create: `app/Http/Middleware/EnsureUserRole.php`, `app/Http/Controllers/RoleRedirectController.php`, `app/Http/Controllers/Admin/DashboardController.php`, `app/Http/Controllers/DepartmentChair/DashboardController.php`
- Create: `resources/views/admin/dashboard.blade.php`, `resources/views/chair/dashboard.blade.php`
- Modify: `bootstrap/app.php`, `routes/web.php`
- Test: `tests/Feature/RbacRoutingTest.php`

**Interfaces (produces):** route names `admin.dashboard`, `chair.dashboard`; middleware alias `role:<value>`; Breeze's `/dashboard` becomes `RoleRedirectController` sending admin → `admin.dashboard`, chair → `chair.dashboard` (coordinator → 403 for now, with message "Coordinator features arrive in a later milestone"). Inactive users (`is_active=false`) are logged out with a 403 by `EnsureUserRole`.

- [ ] **Step 1: Write failing test** — `tests/Feature/RbacRoutingTest.php`:

```php
public function test_admin_reaches_admin_dashboard_and_not_chair(): void
{
    $admin = User::factory()->create(); // default role system_admin
    $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk();
    $this->actingAs($admin)->get(route('chair.dashboard'))->assertForbidden();
}

public function test_chair_reaches_chair_dashboard_and_not_admin(): void
{
    $chair = User::factory()->chair()->create();
    $this->actingAs($chair)->get(route('chair.dashboard'))->assertOk();
    $this->actingAs($chair)->get(route('admin.dashboard'))->assertForbidden();
}

public function test_dashboard_redirects_by_role(): void
{
    $chair = User::factory()->chair()->create();
    $this->actingAs($chair)->get('/dashboard')->assertRedirect(route('chair.dashboard'));
}

public function test_inactive_user_is_blocked(): void
{
    $chair = User::factory()->chair()->create(['is_active' => false]);
    $this->actingAs($chair)->get(route('chair.dashboard'))->assertForbidden();
}

public function test_guest_is_redirected_to_login(): void
{
    $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
}
```

- [ ] **Step 2: Run, verify FAIL.**
- [ ] **Step 3: Implement**

`EnsureUserRole`:

```php
public function handle(Request $request, Closure $next, string $role): Response
{
    $user = $request->user();
    abort_unless($user && $user->is_active, 403);
    abort_unless($user->role->value === $role, 403);
    return $next($request);
}
```

`bootstrap/app.php`: `$middleware->alias(['role' => \App\Http\Middleware\EnsureUserRole::class]);`

`routes/web.php` (replace Breeze's dashboard route; keep profile routes):

```php
Route::get('/dashboard', RoleRedirectController::class)->middleware('auth')->name('dashboard');

Route::middleware(['auth', 'role:system_admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [Admin\DashboardController::class, 'index'])->name('dashboard');
});

Route::middleware(['auth', 'role:department_chair'])->prefix('chair')->name('chair.')->group(function () {
    Route::get('/', [DepartmentChair\DashboardController::class, 'index'])->name('dashboard');
});
```

`RoleRedirectController::__invoke`: match on `$request->user()->role` → `redirect()->route('admin.dashboard')` / `chair.dashboard` / `abort(403, 'Coordinator features arrive in a later milestone.')`.

Dashboard controllers return their views; views extend Breeze's `<x-app-layout>` with an `<h1>` placeholder ("System Administration" / "Department Dashboard"). Update Breeze's nav partial links to point at `route('dashboard')`.

- [ ] **Step 4: Run, verify PASS.**
- [ ] **Step 5: Commit** — `git commit -am "feat: role middleware, route groups, role-based redirect"`

### Task 6: Seeders (real SY 2026-2027 structure + demo accounts)

**Files:** Create: `database/seeders/{DepartmentSeeder,SectionSeeder,OtherAssignmentRoleSeeder,SystemConstantSeeder,UserSeeder}.php`; Modify: `DatabaseSeeder.php`; Test: `tests/Feature/SeedTest.php`

**Interfaces (produces):** the 7 departments by code `FIL, CLE, TLE, SCI, MATH, MAPEH, SOC`; demo accounts `admin@jhs.test` and `chair.<lowercase-code>@jhs.test` (e.g. `chair.fil@jhs.test`), password `password` for all.

- [ ] **Step 1: Write failing test**

```php
public function test_seeded_structure(): void
{
    $this->seed();
    $this->assertSame(7, Department::count());
    $this->assertSame(8, User::count()); // 1 admin + 7 chairs
    $this->assertSame(85, Section::count()); // 19 G7 + 36 G8 + 20 G9 + 10 G10
    $this->assertTrue(Department::where('code', 'SCI')->first()->has_honors_class);
    $this->assertSame(5, Department::where('code', 'MATH')->first()->hours_per_section);
    $this->assertSame('2026-2027', SystemConstant::get('current_school_year'));
    $this->assertTrue(OtherAssignmentRole::where('name', 'Department Chair')->first()->equivalent_hours == 15);
    $this->assertTrue((bool) OtherAssignmentRole::where('name', 'Sports Club Moderator')->first()->is_honorarium);
}
```

- [ ] **Step 2: Run, verify FAIL.**
- [ ] **Step 3: Implement seeders** (data verbatim from the vault docs):

`DepartmentSeeder` — updateOrCreate by code:

| code | name | hours_per_section | has_honors_class |
|---|---|---|---|
| FIL | Filipino | 4 | false |
| CLE | Christian Life Education | 4 | false |
| TLE | Technology and Livelihood Education | 4 | true |
| SCI | Science and Technology | 5 | true |
| MATH | Mathematics | 5 | true |
| MAPEH | MAPEH | 4 | true |
| SOC | Social Studies | 4 | true |

`SectionSeeder` — exact lists from `docs/Documentation/JHS Sections Directory.md` (canonical spellings: De Britto, Anchieta, Colombiere, Berchmans, Arrowsmith):

```php
private array $sections = [
    'G7' => ['Arrowsmith','Bellarmine','Borgia','Briant','Campion','Claver','De Britto','Ignatius','Jogues','Lewis','Miki','Ogilvie','Paul','Pignatelli','Pongracz','Realino','Regis','Rubio','Xavier'],
    'G8' => ['Anchieta','Arrowsmith','Bellarmine','Berchmans','Borgia','Brebeuf','Briant','Campion','Canisius','Chabanel','Claver','Colombiere','De Britto','Evans','Faber','Garnet','Goupil','Hurtado','Ignatius','Jerome','Jogues','Kostka','Lewis','Loyola','Mayer','Miki','Morse','Ogilvie','Owen','Pignatelli','Pongracz','Realino','Regis','Rodriguez','Southwell','Xavier'],
    'G9' => ['Anchieta','Berchmans','Brebeuf','Campion','Canisius','Chabanel','Colombiere','Daniel','Evans','Faber','Garnet','Goupil','Hurtado','Jerome','Kostka','Mayer','Morse','Owen','Rodriguez','Southwell'],
    'G10' => ['Berchmans','Canisius','Chabanel','Colombiere','Daniel','Faber','Hurtado','Jogues','Mayer','Southwell'],
];
```

`OtherAssignmentRoleSeeder` — equivalent-hours roles (is_honorarium=false): Department Chair 15, Grade Level Leader 6, AMEP 6, Faculty Development 15, Quality Assurance Officer 6, HSR Coordinator 21, Facilities Coordinator 15, OPD 15, Admission and Aid Coordinator 15, TLE Coordinator 21, SAO Coordinator 21, Social Studies Subject Area Coordinator 15. Honorarium-only (is_honorarium=true, equivalent_hours=null): Sports Club Moderator, Culinaria Club Moderator, Eagle's Eye Club Moderator, Animo Aguila Moderator, Danzar Atenista Moderator, Artique Circle Moderator, Musica de Aguilas Club Moderator, Youth for Christ Moderator, RCY Moderator, JES Moderator, LLA Moderator, ITS Moderator, Punlaan Moderator. (Admin can correct any value later — that's the CRUD page.)

`SystemConstantSeeder`: `full_load_hours=21` ("Weekly full teaching load"), `overload_divisor=3` ("UNCONFIRMED — inferred from observed data, confirm with registrar"), `service_load_default=3`, `class_moderator_hours=3`, `honors_class_hours=8`, `current_school_year=2026-2027`.

`UserSeeder`: `admin@jhs.test` (System Administrator) + one chair per department, name "«Dept name» Chair", email `chair.«lowercase code»@jhs.test`, all password `password`, wired to their `department_id`.

`DatabaseSeeder` calls the five in the order above.

- [ ] **Step 4: Run, verify PASS**, then seed dev MySQL: `php artisan db:seed`.
- [ ] **Step 5: Commit** — `git commit -am "feat: seed departments, sections, roles, constants, demo accounts"`

### Task 7: CHECKPOINT — scaffold review

- [ ] **Step 1:** Run `php artisan test` (all green), `php artisan serve`, and verify manually: login as `admin@jhs.test` / `password` → admin placeholder dashboard; login as `chair.fil@jhs.test` → chair placeholder dashboard.
- [ ] **Step 2:** **STOP.** Present the directory structure (`git ls-files` tree of `app/`, `database/`, `resources/views/`, `routes/`) to the user for their structure check. Do not begin Phase 2 until they approve.

---

## Phase 2 — System Administrator

### Task 8: AuditLogService

**Files:** Create: `app/Services/Audit/AuditLogService.php`; Test: `tests/Unit/AuditLogServiceTest.php`

**Interfaces (produces):**
```php
AuditLogService::log(string $action, Model $auditable, ?array $before = null, ?array $after = null): AuditLog
```
Records `auth()->id()` (nullable when unauthenticated, e.g. seeding), morph type/id, JSON snapshots, `created_at=now()`.

- [ ] **Step 1: Failing test**

```php
public function test_log_writes_actor_and_diffs(): void
{
    $admin = User::factory()->create();
    $this->actingAs($admin);
    $dept = Department::factory()->create();

    $log = app(AuditLogService::class)->log('department.updated', $dept,
        ['name' => 'Old'], ['name' => 'New']);

    $this->assertSame($admin->id, $log->user_id);
    $this->assertSame(Department::class, $log->auditable_type);
    $this->assertSame(['name' => 'New'], $log->after_json);
}
```

- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Implement — single method creating the `AuditLog` row.
- [ ] **Step 4:** Run → PASS.
- [ ] **Step 5:** `git commit -am "feat: audit log service"`

### Task 9: UserProvisioningService

**Files:** Create: `app/Services/Auth/UserProvisioningService.php`; Test: `tests/Unit/UserProvisioningServiceTest.php`

**Interfaces (produces):**
```php
create(array $data): User        // name, email, password(plain), role(UserRole), department_id?
update(User $user, array $data): User   // same keys, password optional
setActive(User $user, bool $active): User
```
Rules enforced in the service: chairs require `department_id`; non-chairs get `department_id=null` forced; every call audit-logs (`user.created`, `user.updated`, `user.deactivated`/`user.reactivated`); passwords hashed.

- [ ] **Step 1: Failing test**

```php
public function test_create_chair_requires_department(): void
{
    $this->actingAs(User::factory()->create());
    $svc = app(UserProvisioningService::class);
    $dept = Department::factory()->create();

    $chair = $svc->create(['name' => 'C', 'email' => 'c@x.test', 'password' => 'secret123',
        'role' => UserRole::DepartmentChair, 'department_id' => $dept->id]);
    $this->assertSame($dept->id, $chair->department_id);

    $this->expectException(InvalidArgumentException::class);
    $svc->create(['name' => 'D', 'email' => 'd@x.test', 'password' => 'secret123',
        'role' => UserRole::DepartmentChair]);
}

public function test_admin_department_is_nulled_and_actions_audited(): void
{
    $this->actingAs(User::factory()->create());
    $svc = app(UserProvisioningService::class);
    $u = $svc->create(['name' => 'A', 'email' => 'a@x.test', 'password' => 'secret123',
        'role' => UserRole::SystemAdmin, 'department_id' => Department::factory()->create()->id]);
    $this->assertNull($u->department_id);

    $svc->setActive($u, false);
    $this->assertFalse($u->fresh()->is_active);
    $this->assertDatabaseHas('audit_logs', ['action' => 'user.deactivated', 'auditable_id' => $u->id]);
}
```

- [ ] **Step 2:** Run → FAIL. **Step 3:** Implement (constructor-inject `AuditLogService`). **Step 4:** Run → PASS.
- [ ] **Step 5:** `git commit -am "feat: user provisioning service"`

### Task 10: Admin dashboard + User Directory (list/search/filter)

**Files:**
- Modify: `app/Http/Controllers/Admin/DashboardController.php`; Create: `app/Http/Controllers/Admin/UserController.php` (`index` only this task)
- Create/modify views: `admin/dashboard.blade.php`, `admin/users/index.blade.php`; nav partial links
- Modify: `routes/web.php`; Test: `tests/Feature/Admin/UserDirectoryTest.php`

**Interfaces:** Consumes seeded data. Produces routes `admin.users.index` (filters: `?q=` name/email substring, `?role=`, `?department=`).

- [ ] **Step 1: Failing test**

```php
public function test_directory_lists_and_filters(): void
{
    $this->seed();
    $admin = User::where('email', 'admin@jhs.test')->first();

    $this->actingAs($admin)->get(route('admin.users.index'))
        ->assertOk()->assertSee('chair.fil@jhs.test');

    $this->actingAs($admin)->get(route('admin.users.index', ['q' => 'chair.math']))
        ->assertSee('chair.math@jhs.test')->assertDontSee('chair.fil@jhs.test');

    $this->actingAs($admin)->get(route('admin.users.index', ['role' => 'system_admin']))
        ->assertSee('admin@jhs.test')->assertDontSee('chair.fil@jhs.test');
}

public function test_chair_cannot_open_directory(): void
{
    $this->seed();
    $chair = User::where('email', 'chair.fil@jhs.test')->first();
    $this->actingAs($chair)->get(route('admin.users.index'))->assertForbidden();
}
```

- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Implement. Dashboard controller passes counts (`User::count()`, `Department::count()`, submissions grouped by status) to the view as stat cards. `UserController@index`:

```php
$users = User::with('department')
    ->when($request->q, fn ($q, $s) => $q->where(fn ($w) =>
        $w->where('name', 'like', "%$s%")->orWhere('email', 'like', "%$s%")))
    ->when($request->role, fn ($q, $r) => $q->where('role', $r))
    ->when($request->department, fn ($q, $d) => $q->where('department_id', $d))
    ->orderBy('name')->paginate(25)->withQueryString();
```

View: Tailwind table (Name, Email, Role, Department, Active badge, Edit link) + a filter form (text input, role select, department select). Add routes inside the existing `admin.` group.

- [ ] **Step 4:** Run → PASS. **Step 5:** `git commit -am "feat: admin dashboard stats and user directory"`

### Task 11: Create/Edit/Deactivate users

**Files:**
- Modify: `app/Http/Controllers/Admin/UserController.php` (add `create,store,edit,update,toggleActive`)
- Create: `app/Http/Requests/Admin/StoreUserRequest.php`, `UpdateUserRequest.php`; view `admin/users/form.blade.php`
- Modify: `routes/web.php`; Test: `tests/Feature/Admin/UserCrudTest.php`

**Interfaces:** Consumes `UserProvisioningService` (Task 9 signatures). Produces routes `admin.users.create/store/edit/update` + `admin.users.toggle` (PATCH `users/{user}/toggle`).

- [ ] **Step 1: Failing test**

```php
public function test_admin_creates_chair(): void
{
    $this->seed();
    $admin = User::where('email', 'admin@jhs.test')->first();
    $dept = Department::where('code', 'FIL')->first();

    $this->actingAs($admin)->post(route('admin.users.store'), [
        'name' => 'New Chair', 'email' => 'new@jhs.test', 'password' => 'secret1234',
        'role' => 'department_chair', 'department_id' => $dept->id,
    ])->assertRedirect(route('admin.users.index'));

    $this->assertDatabaseHas('users', ['email' => 'new@jhs.test', 'department_id' => $dept->id]);
}

public function test_validation_requires_department_for_chair(): void
{
    $this->seed();
    $admin = User::where('email', 'admin@jhs.test')->first();
    $this->actingAs($admin)->post(route('admin.users.store'), [
        'name' => 'X', 'email' => 'x@jhs.test', 'password' => 'secret1234',
        'role' => 'department_chair',
    ])->assertSessionHasErrors('department_id');
}

public function test_toggle_deactivates(): void
{
    $this->seed();
    $admin = User::where('email', 'admin@jhs.test')->first();
    $chair = User::where('email', 'chair.fil@jhs.test')->first();
    $this->actingAs($admin)->patch(route('admin.users.toggle', $chair));
    $this->assertFalse($chair->fresh()->is_active);
}
```

- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Implement. `StoreUserRequest` rules: `name required`, `email required|email|unique:users`, `password required|min:8`, `role required|in:system_admin,department_chair,academic_coordinator`, `department_id required_if:role,department_chair|nullable|exists:departments,id`. `UpdateUserRequest`: same minus password requirement, email unique ignoring self. Controller delegates to `UserProvisioningService`. Form view: one Blade form for create/edit; Alpine shows the department select only when role == department_chair (`x-data`/`x-show`).
- [ ] **Step 4:** Run → PASS. **Step 5:** `git commit -am "feat: user create/edit/deactivate"`

### Task 12: System Constants page

**Files:** Create: `app/Http/Controllers/Admin/SystemConstantController.php` (`index`, `update`), view `admin/constants/index.blade.php`, request `UpdateSystemConstantRequest` (`value required|string|max:50`); Modify routes; Test: `tests/Feature/Admin/SystemConstantsTest.php`

**Interfaces:** Produces routes `admin.constants.index`, `admin.constants.update` (PATCH `constants/{systemConstant}`). Updates audit-log as `constant.updated` with before/after.

- [ ] **Step 1: Failing test**

```php
public function test_admin_updates_constant_and_is_audited(): void
{
    $this->seed();
    $admin = User::where('email', 'admin@jhs.test')->first();
    $const = SystemConstant::where('key', 'overload_divisor')->first();

    $this->actingAs($admin)->get(route('admin.constants.index'))
        ->assertOk()->assertSee('overload_divisor')->assertSee('UNCONFIRMED');

    $this->actingAs($admin)->patch(route('admin.constants.update', $const), ['value' => '4'])
        ->assertRedirect();
    $this->assertSame('4', SystemConstant::get('overload_divisor'));
    $this->assertDatabaseHas('audit_logs', ['action' => 'constant.updated']);
}
```

- [ ] **Step 2:** FAIL. **Step 3:** Implement — table of key / value (inline edit form per row) / description. **Step 4:** PASS.
- [ ] **Step 5:** `git commit -am "feat: system constants admin page"`

### Task 13: Role → Equivalent Hours lookup CRUD

**Files:** Create: `app/Http/Controllers/Admin/AssignmentRoleController.php` (resource minus show/destroy → `index,create,store,edit,update`), views `admin/roles/{index,form}.blade.php`, request `StoreAssignmentRoleRequest` (`name required|unique`, `equivalent_hours nullable|numeric|min:0|prohibited_if:is_honorarium,true`, `is_honorarium boolean`); Modify routes; Test: `tests/Feature/Admin/AssignmentRoleTest.php`

**Interfaces:** Produces routes `admin.roles.*`. Honorarium roles must have null hours (enforced by validation), matching SRS FR-7.

- [ ] **Step 1: Failing test**

```php
public function test_crud_role_lookup(): void
{
    $this->seed();
    $admin = User::where('email', 'admin@jhs.test')->first();

    $this->actingAs($admin)->post(route('admin.roles.store'), [
        'name' => 'Test Coordinator', 'equivalent_hours' => 12, 'is_honorarium' => 0,
    ])->assertRedirect(route('admin.roles.index'));
    $this->assertDatabaseHas('other_assignment_roles', ['name' => 'Test Coordinator']);

    $this->actingAs($admin)->post(route('admin.roles.store'), [
        'name' => 'Bad Club', 'equivalent_hours' => 5, 'is_honorarium' => 1,
    ])->assertSessionHasErrors('equivalent_hours');
}
```

- [ ] **Step 2:** FAIL. **Step 3:** Implement (index table shows name, hours or "— honorarium", edit links; audit-log writes). **Step 4:** PASS.
- [ ] **Step 5:** `git commit -am "feat: assignment role lookup CRUD"`

### Task 14: Audit Log viewer

**Files:** Create: `app/Http/Controllers/Admin/AuditLogController.php` (`index`), view `admin/audit/index.blade.php`; Modify routes; Test: `tests/Feature/Admin/AuditViewerTest.php`

**Interfaces:** Produces route `admin.audit.index` with filters `?action=`, `?user=`, `?from=&to=` (dates). Table newest-first, paginated 50, before/after JSON rendered in a `<details>` block.

- [ ] **Step 1: Failing test**

```php
public function test_viewer_lists_and_filters_actions(): void
{
    $this->seed();
    $admin = User::where('email', 'admin@jhs.test')->first();
    $this->actingAs($admin);
    app(AuditLogService::class)->log('user.created', $admin);
    app(AuditLogService::class)->log('constant.updated', $admin);

    $this->get(route('admin.audit.index'))->assertOk()->assertSee('user.created');
    $this->get(route('admin.audit.index', ['action' => 'constant.updated']))
        ->assertSee('constant.updated')->assertDontSee('user.created');
}
```

- [ ] **Step 2:** FAIL. **Step 3:** Implement. **Step 4:** PASS.
- [ ] **Step 5:** `git commit -am "feat: audit log viewer"` — **Phase 2 complete; demo the admin area to the user.**

---

## Phase 3 — Department Chair

### Task 15: Chair scoping guarantees (RBAC boundary tests) + submission policy

**Files:** Create: `app/Policies/PlantillaSubmissionPolicy.php`, `tests/Feature/Chair/ScopeBoundaryTest.php`; Modify: `app/Providers/AppServiceProvider.php` (policy registration if not auto-discovered)

**Interfaces (produces):**
- `PlantillaSubmissionPolicy::update(User $user, PlantillaSubmission $s): bool` — chair, own department, status Draft or Returned. All chair mutating controllers call `$this->authorize('update', $submission)`.
- Convention consumed by every later chair task: controllers resolve `$department = $request->user()->department` and `$submission = PlantillaSubmission::currentFor($department->id)`; queries filter by `$department->id`. Route model bindings for chair-owned records must verify ownership and 404 on mismatch.

- [ ] **Step 1: Failing test** (drives conventions later tasks obey)

```php
public function test_policy_allows_own_draft_only(): void
{
    $this->seed();
    $fil = User::where('email', 'chair.fil@jhs.test')->first();
    $cle = User::where('email', 'chair.cle@jhs.test')->first();
    $sub = PlantillaSubmission::currentFor($fil->department_id);

    $this->assertTrue($fil->can('update', $sub));
    $this->assertFalse($cle->can('update', $sub));

    $sub->update(['status' => SubmissionStatus::Submitted]);
    $this->assertFalse($fil->fresh()->can('update', $sub->fresh()));

    $sub->update(['status' => SubmissionStatus::Returned]);
    $this->assertTrue($fil->fresh()->can('update', $sub->fresh()));
}
```

- [ ] **Step 2:** FAIL. **Step 3:** Implement policy. **Step 4:** PASS.
- [ ] **Step 5:** `git commit -am "feat: plantilla submission policy"`

### Task 16: LoadCalculationService

**Files:** Create: `app/Services/Curriculum/LoadCalculationService.php`; Test: `tests/Unit/LoadCalculationServiceTest.php`

**Interfaces (produces):**
```php
forTeacher(Teacher $teacher, string $schoolYear): array
// returns: ['teaching_hours' => float, 'nonteaching_hours' => float,
//           'total_hours' => float, 'overload_units' => float,
//           'section_count' => int, 'flags' => string[]]
```
Formula per constraints doc §3: teaching = Σ assignment hours + CM hours + HC hours; nonteaching = service load + Σ non-honorarium role equivalent_hours; overload = (total − full_load_hours) / overload_divisor, min 0, rounded to 2dp. Flags: `'zero_sections'` when no assignments; `'no_service_load'` when no ServiceLoad row; `'below_full_load'` / `'overloaded'` vs `full_load_hours`. Honorarium roles contribute 0.

- [ ] **Step 1: Failing test**

```php
public function test_full_formula(): void
{
    $this->seed(SystemConstantSeeder::class);
    $dept = Department::factory()->create(['hours_per_section' => 5]);
    $t = Teacher::factory()->create(['department_id' => $dept->id]);
    $sy = '2026-2027';
    foreach (Section::factory()->count(3)->create() as $s) {
        TeacherSectionAssignment::create(['teacher_id' => $t->id, 'section_id' => $s->id,
            'department_id' => $dept->id, 'school_year' => $sy, 'hours' => 5]);
    }
    ClassModeratorAssignment::create(['teacher_id' => $t->id,
        'section_id' => Section::factory()->create()->id, 'school_year' => $sy, 'hours' => 3]);
    ServiceLoad::create(['teacher_id' => $t->id, 'school_year' => $sy, 'hours' => 3]);
    $gll = OtherAssignmentRole::create(['name' => 'GLL-T', 'equivalent_hours' => 6, 'is_honorarium' => false]);
    $club = OtherAssignmentRole::create(['name' => 'Club-T', 'equivalent_hours' => null, 'is_honorarium' => true]);
    TeacherOtherAssignment::create(['teacher_id' => $t->id, 'other_assignment_role_id' => $gll->id, 'school_year' => $sy]);
    TeacherOtherAssignment::create(['teacher_id' => $t->id, 'other_assignment_role_id' => $club->id, 'school_year' => $sy]);

    $r = app(LoadCalculationService::class)->forTeacher($t, $sy);
    $this->assertEquals(18.0, $r['teaching_hours']);      // 15 + 3
    $this->assertEquals(9.0, $r['nonteaching_hours']);    // 3 + 6, club excluded
    $this->assertEquals(27.0, $r['total_hours']);
    $this->assertEquals(2.0, $r['overload_units']);       // (27-21)/3
    $this->assertContains('overloaded', $r['flags']);
}

public function test_zero_section_teacher_is_flagged_not_an_error(): void
{
    $this->seed(SystemConstantSeeder::class);
    $t = Teacher::factory()->create();
    $r = app(LoadCalculationService::class)->forTeacher($t, '2026-2027');
    $this->assertEquals(0.0, $r['teaching_hours']);
    $this->assertContains('zero_sections', $r['flags']);
    $this->assertContains('no_service_load', $r['flags']);
}
```

- [ ] **Step 2:** FAIL. **Step 3:** Implement, reading constants via `SystemConstant::get`. **Step 4:** PASS.
- [ ] **Step 5:** `git commit -am "feat: load calculation service"`

### Task 17: PdfExtractionService

**Files:** Create: `app/Services/Plantilla/PdfExtractionService.php`; Copy fixture: `cp "docs/Plantillas/KAGAWARAN PLANTILLA .docx.pdf" tests/Fixtures/filipino-plantilla.pdf`; Test: `tests/Unit/PdfExtractionServiceTest.php`

**Interfaces (produces):**
```php
extract(string $absolutePdfPath): array
// list of rows; each row:
// ['teacher_name' => ?string, 'employment_status' => ?string, 'sections' => ?string,
//  'cm' => ?string, 'hc' => ?string, 'service_load' => ?string,
//  'other_assignment' => ?string, 'flagged' => bool]
```
`flagged=true` when the parser can't confidently split a row's fields (missing name or nothing but the row number). Throws `App\Services\Plantilla\ExtractionFailedException` (create it, extends RuntimeException) when the PDF yields no text at all (scanned image case).

- [ ] **Step 1: Install parser** — `composer require smalot/pdfparser`

- [ ] **Step 2: Failing test** (deliberately tolerant, per SRS §6.2 — extraction is best-effort, the review grid absorbs imperfection):

```php
public function test_extracts_rows_from_real_filipino_plantilla(): void
{
    $rows = app(PdfExtractionService::class)->extract(base_path('tests/Fixtures/filipino-plantilla.pdf'));

    $this->assertGreaterThanOrEqual(5, count($rows));
    $names = implode('|', array_column($rows, 'teacher_name'));
    $this->assertStringContainsString('Bilbar', $names);
    foreach ($rows as $row) {
        $this->assertArrayHasKey('flagged', $row);
    }
}

public function test_textless_pdf_throws(): void
{
    $path = tempnam(sys_get_temp_dir(), 'pdf');
    file_put_contents($path, '%PDF-1.4 empty');
    $this->expectException(ExtractionFailedException::class);
    app(PdfExtractionService::class)->extract($path);
}
```

- [ ] **Step 3:** Run → FAIL.
- [ ] **Step 4: Implement** heuristic parser:

```php
public function extract(string $absolutePdfPath): array
{
    try {
        $text = (new \Smalot\PdfParser\Parser())->parseFile($absolutePdfPath)->getText();
    } catch (\Throwable $e) {
        throw new ExtractionFailedException('Unreadable PDF: ' . $e->getMessage(), previous: $e);
    }
    if (trim($text) === '') {
        throw new ExtractionFailedException('PDF contains no extractable text (scanned image?).');
    }

    $lines = array_values(array_filter(array_map('trim', explode("\n", $text))));
    $rows = [];
    $current = null;
    foreach ($lines as $line) {
        if (preg_match('/^(\d{1,2})[\s.]+([A-ZÑa-zñ].*)$/u', $line, $m) && (int) $m[1] <= 30) {
            if ($current) $rows[] = $this->finalize($current);
            $current = ['number' => (int) $m[1], 'buffer' => [$m[2]]];
        } elseif ($current) {
            $current['buffer'][] = $line;
        }
    }
    if ($current) $rows[] = $this->finalize($current);
    return $rows;
}

private function finalize(array $raw): array
{
    $joined = implode(' ', $raw['buffer']);
    $status = null;
    if (preg_match('/(FT\s+)?(Permanent( Teacher)?|Probationary\s*[1-3]|New Teacher|Substitute[^,]*|Retiree)/i', $joined, $m)) {
        $status = trim($m[0]);
    }
    // name = leading text up to the status keyword (or first 40 chars as fallback)
    $name = $status ? trim(mb_substr($joined, 0, mb_strpos($joined, $m[0]))) : null;
    $sections = null;
    if (preg_match_all('/G(?:rade\s*)?(7|8|9|10)\s*:?\s*([A-Za-zñÑ,\s]+?)(?=G(?:rade\s*)?(?:7|8|9|10)|$)/u', $joined, $sm, PREG_SET_ORDER)) {
        $sections = implode('; ', array_map(fn ($s) => 'G' . $s[1] . ': ' . trim($s[2], " ,"), $sm));
    }
    return [
        'teacher_name' => $name ?: null,
        'employment_status' => $status,
        'sections' => $sections,
        'cm' => null, 'hc' => null,           // column attribution unreliable in flat text — Chair fills in
        'service_load' => null,
        'other_assignment' => null,
        'flagged' => $name === null || $sections === null,
    ];
}
```

(This is intentionally modest: names + statuses + grade-section strings parse reliably from the flat text; CM/HC/service-load column attribution does not, so those stay null/flagged for the Chair — exactly what the review gate is for. Improving the parser later never changes the interface.)

- [ ] **Step 5:** Run → PASS. **Step 6:** `git commit -am "feat: plantilla PDF extraction service"`

### Task 18: Upload page → extraction → staging rows

**Files:**
- Create: `app/Http/Controllers/DepartmentChair/PlantillaUploadController.php` (`create`, `store`), view `chair/plantilla/upload.blade.php`, request `StorePlantillaUploadRequest` (`pdf required|file|mimes:pdf|max:10240`)
- Modify: `routes/web.php`; Test: `tests/Feature/Chair/PlantillaUploadTest.php`

**Interfaces:** Consumes `PdfExtractionService::extract`, `PlantillaSubmission::currentFor`. Produces routes `chair.plantilla.create` (GET `/chair/plantilla/upload`), `chair.plantilla.store` (POST). Store: authorize `update` on current submission → save file to `storage/app/plantillas/{dept-code}/` → create `PlantillaUpload` (status `pending`) → run extraction → bulk-insert `PlantillaExtractionRow`s (`row_status` = `flagged` when `row['flagged']` else `extracted`, `row_json` = the row array) → upload status `extracted`, `extracted_at=now()` → redirect `chair.plantilla.review`. On `ExtractionFailedException`: upload status `failed`, still redirect to review with a warning flash (Chair enters rows manually).

- [ ] **Step 1: Failing test**

```php
public function test_upload_extracts_to_staging(): void
{
    $this->seed();
    Storage::fake('local');
    $chair = User::where('email', 'chair.fil@jhs.test')->first();
    $pdf = new \Illuminate\Http\Testing\File('plantilla.pdf',
        fopen(base_path('tests/Fixtures/filipino-plantilla.pdf'), 'r'));

    $this->actingAs($chair)->post(route('chair.plantilla.store'), ['pdf' => $pdf])
        ->assertRedirect(route('chair.plantilla.review'));

    $upload = PlantillaUpload::first();
    $this->assertSame('extracted', $upload->extraction_status);
    $this->assertGreaterThanOrEqual(5, $upload->rows()->count());
}

public function test_upload_blocked_when_submitted(): void
{
    $this->seed();
    $chair = User::where('email', 'chair.fil@jhs.test')->first();
    PlantillaSubmission::currentFor($chair->department_id)
        ->update(['status' => SubmissionStatus::Submitted]);

    $this->actingAs($chair)->post(route('chair.plantilla.store'), [
        'pdf' => \Illuminate\Http\UploadedFile::fake()->create('x.pdf', 10, 'application/pdf'),
    ])->assertForbidden();
}
```

- [ ] **Step 2:** FAIL. **Step 3:** Implement per Interfaces. Upload view: drag/drop-styled file input + submit. **Step 4:** PASS.
- [ ] **Step 5:** `git commit -am "feat: plantilla upload with extraction to staging"`

### Task 19: Review & Correction grid

**Files:**
- Create: `app/Http/Controllers/DepartmentChair/PlantillaReviewController.php` (`show`, `updateRow`, `storeRow`, `destroyRow`), view `chair/plantilla/review.blade.php`, request `UpdateExtractionRowRequest`
- Modify routes; Test: `tests/Feature/Chair/PlantillaReviewTest.php`

**Interfaces:** Produces routes `chair.plantilla.review` (GET `/chair/plantilla/review`), `chair.plantilla.rows.update` (PATCH `/chair/plantilla/rows/{row}`), `chair.plantilla.rows.store` (POST), `chair.plantilla.rows.destroy` (DELETE). Row fields (all nullable strings validated `max:500`, `employment_status` additionally `in:` the canonical labels or raw source variants): `teacher_name, employment_status, sections, cm, hc, service_load, other_assignment`. A successful row update sets `row_status` to `extracted` (unflags); `flagged` rows render with an amber highlight. Ownership check: the row's upload's submission must belong to the chair's department (404 otherwise). Task 20 consumes rows via `row_json` with these exact keys.

- [ ] **Step 1: Failing test**

```php
public function test_chair_edits_flagged_row(): void
{
    $this->seed();
    $chair = User::where('email', 'chair.fil@jhs.test')->first();
    $upload = $this->makeUploadFor($chair, [['teacher_name' => null, 'employment_status' => null,
        'sections' => null, 'cm' => null, 'hc' => null, 'service_load' => null,
        'other_assignment' => null, 'flagged' => true]]);
    $row = $upload->rows()->first();

    $this->actingAs($chair)->patch(route('chair.plantilla.rows.update', $row), [
        'teacher_name' => 'Leah Angelic C. Bilbar', 'employment_status' => 'Permanent',
        'sections' => 'G7: Ignatius',
    ])->assertRedirect();

    $row->refresh();
    $this->assertSame('Leah Angelic C. Bilbar', $row->row_json['teacher_name']);
    $this->assertSame(ExtractionRowStatus::Extracted, $row->row_status);
}

public function test_other_chair_cannot_touch_row(): void
{
    $this->seed();
    $fil = User::where('email', 'chair.fil@jhs.test')->first();
    $cle = User::where('email', 'chair.cle@jhs.test')->first();
    $row = $this->makeUploadFor($fil, [['teacher_name' => 'X', 'flagged' => false]])->rows()->first();

    $this->actingAs($cle)->patch(route('chair.plantilla.rows.update', $row),
        ['teacher_name' => 'Hacked'])->assertNotFound();
}
```

(`makeUploadFor` is a private test helper creating submission → upload → rows for the chair's department.)

- [ ] **Step 2:** FAIL. **Step 3:** Implement. Review view: table of staged rows, each row an Alpine-toggled inline `<form>` (view mode ↔ edit mode with inputs for the 7 fields; employment status is a `<select>` of canonical labels), flagged rows `bg-amber-50` with an "incomplete" badge, "Add row" button appending an empty form (POST `rows.store`), delete button per row, and a prominent "Confirm & Import" button posting to the Task 20 route (rendered disabled until Task 20 adds it — include the button now pointing at `chair.plantilla.confirm`). **Step 4:** PASS.
- [ ] **Step 5:** `git commit -am "feat: plantilla review and correction grid"`

### Task 20: Confirm & Import (PlantillaReviewService)

**Files:** Create: `app/Services/Plantilla/PlantillaReviewService.php`, controller method `PlantillaReviewController@confirm`; Modify routes (`chair.plantilla.confirm`, POST `/chair/plantilla/confirm`); Test: `tests/Unit/PlantillaReviewServiceTest.php`, extend `tests/Feature/Chair/PlantillaReviewTest.php`

**Interfaces (produces):**
```php
PlantillaReviewService::confirmImport(PlantillaUpload $upload): array
// returns ['imported' => int, 'skipped' => array<string>] — skipped = human-readable reasons
```
Behavior, all inside one `DB::transaction`:
1. For each staging row (skip rows whose `teacher_name` is still null → add to `skipped`): canonicalize status via `EmploymentStatus::fromLabel` (null → `skipped` reason); `Teacher::updateOrCreate(['full_name' => …, 'department_id' => dept], ['employment_status' => …])`.
2. Parse `sections` string (`G7: Ignatius, Xavier; G9: Kostka` — split `;` then `Gn:` prefix then commas): each name → `Section::firstOrCreate(['grade_level' => …, 'name' => …])` → `TeacherSectionAssignment::updateOrCreate(['section_id','department_id','school_year'], ['teacher_id','hours' => dept->hours_per_section])`.
3. `cm` non-null: parse as `Gn: Name` single section → `ClassModeratorAssignment::updateOrCreate(['section_id','school_year'], ['teacher_id','hours' => SystemConstant::get('class_moderator_hours')])`.
4. `hc` non-null and `department->has_honors_class`: same shape with `honors_class_hours`; if dept lacks HC → `skipped` reason, no write.
5. `service_load`: numeric value → `ServiceLoad::updateOrCreate(['teacher_id','school_year'], ['hours'])`; blank/`-` → no row (that's the waived case from constraints doc §6).
6. `other_assignment` non-null: match `OtherAssignmentRole` by case-insensitive name containment; matched → `TeacherOtherAssignment::firstOrCreate`; unmatched → `skipped` reason.
7. Rows imported get `row_status=confirmed`; upload `extraction_status=reviewed`; audit-log `plantilla.imported` with counts.

Any thrown exception rolls the whole transaction back (assert in test). School year always `SystemConstant::get('current_school_year')`.

- [ ] **Step 1: Failing unit test**

```php
public function test_confirm_import_creates_authoritative_records(): void
{
    $this->seed();
    $chair = User::where('email', 'chair.fil@jhs.test')->first();
    $this->actingAs($chair);
    $upload = $this->makeUploadFor($chair, [[
        'teacher_name' => 'Leah Angelic C. Bilbar', 'employment_status' => 'Permanent',
        'sections' => 'G7: Ignatius', 'cm' => null, 'hc' => null,
        'service_load' => '3', 'other_assignment' => 'Department Chair', 'flagged' => false,
    ]]);

    $result = app(PlantillaReviewService::class)->confirmImport($upload);

    $this->assertSame(1, $result['imported']);
    $teacher = Teacher::where('full_name', 'Leah Angelic C. Bilbar')->first();
    $this->assertSame(EmploymentStatus::Permanent, $teacher->employment_status);
    $this->assertSame(1, $teacher->sectionAssignments()->count());
    $this->assertEquals(4.0, (float) $teacher->sectionAssignments()->first()->hours); // FIL = 4h
    $this->assertDatabaseHas('service_loads', ['teacher_id' => $teacher->id, 'hours' => 3]);
    $this->assertSame(1, TeacherOtherAssignment::count());
    $this->assertSame('reviewed', $upload->fresh()->extraction_status);
}

public function test_unknown_status_is_skipped_and_reported(): void
{
    $this->seed();
    $chair = User::where('email', 'chair.fil@jhs.test')->first();
    $this->actingAs($chair);
    $upload = $this->makeUploadFor($chair, [[
        'teacher_name' => 'Mystery Person', 'employment_status' => 'Freelance',
        'sections' => null, 'cm' => null, 'hc' => null, 'service_load' => null,
        'other_assignment' => null, 'flagged' => false,
    ]]);

    $result = app(PlantillaReviewService::class)->confirmImport($upload);
    $this->assertSame(0, $result['imported']);
    $this->assertNotEmpty($result['skipped']);
    $this->assertSame(0, Teacher::count() - Teacher::whereNot('full_name', 'Mystery Person')->count());
}
```

Add `Teacher::sectionAssignments()` hasMany relation while implementing.

- [ ] **Step 2:** FAIL. **Step 3:** Implement service + controller `confirm` (authorize, call, flash `imported`/`skipped` summary, redirect to `chair.teachers.index` — route exists next task; use `chair.dashboard` until then and fix in Task 21). **Step 4:** PASS.
- [ ] **Step 5:** `git commit -am "feat: confirm-and-import from staging to authoritative tables"`

### Task 21: Teacher Roster

**Files:** Create: `app/Http/Controllers/DepartmentChair/TeacherController.php` (`index,create,store,edit,update`), views `chair/teachers/{index,form}.blade.php`, request `StoreTeacherRequest` (`full_name required|max:150`, `employment_status required|in:` enum values); Modify routes; Test: `tests/Feature/Chair/TeacherRosterTest.php`

**Interfaces:** Produces `chair.teachers.*` routes. Index lists own-department teachers with status label + per-teacher load summary via `LoadCalculationService`. All writes authorize `update` on the current submission (locked after submit) and audit-log. Teachers always created in the chair's own `department_id` — no department input exists in the form.

- [ ] **Step 1: Failing test**

```php
public function test_roster_scoped_to_own_department(): void
{
    $this->seed();
    $fil = User::where('email', 'chair.fil@jhs.test')->first();
    $mine = Teacher::factory()->create(['department_id' => $fil->department_id, 'full_name' => 'Mine Person']);
    $other = Teacher::factory()->create(['full_name' => 'Other Person']);

    $this->actingAs($fil)->get(route('chair.teachers.index'))
        ->assertOk()->assertSee('Mine Person')->assertDontSee('Other Person');

    $this->actingAs($fil)->get(route('chair.teachers.edit', $other))->assertNotFound();
}

public function test_create_teacher_lands_in_own_department(): void
{
    $this->seed();
    $fil = User::where('email', 'chair.fil@jhs.test')->first();
    $this->actingAs($fil)->post(route('chair.teachers.store'), [
        'full_name' => 'New Teacher Name', 'employment_status' => 'permanent',
    ])->assertRedirect(route('chair.teachers.index'));
    $this->assertDatabaseHas('teachers', ['full_name' => 'New Teacher Name',
        'department_id' => $fil->department_id]);
}
```

- [ ] **Step 2:** FAIL. **Step 3:** Implement. **Step 4:** PASS. **Step 5:** `git commit -am "feat: chair teacher roster"` (also fix Task 20's confirm redirect to `chair.teachers.index`).

### Task 22: Section Assignment Editor (subject + CM + HC)

**Files:**
- Create: `app/Services/Curriculum/SectionAssignmentService.php`, `app/Http/Controllers/DepartmentChair/SectionAssignmentController.php` (`index`, `store`, `destroy`, `storeModerator`, `destroyModerator`, `storeHonors`, `destroyHonors`), view `chair/assignments/index.blade.php`, request `StoreSectionAssignmentRequest` (`teacher_id required|exists:teachers,id`, `section_id required|exists:sections,id`)
- Modify routes; Test: `tests/Unit/SectionAssignmentServiceTest.php`, `tests/Feature/Chair/SectionAssignmentTest.php`

**Interfaces (produces):**
```php
SectionAssignmentService::assign(Teacher $t, Section $s): TeacherSectionAssignment
// hours = $t->department->hours_per_section; school year from constants;
// throws DomainException 'section_taken' if another teacher of this dept already holds the section
SectionAssignmentService::assignModerator(Teacher $t, Section $s): ClassModeratorAssignment
// throws DomainException 'moderator_taken' if the section already has a CM (any department)
SectionAssignmentService::assignHonors(Teacher $t, Section $s): HonorsClassAssignment
// throws DomainException 'no_honors_class' if $t->department->has_honors_class is false
```
Routes: `chair.assignments.index/store/destroy` + `chair.moderators.store/destroy` + `chair.honors.store/destroy`. Index view: sections grouped by grade (Alpine tab per grade), each showing this department's assigned teacher (or "unassigned"), CM name, HC name; assignment via `<select>` of own-dept teachers + submit. Controller maps DomainException to a validation error flash. All writes authorize submission `update` + audit-log.

- [ ] **Step 1: Failing unit test**

```php
public function test_assign_uses_department_rate_and_uniqueness(): void
{
    $this->seed(SystemConstantSeeder::class);
    $dept = Department::factory()->create(['hours_per_section' => 5]);
    $t1 = Teacher::factory()->create(['department_id' => $dept->id]);
    $t2 = Teacher::factory()->create(['department_id' => $dept->id]);
    $s = Section::factory()->create();
    $svc = app(SectionAssignmentService::class);

    $a = $svc->assign($t1, $s);
    $this->assertEquals(5.0, (float) $a->hours);

    $this->expectException(DomainException::class);
    $svc->assign($t2, $s);
}

public function test_honors_requires_department_flag(): void
{
    $this->seed(SystemConstantSeeder::class);
    $dept = Department::factory()->create(['has_honors_class' => false]);
    $t = Teacher::factory()->create(['department_id' => $dept->id]);
    $this->expectException(DomainException::class);
    app(SectionAssignmentService::class)->assignHonors($t, Section::factory()->create());
}

public function test_one_moderator_per_section_across_departments(): void
{
    $this->seed(SystemConstantSeeder::class);
    $s = Section::factory()->create();
    $svc = app(SectionAssignmentService::class);
    $svc->assignModerator(Teacher::factory()->create(), $s);
    $this->expectException(DomainException::class);
    $svc->assignModerator(Teacher::factory()->create(), $s);
}
```

Feature test: chair posts `chair.assignments.store` with own teacher + section → 302 + DB row; posting a teacher belonging to another department → validation error; other chair's route access already covered by middleware.

- [ ] **Step 2:** FAIL. **Step 3:** Implement service + controller + view. **Step 4:** PASS.
- [ ] **Step 5:** `git commit -am "feat: section assignment editor with CM and honors"`

### Task 23: Chair Dashboard (real data + quality flags)

**Files:** Modify: `app/Http/Controllers/DepartmentChair/DashboardController.php`, `resources/views/chair/dashboard.blade.php`; Test: `tests/Feature/Chair/DashboardTest.php`

**Interfaces:** Consumes `LoadCalculationService::forTeacher`, `PlantillaSubmission::currentFor`. Dashboard shows: status banner (Draft/Submitted/Returned + returned_comment when present), stat cards (teacher count, sections covered by this dept vs `Section::count()`, flagged-teacher count), and a per-teacher table (name, status, section count, teaching/nonteaching/total hours, overload, flag badges) sorted by name. Links to upload/review/roster/assignments/submit pages.

- [ ] **Step 1: Failing test**

```php
public function test_dashboard_shows_submission_status_and_flags(): void
{
    $this->seed();
    $chair = User::where('email', 'chair.fil@jhs.test')->first();
    Teacher::factory()->create(['department_id' => $chair->department_id,
        'full_name' => 'Zero Section Teacher']);

    $this->actingAs($chair)->get(route('chair.dashboard'))
        ->assertOk()
        ->assertSee('Draft')
        ->assertSee('Zero Section Teacher')
        ->assertSee('zero_sections');
}
```

- [ ] **Step 2:** FAIL. **Step 3:** Implement (controller assembles teacher rows through the service; flags rendered as small red/amber Tailwind pills, humanized: `zero_sections` → "No sections"). Assert humanized text instead if you humanize — keep test and view consistent. **Step 4:** PASS.
- [ ] **Step 5:** `git commit -am "feat: chair dashboard with live load data and flags"`

### Task 24: Submit for Review

**Files:** Create: `app/Services/Plantilla/SubmissionService.php`, `app/Http/Controllers/DepartmentChair/SubmissionController.php` (`show`, `store`), view `chair/submission/show.blade.php`; Modify routes (`chair.submission.show` GET `/chair/submission`, `chair.submission.store` POST); Test: `tests/Unit/SubmissionServiceTest.php`, `tests/Feature/Chair/SubmitFlowTest.php`

**Interfaces (produces):**
```php
SubmissionService::submit(PlantillaSubmission $submission, User $by): PlantillaSubmission
// asserts status is Draft or Returned (else DomainException), sets Submitted,
// submitted_by_user_id, submitted_at=now(), audit-logs 'plantilla.submitted'
```
Show page: read-only summary (same per-teacher table as dashboard) + confirm submit button; after submission every chair mutating endpoint 403s (via the Task 15 policy — verify end-to-end here).

- [ ] **Step 1: Failing tests**

```php
// Unit
public function test_submit_transitions_and_audits(): void
{
    $this->seed();
    $chair = User::where('email', 'chair.fil@jhs.test')->first();
    $sub = PlantillaSubmission::currentFor($chair->department_id);

    $out = app(SubmissionService::class)->submit($sub, $chair);
    $this->assertSame(SubmissionStatus::Submitted, $out->status);
    $this->assertNotNull($out->submitted_at);
    $this->assertDatabaseHas('audit_logs', ['action' => 'plantilla.submitted']);

    $this->expectException(DomainException::class);
    app(SubmissionService::class)->submit($out, $chair); // already submitted
}

// Feature
public function test_submit_locks_all_editing(): void
{
    $this->seed();
    $chair = User::where('email', 'chair.fil@jhs.test')->first();
    $t = Teacher::factory()->create(['department_id' => $chair->department_id]);

    $this->actingAs($chair)->post(route('chair.submission.store'))->assertRedirect();

    $this->actingAs($chair)->post(route('chair.teachers.store'), [
        'full_name' => 'Late Addition', 'employment_status' => 'permanent',
    ])->assertForbidden();
    $this->actingAs($chair)->patch(route('chair.teachers.update', $t), [
        'full_name' => 'Renamed', 'employment_status' => 'permanent',
    ])->assertForbidden();
}
```

- [ ] **Step 2:** FAIL. **Step 3:** Implement. **Step 4:** PASS.
- [ ] **Step 5:** `git commit -am "feat: chair submit-for-review flow with edit locking"`

### Task 25: Final verification + README

**Files:** Create: `README.md` (project root); no code changes unless verification finds gaps.

- [ ] **Step 1:** `php artisan test` — full suite green.
- [ ] **Step 2:** End-to-end manual pass against MySQL (`php artisan serve`): admin logs in → creates a user → edits a constant → checks audit log; chair logs in → uploads `docs/Plantillas/KAGAWARAN PLANTILLA .docx.pdf` → corrects flagged rows in review grid → confirms import → checks roster + dashboard flags → assigns a section → submits → verifies editing is locked. Fix anything broken (each fix: failing test first).
- [ ] **Step 3:** Write `README.md`: project purpose (2 sentences), XAMPP/MAMP + `.env` setup, `composer install && npm install && npm run build`, `php artisan migrate --seed`, demo credentials table, `php artisan test`, pointer to `docs/`.
- [ ] **Step 4:** `git add -A && git commit -m "docs: README with setup and demo credentials"`
- [ ] **Step 5:** Report completion to the user; suggest superpowers:finishing-a-development-branch if on a branch, and demo the two dashboards.

---

## Self-Review Notes

- Spec coverage: scaffold (T1–7), schema incl. staging table (T3–4), seeders (T6), admin pages — dashboard/users/constants/roles/audit (T10–14), chair dashboard w/ flags (T23), upload+extraction (T17–18), review grid (T19), confirm-import (T20), roster (T21), assignments+CM+HC (T22), submit+lock (T15, T24), RBAC boundaries (T5, T15, per-task scoping tests), error handling (T17 exception path, T18 failed-upload path, T20 transaction), testing strategy (real-PDF fixture, SQLite in-memory). Password-reset pages come free with Breeze (T2).
- Deliberate deferrals (documented, not gaps): Coordinator everything; extraction of CM/HC/service-load columns (Chair fills in — interface supports later parser improvement); notifications/inbox.
