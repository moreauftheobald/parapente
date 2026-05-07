<?php

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\SiteController as AdminSiteController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\MapController;
use Illuminate\Support\Facades\Route;

Route::get('/', [MapController::class, 'index'])->name('map');

// ───────────────────────────────────────────────────────────────
// BackOffice (/admin)
// ───────────────────────────────────────────────────────────────
Route::prefix('admin')->name('admin.')->group(function () {
    // Auth (publiques)
    Route::get('/login',  [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

    // Zone protégée : auth + admin
    Route::middleware(['auth', 'admin'])->group(function () {
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

        // ── Sites ────────────────────────────────────────────────
        Route::get('/sites',                   [AdminSiteController::class, 'index'])->name('sites.index');
        Route::get('/sites/{site}/edit',       [AdminSiteController::class, 'edit'])->name('sites.edit');
        Route::patch('/sites/{site}',          [AdminSiteController::class, 'update'])->name('sites.update');
        Route::delete('/sites/{site}',         [AdminSiteController::class, 'destroy'])->name('sites.destroy');
        Route::post('/sites/{site}/toggle',    [AdminSiteController::class, 'toggleActive'])->name('sites.toggle');

        // ── Utilisateurs ─────────────────────────────────────────
        Route::get('/users',              [AdminUserController::class, 'index'])->name('users.index');
        Route::get('/users/create',       [AdminUserController::class, 'create'])->name('users.create');
        Route::post('/users',             [AdminUserController::class, 'store'])->name('users.store');
        Route::get('/users/{user}/edit',  [AdminUserController::class, 'edit'])->name('users.edit');
        Route::patch('/users/{user}',     [AdminUserController::class, 'update'])->name('users.update');
        Route::delete('/users/{user}',    [AdminUserController::class, 'destroy'])->name('users.destroy');

        // Sections futures (balises, modèles…) à brancher ici
    });
});
