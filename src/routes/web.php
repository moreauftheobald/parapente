<?php

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\SiteController as AdminSiteController;
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
        Route::get('/sites',                  [AdminSiteController::class, 'index'])->name('sites.index');
        Route::post('/sites/{site}/toggle',   [AdminSiteController::class, 'toggleActive'])->name('sites.toggle');

        // Sections futures (users, balises, modèles…) à brancher ici
    });
});
