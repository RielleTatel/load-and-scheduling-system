<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\DepartmentChair;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RoleRedirectController;
use App\Http\Controllers\Scheduling\TimetableController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check() ? redirect()->route('dashboard') : redirect()->route('login');
});

Route::get('/dashboard', RoleRedirectController::class)->middleware('auth')->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::middleware(['auth', 'role:system_admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [Admin\DashboardController::class, 'index'])->name('dashboard');

    Route::get('schedules', [TimetableController::class, 'adminIndex'])->name('schedules.index');
    Route::post('schedules/generate', [TimetableController::class, 'generate'])->name('schedules.generate');
    Route::get('schedules/{timetable}', [TimetableController::class, 'adminShow'])->whereNumber('timetable')->name('schedules.show');
    Route::get('schedules/{timetable}/progress', [TimetableController::class, 'progress'])->whereNumber('timetable')->name('schedules.progress');
    Route::post('schedules/{timetable}/stop', [TimetableController::class, 'stop'])->whereNumber('timetable')->name('schedules.stop');
    Route::post('schedules/{timetable}/approve', [TimetableController::class, 'approve'])->whereNumber('timetable')->name('schedules.approve');
    Route::post('schedules/{timetable}/clone', [TimetableController::class, 'clone'])->whereNumber('timetable')->name('schedules.clone');
    Route::put('schedules/{timetable}/entries/{entry}', [TimetableController::class, 'updateEntry'])->whereNumber('timetable')->whereNumber('entry')->name('schedules.entries.update');
    Route::post('schedules/{timetable}/undo', [TimetableController::class, 'undo'])->whereNumber('timetable')->name('schedules.undo');
    Route::post('schedules/{timetable}/unavailability', [TimetableController::class, 'addAvailability'])->whereNumber('timetable')->name('schedules.unavailability.store');
    Route::delete('schedules/{timetable}/unavailability/{availability}', [TimetableController::class, 'deleteAvailability'])->whereNumber('timetable')->whereNumber('availability')->name('schedules.unavailability.destroy');
    Route::get('schedules/{timetable}/export/{format}', [TimetableController::class, 'adminExport'])
        ->whereNumber('timetable')->whereIn('format', ['pdf', 'xlsx'])->name('schedules.export');

    Route::get('users', [Admin\UserController::class, 'index'])->name('users.index');
    Route::get('users/create', [Admin\UserController::class, 'create'])->name('users.create');
    Route::post('users', [Admin\UserController::class, 'store'])->name('users.store');
    Route::get('users/{user}/edit', [Admin\UserController::class, 'edit'])->name('users.edit');
    Route::put('users/{user}', [Admin\UserController::class, 'update'])->name('users.update');
    Route::patch('users/{user}/toggle', [Admin\UserController::class, 'toggleActive'])->name('users.toggle');

    Route::get('constants', [Admin\SystemConstantController::class, 'index'])->name('constants.index');
    Route::patch('constants/{systemConstant}', [Admin\SystemConstantController::class, 'update'])->name('constants.update');

    Route::get('roles', [Admin\AssignmentRoleController::class, 'index'])->name('roles.index');
    Route::get('roles/create', [Admin\AssignmentRoleController::class, 'create'])->name('roles.create');
    Route::post('roles', [Admin\AssignmentRoleController::class, 'store'])->name('roles.store');
    Route::get('roles/{role}/edit', [Admin\AssignmentRoleController::class, 'edit'])->name('roles.edit');
    Route::put('roles/{role}', [Admin\AssignmentRoleController::class, 'update'])->name('roles.update');

    // Registrar reference data: school-wide, re-issued each year, and the
    // cross-check every department's plantilla import is validated against.
    Route::get('sections', [Admin\SectionController::class, 'index'])->name('sections.index');
    Route::get('sections/create', [Admin\SectionController::class, 'create'])->name('sections.create');
    Route::post('sections', [Admin\SectionController::class, 'store'])->name('sections.store');
    Route::get('sections/{section}/edit', [Admin\SectionController::class, 'edit'])->name('sections.edit');
    Route::put('sections/{section}', [Admin\SectionController::class, 'update'])->name('sections.update');

    // The registrar's roster arrives as one PDF each year; extraction stages it
    // for review and nothing reaches `sections` until the Admin confirms.
    Route::get('roster/upload', [Admin\RosterImportController::class, 'create'])->name('roster.create');
    Route::post('roster', [Admin\RosterImportController::class, 'store'])->name('roster.store');
    Route::get('roster/review', [Admin\RosterImportController::class, 'review'])->name('roster.review');
    Route::patch('roster/rows/{row}', [Admin\RosterImportController::class, 'updateRow'])->name('roster.rows.update');
    Route::post('roster/confirm', [Admin\RosterImportController::class, 'confirm'])->name('roster.confirm');

    Route::get('teachers', [Admin\TeacherDirectoryController::class, 'index'])->name('teachers.index');
    Route::get('teachers/create', [Admin\TeacherDirectoryController::class, 'create'])->name('teachers.create');
    Route::post('teachers', [Admin\TeacherDirectoryController::class, 'store'])->name('teachers.store');
    Route::get('teachers/{teacher}/edit', [Admin\TeacherDirectoryController::class, 'edit'])->name('teachers.edit');
    Route::put('teachers/{teacher}', [Admin\TeacherDirectoryController::class, 'update'])->name('teachers.update');
    Route::post('teachers/{teacher}/verify', [Admin\TeacherDirectoryController::class, 'verify'])->name('teachers.verify');
    Route::get('teachers/{teacher}/merge', [Admin\TeacherMergeController::class, 'preview'])->name('teachers.merge.preview');
    Route::post('teachers/{teacher}/merge', [Admin\TeacherMergeController::class, 'merge'])->name('teachers.merge');
    Route::get('teacher-directory-sources/create', [Admin\DirectorySourceController::class, 'create'])->name('directory-sources.create');
    Route::post('teacher-directory-sources', [Admin\DirectorySourceController::class, 'store'])->name('directory-sources.store');
    Route::get('teacher-readiness', [Admin\TeacherReadinessController::class, 'index'])->name('teacher-readiness.index');

    // Who a written name refers to, when no source can say for certain.
    Route::get('identity-cases', [Admin\IdentityCaseController::class, 'index'])->name('identity-cases.index');
    Route::get('identity-cases/{identityCase}', [Admin\IdentityCaseController::class, 'show'])->name('identity-cases.show');
    Route::post('identity-cases/{identityCase}/resolve', [Admin\IdentityCaseController::class, 'resolve'])->name('identity-cases.resolve');

    Route::get('audit', [Admin\AuditLogController::class, 'index'])->name('audit.index');
});

