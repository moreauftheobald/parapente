<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use App\Jobs\AggregateBaliseReadingsHourlyJob;
use App\Jobs\ComputeBaliseConsensusCompareJob;
use App\Jobs\ComputeModelReliabilityJob;
use App\Jobs\FetchBaliseForecastsJob;
use App\Jobs\FetchForecastsJob;
use App\Jobs\WatchScoringTableJob;
use App\Jobs\FetchInfoclimatStationReadingsJob;
use App\Jobs\FetchMetarStationReadingsJob;
use App\Jobs\FetchMfStationReadingsJob;
use App\Jobs\FetchPiouPiouReadingsJob;
use App\Jobs\FetchWindyReadingsJob;
use App\Jobs\FetchStationForecastsJob;
use App\Jobs\PurgeOldForecastsJob;
use App\Jobs\PurgePageViewsJob;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Fetch des prévisions Open-Meteo par modèle : toutes les heures
// (alimente forecasts → panel multimodèles / carte des modèles / fiabilité ;
//  le scoring est déporté au sidecar consensus-grid-v2)
Schedule::job(FetchForecastsJob::class)
    ->hourly()
    ->name('fetch-forecasts')
    ->withoutOverlapping();

// Surveillance du buffer de scoring écrit par le sidecar : au flip de
// `scoring_table`, invalide les caches map et régénère le bundle.
Schedule::job(WatchScoringTableJob::class)
    ->everyMinute()
    ->name('watch-scoring-table')
    ->withoutOverlapping();

// Purge des données obsolètes : tous les jours à 03h00
//   - forecasts : slots passés (J-1) — les site_scores_{1,2} sont gérées
//     par le sidecar (DROP/recréation à chaque run)
//   - forecast_archive_balises / forecast_archive_stations : > 30 jours
//   - balise_readings / weather_station_observations (brut) : > 30 jours
//   - balise_readings_hourly : > 7 jours
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

// Polling METAR (NOAA) via le système stations météo
//   - Cible weather_stations / weather_station_observations
//   - Observations enrichies (pression, visibilité, couverture nuageuse…)
//   - Activé via /admin/station-apis (API METAR = active)
Schedule::job(FetchMetarStationReadingsJob::class)
    ->everyThirtyMinutes()
    ->name('fetch-metar-stations')
    ->withoutOverlapping();

// Polling Météo-France (infrahoraire 6m) toutes les 12 minutes
//   - 2 appels HTTP par run (2 slots de 6 min) → toutes les stations MF
//   - Cadence : xx:09, xx:21, xx:33, xx:45, xx:57
//     ex: xx:09 fetche xx:00 + xx:06 (délai +3 min pour publication MF)
//   - 10 appels/heure (quota MF = 100 req/min)
//   - Activé via /admin/station-apis (API MF = active + credentials OAuth2)
Schedule::job(FetchMfStationReadingsJob::class)
    ->cron('9,21,33,45,57 * * * *')
    ->name('fetch-mf-stations')
    ->withoutOverlapping();

// Polling Infoclimat (réseau StatIC) toutes les heures
//   - N appels batch (50 stations/appel) → ~600+ stations amateurs
//   - Activé via /admin/station-apis (API Infoclimat = active + API key)
Schedule::job(FetchInfoclimatStationReadingsJob::class)
    ->hourly()
    ->name('fetch-infoclimat-stations')
    ->withoutOverlapping();

// Polling Windy.com (Open Data v2) toutes les 30 minutes
//   - Cadence conservatrice : 1 appel HTTP par balise windy active
//     (pas d'endpoint batch côté Windy)
//   - Skip silencieux si la clé API n'est pas configurée
//     (settings.windy.api_key, éditable dans /admin/settings)
Schedule::job(FetchWindyReadingsJob::class)
    ->everyThirtyMinutes()
    ->name('fetch-windy')
    ->withoutOverlapping();

// Archivage horaire des prévisions Open-Meteo aux coords des balises
//   - Multi-coordonnées en batch (10 calls/heure tous modèles confondus)
//   - Filtre à horizon ≤ 72h (J+2 max)
//   - Upsert dans forecast_archive_balises
Schedule::job(FetchBaliseForecastsJob::class)
    ->hourly()
    ->name('fetch-balise-forecasts')
    ->withoutOverlapping();

// Archivage horaire des prévisions Open-Meteo aux coords des stations météo
//   - Payload étendu (vent, temp, humidité, précip, pression, nuages)
//   - Même batch multi-coordonnées que les balises
//   - Décalé à :15 pour ne pas chevaucher le fetch balises
Schedule::job(FetchStationForecastsJob::class)
    ->hourlyAt(15)
    ->name('fetch-station-forecasts')
    ->withoutOverlapping();

// Agrégation horaire des lectures balises (dir / vit. moy / rafale / temp)
//   - Fenêtre glissante 3h pour capter les lectures tardives
//   - Upsert dans balise_readings_hourly (unique balise_id, hour_at)
//   - Décalé à :05 pour laisser PiouPiou/METAR terminer leur tour à :00
Schedule::job(AggregateBaliseReadingsHourlyJob::class)
    ->hourlyAt(5)
    ->name('aggregate-balise-readings-hourly')
    ->withoutOverlapping();

// Purge des pages vues au-delà de la rétention configurée
// (setting pageviews.retention_days, défaut 365 j) — tous les jours à 03h15
Schedule::job(PurgePageViewsJob::class)
    ->dailyAt('03:15')
    ->name('purge-page-views')
    ->withoutOverlapping();

// ── Fiabilité des modèles (phase 2.5 — shadow comparatif) ────────
// Cf. FF_model_reliability.md. Aucun impact sur le scoring de prod ;
// alimente uniquement l'écran admin de comparaison.

// Recalcul du triple-consensus pour les balises du panel — horaire à :10
// (après FetchBaliseForecastsJob à :00 et AggregateBaliseReadingsHourlyJob à :05).
// Honore le kill switch reliability.shadow_enabled.
Schedule::job(ComputeBaliseConsensusCompareJob::class)
    ->hourlyAt(10)
    ->name('compute-consensus-compare')
    ->withoutOverlapping();

// Recalcul des MAE / weight_factor des modèles sur la fenêtre glissante
// (reliability.window_days, défaut 7 j). Une fois par jour à 03h30 —
// après les purges (03:00, 03:15) et avant que les utilisateurs ne
// commencent à consulter la carte au petit matin.
Schedule::job(ComputeModelReliabilityJob::class)
    ->dailyAt('03:30')
    ->name('compute-model-reliability')
    ->withoutOverlapping();

