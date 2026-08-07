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
});

Route::middleware(['auth', 'role:department_chair'])->prefix('chair')->name('chair.')->group(function () {
    Route::get('/', [DepartmentChair\DashboardController::class, 'index'])->name('dashboard');
});

require __DIR__.'/auth.php';
