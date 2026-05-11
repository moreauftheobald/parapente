<?php

use App\Http\Controllers\Api\BaliseController;
use App\Http\Controllers\Api\SiteController;
use Illuminate\Support\Facades\Route;

Route::prefix('sites')->group(function () {
    Route::get('/',           [SiteController::class, 'index']);
    Route::get('/{id}/scores',     [SiteController::class, 'scores']);
    Route::get('/{id}/chart',      [SiteController::class, 'chart']);
    Route::get('/{id}/multimodel', [SiteController::class, 'multimodel']);
});

Route::prefix('balises')->group(function () {
    Route::get('/',             [BaliseController::class, 'index']);
    Route::get('/{id}/history', [BaliseController::class, 'history']);
});