Route::middleware(['auth', 'role:department_chair'])->prefix('chair')->name('chair.')->group(function () {
    Route::get('/', [DepartmentChair\DashboardController::class, 'index'])->name('dashboard');

    Route::get('schedules', [TimetableController::class, 'chairIndex'])->name('schedules.index');
    Route::get('schedules/{timetable}', [TimetableController::class, 'chairShow'])->whereNumber('timetable')->name('schedules.show');
    Route::get('schedules/{timetable}/export/{format}', [TimetableController::class, 'chairExport'])
        ->whereNumber('timetable')->whereIn('format', ['pdf', 'xlsx'])->name('schedules.export');

    Route::get('plantilla/upload', [DepartmentChair\PlantillaUploadController::class, 'create'])->name('plantilla.create');
    Route::post('plantilla', [DepartmentChair\PlantillaUploadController::class, 'store'])->name('plantilla.store');

    Route::get('plantilla/review', [DepartmentChair\PlantillaReviewController::class, 'show'])->name('plantilla.review');
    Route::post('plantilla/rows', [DepartmentChair\PlantillaReviewController::class, 'storeRow'])->name('plantilla.rows.store');
    Route::patch('plantilla/rows/{row}', [DepartmentChair\PlantillaReviewController::class, 'updateRow'])->name('plantilla.rows.update');
    Route::delete('plantilla/rows/{row}', [DepartmentChair\PlantillaReviewController::class, 'destroyRow'])->name('plantilla.rows.destroy');
    Route::post('plantilla/confirm', [DepartmentChair\PlantillaReviewController::class, 'confirm'])->name('plantilla.confirm');

    Route::get('teachers', [DepartmentChair\TeacherController::class, 'index'])->name('teachers.index');
    Route::get('teachers/create', [DepartmentChair\TeacherController::class, 'create'])->name('teachers.create');
    Route::post('teachers', [DepartmentChair\TeacherController::class, 'store'])->name('teachers.store');
    Route::get('teachers/{teacher}/edit', [DepartmentChair\TeacherController::class, 'edit'])->name('teachers.edit');
    Route::put('teachers/{teacher}', [DepartmentChair\TeacherController::class, 'update'])->name('teachers.update');
    Route::post('teachers/{teacher}/confirm-identity', [DepartmentChair\TeacherController::class, 'confirmIdentity'])->name('teachers.confirm-identity');
    Route::post('identity-cases/{identityCase}/context', [DepartmentChair\TeacherController::class, 'addCaseContext'])->name('identity-cases.context');

    Route::get('assignments', [DepartmentChair\SectionAssignmentController::class, 'index'])->name('assignments.index');
    Route::post('assignments', [DepartmentChair\SectionAssignmentController::class, 'store'])->name('assignments.store');
    Route::delete('assignments', [DepartmentChair\SectionAssignmentController::class, 'destroy'])->name('assignments.destroy');
    Route::post('moderators', [DepartmentChair\SectionAssignmentController::class, 'storeModerator'])->name('moderators.store');
    Route::delete('moderators', [DepartmentChair\SectionAssignmentController::class, 'destroyModerator'])->name('moderators.destroy');
    Route::post('honors', [DepartmentChair\SectionAssignmentController::class, 'storeHonors'])->name('honors.store');
    Route::delete('honors', [DepartmentChair\SectionAssignmentController::class, 'destroyHonors'])->name('honors.destroy');

    Route::get('submission', [DepartmentChair\SubmissionController::class, 'show'])->name('submission.show');
    Route::post('submission', [DepartmentChair\SubmissionController::class, 'store'])->name('submission.store');
});

require __DIR__.'/auth.php';
