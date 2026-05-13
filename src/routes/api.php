<?php

use App\Http\Controllers\Api\BaliseController;
use App\Http\Controllers\Api\SiteController;
use App\Http\Controllers\Api\UserScoringController;
use Illuminate\Support\Facades\Route;

Route::prefix('sites')->group(function () {
    Route::get('/',                [SiteController::class, 'index']);
    Route::get('/{id}/scores',     [SiteController::class, 'scores']);
    Route::get('/{id}/chart',      [SiteController::class, 'chart']);
    Route::get('/{id}/multimodel', [SiteController::class, 'multimodel']);
});

Route::prefix('balises')->group(function () {
    Route::get('/',             [BaliseController::class, 'index']);
    Route::get('/{id}/history', [BaliseController::class, 'history']);
});

// ── Scorings perso de l'utilisateur authentifié ──────────────────
// Routes sous `auth:web` car on partage le cookie de session du site
// (pas de token API séparé pour l'instant — l'API est exclusivement
// appelée depuis le front Blade).
Route::middleware('auth:web')
    ->prefix('users/me/scorings')
    ->group(function () {
        Route::get('/',                       [UserScoringController::class, 'index']);
        Route::post('/',                      [UserScoringController::class, 'store']);
        Route::patch('/{scoring}',            [UserScoringController::class, 'update']);
        Route::delete('/{scoring}',           [UserScoringController::class, 'destroy']);
        Route::post('/{scoring}/activate',    [UserScoringController::class, 'activate']);
        Route::post('/{scoring}/deactivate',  [UserScoringController::class, 'deactivate']);
    });
