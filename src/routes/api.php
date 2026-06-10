<?php

use App\Http\Controllers\Api\BaliseController;
use App\Http\Controllers\Api\MapBundleController;
use App\Http\Controllers\Api\MeHiddenSitesController;
use App\Http\Controllers\Api\MeScoringController;
use App\Http\Controllers\Api\SiteController;
use App\Http\Controllers\Api\UserHiddenSiteController;
use App\Http\Controllers\Api\UserScoringController;
use App\Http\Controllers\Api\WeatherStationController;
use Illuminate\Support\Facades\Route;

// ── Map bundle (cache pré-calculé partagé) ───────────────────────
// Bundle unique pour le boot de la vue carte : sites + statuts
// journaliers + fenêtres solaires + agrégat global par jour.
// Régénéré à la fin de chaque cycle de scoring. Cf. FF_map_bundle_cache.md.
Route::get('map-bundle', [MapBundleController::class, 'show']);

Route::prefix('sites')->group(function () {
    Route::get('/',                [SiteController::class, 'index']);
    Route::get('/{id}/scores',     [SiteController::class, 'scores']);
    Route::get('/{id}/chart',      [SiteController::class, 'chart']);
    Route::get('/{id}/multimodel', [SiteController::class, 'multimodel']);
});

Route::prefix('balises')->group(function () {
    Route::get('/',                [BaliseController::class, 'index']);
    Route::get('/{id}/history',    [BaliseController::class, 'history']);
    Route::get('/{id}/comparison', [BaliseController::class, 'comparison']);
});

Route::get('weather-stations', [WeatherStationController::class, 'index']);
Route::get('weather-stations/{id}/detail', [WeatherStationController::class, 'detail']);

// ── Scorings perso de l'utilisateur authentifié ──────────────────
// Routes sous `auth:web` car on partage le cookie de session du site
// (pas de token API séparé pour l'instant — l'API est exclusivement
// appelée depuis le front Blade).
Route::middleware('auth:web')->group(function () {
    // Overrides scoring perso pour la carte : sur-couche du map bundle
    // global, fusionnée côté client. Léger (1-3 sites max en pratique).
    Route::get('me/scoring-overrides', [MeScoringController::class, 'overrides']);

    // Sites masqués pour la carte : ids à filtrer + agrégat journalier
    // recalculé sans eux (cf. FF_site_blacklist.md).
    Route::get('me/hidden-sites', [MeHiddenSitesController::class, 'overrides']);

    Route::prefix('users/me/scorings')->group(function () {
        Route::get('/',                       [UserScoringController::class, 'index']);
        Route::post('/',                      [UserScoringController::class, 'store']);
        Route::patch('/{scoring}',            [UserScoringController::class, 'update']);
        Route::delete('/{scoring}',           [UserScoringController::class, 'destroy']);
        Route::post('/{scoring}/activate',    [UserScoringController::class, 'activate']);
        Route::post('/{scoring}/deactivate',  [UserScoringController::class, 'deactivate']);
    });

    // CRUD des sites masqués (page /profil/sites-masques). Binding sur
    // le Site : masquer = PUT, réafficher = DELETE (tous deux idempotents).
    Route::prefix('users/me/hidden-sites')->group(function () {
        Route::get('/',           [UserHiddenSiteController::class, 'index']);
        Route::put('/{site}',     [UserHiddenSiteController::class, 'hide']);
        Route::delete('/{site}',  [UserHiddenSiteController::class, 'unhide']);
    });
});
