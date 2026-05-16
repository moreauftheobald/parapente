<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Site;
use App\Models\WeatherModel;
use Illuminate\Bus\Batch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrateur planifié (cron horaire).
 *
 * Pour chaque site actif, on prépare une CHAÎNE de jobs reprenant les
 * modèles dûs pour un refresh (selon `refresh_frequency_minutes` +
 * `last_fetch_at`) :
 *
 *   FetchSiteModelJob (modèle 1, sans rescore)
 *   → FetchSiteModelJob (modèle 2, sans rescore)
 *   → …
 *   → ScoreSiteJob  (un seul recalcul des scores, en fin de salve)
 *
 * Toutes les chaînes (1 par site) sont enveloppées dans un `Bus::batch()`.
 * Quand toutes les chaînes ont fini (succès OU échec, allowFailures()
 * est actif), le callback `finally` dispatch RebuildMapBundleJob qui
 * régénère le cache `/api/map-bundle` à partir des scores frais.
 * Cf. FF_map_bundle_cache.md.
 *
 * Le rescoring n'est donc fait qu'une fois par site et par cycle, après
 * ingestion de tous les modèles ; et le map bundle qu'une fois TOUTES
 * les sites ont fini de scorer.
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

        // Une chaîne par site : modèles à fetcher puis ScoreSiteJob en fin.
        $chains = [];
        foreach ($sites as $site) {
            if (! $site->conditions) {
                continue;
            }

            $jobs   = $dueModels
                ->map(fn (WeatherModel $m) => new FetchSiteModelJob($site->id, $m->id, false))
                ->all();
            $jobs[] = new ScoreSiteJob($site->id);

            $chains[] = $jobs;
        }

        if (empty($chains)) {
            Log::info('FetchForecastsJob: aucun site éligible (pas de conditions).');
            return;
        }

        // Batch global : permet de détecter la fin du cycle (toutes les
        // chaînes terminées) pour régénérer le map bundle. allowFailures
        // = un site qui plante son fetch n'empêche pas les autres, et le
        // bundle final aura les sites qui ont réussi + les anciens
        // scores des sites en échec (qui seront rescorés au prochain cycle).
        Bus::batch($chains)
            ->name('hourly-scoring')
            ->onQueue('meteo')
            ->allowFailures()
            ->finally(function (Batch $batch) {
                Log::info(
                    "FetchForecastsJob: batch {$batch->id} terminé "
                    . "({$batch->processedJobs()} / {$batch->totalJobs} jobs, "
                    . "{$batch->failedJobs} échecs). Dispatch RebuildMapBundleJob."
                );
                RebuildMapBundleJob::dispatch();
            })
            ->dispatch();

        foreach ($dueModels as $model) {
            $model->last_fetch_at = now();
            $model->save();
        }

        Log::info(
            'FetchForecastsJob: batch dispatché ('
            . count($chains) . " chaîne(s), {$dueModels->count()} modèle(s) × {$sites->count()} site(s))."
        );
    }
}
