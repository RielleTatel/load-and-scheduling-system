<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\DepartmentChair;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RoleRedirectController;
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

    Route::get('audit', [Admin\AuditLogController::class, 'index'])->name('audit.index');
});

Route::middleware(['auth', 'role:department_chair'])->prefix('chair')->name('chair.')->group(function () {
    Route::get('/', [DepartmentChair\DashboardController::class, 'index'])->name('dashboard');

    Route::get('plantilla/upload', [DepartmentChair\PlantillaUploadController::class, 'create'])->name('plantilla.create');
    Route::post('plantilla', [DepartmentChair\PlantillaUploadController::class, 'store'])->name('plantilla.store');

    Route::get('plantilla/review', [DepartmentChair\PlantillaReviewController::class, 'show'])->name('plantilla.review');
    Route::post('plantilla/rows', [DepartmentChair\PlantillaReviewController::class, 'storeRow'])->name('plantilla.rows.store');
    Route::patch('plantilla/rows/{row}', [DepartmentChair\PlantillaReviewController::class, 'updateRow'])->name('plantilla.rows.update');
    Route::delete('plantilla/rows/{row}', [DepartmentChair\PlantillaReviewController::class, 'destroyRow'])->name('plantilla.rows.destroy');
    Route::post('plantilla/confirm', [DepartmentChair\PlantillaReviewController::class, 'confirm'])->name('plantilla.confirm');
});

require __DIR__.'/auth.php';
