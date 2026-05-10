<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Site;
use App\Models\WeatherModel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrateur planifié (cron horaire ou plus court).
 *
 * Pour chaque modèle dû pour un refresh (selon
 * `refresh_frequency_minutes` + `last_fetch_at`), on dispatche un job
 * `FetchSiteModelJob` par site actif. Une fois la salve dispatchée, on
 * met à jour `weather_models.last_fetch_at` pour fermer la fenêtre.
 *
 * Le scoring est déclenché à la fin de chaque FetchSiteModelJob.
 */
class FetchForecastsJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 60;
    public int $tries   = 3;

    public function handle(): void
    {
        $sites = Site::active()->with('conditions')->get();
        if ($sites->isEmpty()) {
            Log::info('FetchForecastsJob: aucun site actif.');
            return;
        }

        $models    = WeatherModel::with('api')->where('active', true)->get();
        $dueModels = $models->filter(fn (WeatherModel $m) => $m->isDueForRefresh());

        if ($dueModels->isEmpty()) {
            Log::info('FetchForecastsJob: aucun modèle dû pour refresh.');
            return;
        }

        $dispatched = 0;
        foreach ($dueModels as $model) {
            if (! $model->api || ! $model->api->active) {
                Log::info("FetchForecastsJob: modèle {$model->code} sans API active, ignoré.");
                continue;
            }

            foreach ($sites as $site) {
                if (! $site->conditions) {
                    continue;
                }
                FetchSiteModelJob::dispatch($site->id, $model->id)
                    ->onQueue('meteo');
                $dispatched++;
            }

            $model->last_fetch_at = now();
            $model->save();
        }

        Log::info("FetchForecastsJob: {$dispatched} jobs dispatchés (". $dueModels->count() ." modèles × {$sites->count()} sites).");
    }
}
