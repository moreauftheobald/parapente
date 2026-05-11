<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Site;
use App\Models\WeatherModel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrateur planifié (cron horaire).
 *
 * Pour chaque site actif, on dispatche une CHAÎNE de jobs reprenant les
 * modèles dûs pour un refresh (selon `refresh_frequency_minutes` +
 * `last_fetch_at`) :
 *
 *   FetchSiteModelJob (modèle 1, sans rescore)
 *   → FetchSiteModelJob (modèle 2, sans rescore)
 *   → …
 *   → ScoreSiteJob  (un seul recalcul des scores, en fin de salve)
 *
 * Le rescoring n'est donc fait qu'une fois par site et par cycle, après
 * ingestion de tous les modèles (au lieu d'une fois après chaque modèle).
 *
 * Une fois les chaînes dispatchées, on met à jour
 * `weather_models.last_fetch_at` pour fermer la fenêtre de refresh.
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

        $dueModels = WeatherModel::with('api')
            ->where('active', true)
            ->get()
            ->filter(fn (WeatherModel $m) => $m->isDueForRefresh())
            ->filter(function (WeatherModel $m) {
                if (! $m->api || ! $m->api->active) {
                    Log::info("FetchForecastsJob: modèle {$m->code} sans API active, ignoré.");
                    return false;
                }
                return true;
            })
            ->values();

        if ($dueModels->isEmpty()) {
            Log::info('FetchForecastsJob: aucun modèle dû pour refresh.');
            return;
        }

        $chains = 0;
        foreach ($sites as $site) {
            if (! $site->conditions) {
                continue;
            }

            $siteId = $site->id;

            $jobs   = $dueModels
                ->map(fn (WeatherModel $m) => new FetchSiteModelJob($siteId, $m->id, false))
                ->all();
            $jobs[] = new ScoreSiteJob($siteId);

            Bus::chain($jobs)
                ->onQueue('meteo')
                ->catch(function (\Throwable $e) use ($siteId) {
                    // Une chaîne interrompue (erreur PHP inattendue) ne doit
                    // pas priver le site de son rescoring : on le relance
                    // avec les données déjà ingérées.
                    Log::warning("FetchForecastsJob: chaîne interrompue (site {$siteId}): {$e->getMessage()}");
                    ScoreSiteJob::dispatch($siteId)->onQueue('meteo');
                })
                ->dispatch();

            $chains++;
        }

        foreach ($dueModels as $model) {
            $model->last_fetch_at = now();
            $model->save();
        }

        Log::info("FetchForecastsJob: {$chains} chaîne(s) dispatchée(s) ({$dueModels->count()} modèle(s) × {$sites->count()} site(s)).");
    }
}
