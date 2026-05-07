<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use App\Jobs\FetchForecastsJob;
use App\Jobs\FetchPiouPiouReadingsJob;
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

// Polling des balises PiouPiou toutes les 10 minutes
//   - Insère les nouvelles lectures dans balise_readings
//   - Désactive les balises sans lecture > 7 jours
Schedule::job(FetchPiouPiouReadingsJob::class)
    ->everyTenMinutes()
    ->name('fetch-pioupiou')
    ->withoutOverlapping();

