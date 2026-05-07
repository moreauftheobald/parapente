<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use App\Jobs\FetchForecastsJob;
use App\Jobs\PurgeOldForecastsJob;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Fetch des prévisions Open-Meteo : toutes les heures
Schedule::job(FetchForecastsJob::class)
    ->hourly()
    ->name('fetch-forecasts')
    ->withoutOverlapping();

// Purge des données obsolètes : tous les jours à 03h00
//   - forecasts/site_scores : slots passés (J-1)
//   - forecast_archive_balises : > 30 jours
Schedule::job(PurgeOldForecastsJob::class)
    ->dailyAt('03:00')
    ->name('purge-old-forecasts')
    ->withoutOverlapping();

