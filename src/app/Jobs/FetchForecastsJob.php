<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\TracksExecution;
use App\Models\Site;
use App\Models\WeatherModel;
use App\Services\Weather\Apis\ConsensusApi;
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
    use TracksExecution;

    public int $timeout = 60;
    public int $tries   = 3;

    protected function monitorGroup(): string
    {
        return 'sites';
    }

    public function handle(): void
    {
        $this->trackStart();

        $siteCount = Site::active()->whereHas('conditions')->count();
        if ($siteCount === 0) {
            $this->trackSuccess('Aucun site actif avec conditions');
            Log::info('FetchForecastsJob: aucun site actif avec conditions.');
            return;
        }

        $activeModels = WeatherModel::with('api')->where('active', true)->get();

        $consensus        = $activeModels->firstWhere('code', ConsensusApi::MODEL_CODE);
        $dispatchConsensus = $consensus
            && $consensus->isDueForRefresh()
            && $consensus->api
            && $consensus->api->active;

        $dueModels = $activeModels
            ->reject(fn (WeatherModel $m) => $m->code === ConsensusApi::MODEL_CODE)
            ->filter(fn (WeatherModel $m) => $m->isDueForRefresh())
            ->filter(function (WeatherModel $m) {
                if (! $m->api || ! $m->api->active) {
                    Log::info("FetchForecastsJob: modèle {$m->code} sans API active, ignoré.");
                    return false;
                }
                return true;
            })
            ->values();

        if ($dueModels->isEmpty() && ! $dispatchConsensus) {
            $this->trackSuccess('Aucun modèle dû pour refresh');
            Log::info('FetchForecastsJob: aucun modèle dû pour refresh.');
            return;
        }

        if ($dispatchConsensus) {
            FetchConsensusBatchJob::dispatch()->onQueue('meteo');
            $consensus->last_fetch_at = now();
            $consensus->save();
            Log::info('FetchForecastsJob: FetchConsensusBatchJob dispatché (batch consensus).');
        }

        $dueModelIds = $dueModels->pluck('id')->all();
        $chainCount  = 0;
        $chains      = [];

        Site::active()
            ->whereHas('conditions')
            ->select(['id'])
            ->chunkById(200, function ($sites) use ($dueModelIds, &$chains, &$chainCount) {
                foreach ($sites as $site) {
                    $jobs = array_map(
                        fn (int $modelId) => new FetchSiteModelJob($site->id, $modelId, false),
                        $dueModelIds
                    );
                    $jobs[] = new ScoreSiteJob($site->id);

                    $chains[] = $jobs;
                    $chainCount++;
                }

                if (count($chains) >= 500) {
                    $this->dispatchBatch($chains);
                    $chains = [];
                }
            });

        if (! empty($chains)) {
            $this->dispatchBatch($chains);
        }

        if ($chainCount === 0) {
            $this->trackSuccess('Aucun site éligible (pas de conditions)');
            Log::info('FetchForecastsJob: aucun site éligible (pas de conditions).');
            return;
        }

        foreach ($dueModels as $model) {
            $model->last_fetch_at = now();
            $model->save();
        }

        $this->trackSuccess("{$chainCount} chaîne(s), {$dueModels->count()} modèle(s)", ['chains' => $chainCount, 'models' => $dueModels->count(), 'sites' => $siteCount]);

        Log::info(
            'FetchForecastsJob: batch(s) dispatché(s) ('
            . "{$chainCount} chaîne(s), {$dueModels->count()} modèle(s) × {$siteCount} site(s))."
        );
    }

    private function dispatchBatch(array $chains): void
    {
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
    }

    public function failed(\Throwable $e): void
    {
        $this->trackFailure($e);
    }
}
