<?php

use App\Http\Controllers\Api\SiteController;
use Illuminate\Support\Facades\Route;

Route::prefix('sites')->group(function () {
    Route::get('/',           [SiteController::class, 'index']);
    Route::get('/{id}/scores',[SiteController::class, 'scores']);
    Route::get('/{id}/chart', [SiteController::class, 'chart']);
});
