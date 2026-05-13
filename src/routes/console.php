<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use App\Jobs\AggregateBaliseReadingsHourlyJob;
use App\Jobs\FetchBaliseForecastsJob;
use App\Jobs\FetchForecastsJob;
use App\Jobs\FetchMetarReadingsJob;
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

// Polling METAR (NOAA Aviation Weather) toutes les 30 minutes
//   - Stations aéroportuaires officielles, fréquence native ~30 min
//   - Mêmes mécaniques de dédup et désactivation que PiouPiou
Schedule::job(FetchMetarReadingsJob::class)
    ->everyThirtyMinutes()
    ->name('fetch-metar')
    ->withoutOverlapping();

// Archivage horaire des prévisions Open-Meteo aux coords des balises
//   - Multi-coordonnées en batch (10 calls/heure tous modèles confondus)
//   - Filtre à horizon ≤ 72h (J+2 max)
//   - Upsert dans forecast_archive_balises
Schedule::job(FetchBaliseForecastsJob::class)
    ->hourly()
    ->name('fetch-balise-forecasts')
    ->withoutOverlapping();

// Agrégation horaire des lectures balises (dir / vit. moy / rafale / temp)
//   - Fenêtre glissante 3h pour capter les lectures tardives
//   - Upsert dans balise_readings_hourly (unique balise_id, hour_at)
//   - Décalé à :05 pour laisser PiouPiou/METAR terminer leur tour à :00
Schedule::job(AggregateBaliseReadingsHourlyJob::class)
    ->hourlyAt(5)
    ->name('aggregate-balise-readings-hourly')
    ->withoutOverlapping();

