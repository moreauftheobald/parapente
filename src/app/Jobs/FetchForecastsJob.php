<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\TracksExecution;
use App\Models\Site;
use App\Models\WeatherModel;
use Illuminate\Bus\Batch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrateur planifié (cron horaire) du fetch des prévisions PAR
 * MODÈLE (table `forecasts`).
 *
 * Pour chaque site actif, on prépare une CHAÎNE de jobs reprenant les
 * modèles dûs pour un refresh (selon `refresh_frequency_minutes` +
 * `last_fetch_at`) :
 *
 *   FetchSiteModelJob (modèle 1)
 *   → FetchSiteModelJob (modèle 2)
 *   → …
 *
 * Ces prévisions par modèle alimentent le panel multimodèles, la carte
 * des modèles et la fiabilité. Le CONSENSUS et le SCORING sont désormais
 * entièrement déportés au sidecar `consensus-grid-v2` (tables
 * `site_scores_{1,2}`) : ce job ne fetche plus `qui_vole_consensus` et ne
 * déclenche plus aucun calcul de score ni de rebuild du map bundle (ce
 * dernier est piloté par WatchScoringTableJob, au flip du buffer).
 *
 * Toutes les chaînes (1 par site) sont enveloppées dans un `Bus::batch()`
 * (`allowFailures()`). Une fois les chaînes dispatchées, on met à jour
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

        // Le modèle consensus est déporté au sidecar — exclu par sécurité
        // (il est de toute façon inactif depuis la bascule scoring V2).
        $dueModels = $activeModels
            ->reject(fn (WeatherModel $m) => $m->code === WeatherModel::CONSENSUS_CODE)
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
            $this->trackSuccess('Aucun modèle dû pour refresh');
            Log::info('FetchForecastsJob: aucun modèle dû pour refresh.');
            return;
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
                        fn (int $modelId) => new FetchSiteModelJob($site->id, $modelId),
                        $dueModelIds
                    );

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
            ->name('hourly-forecasts')
            ->onQueue('meteo')
            ->allowFailures()
            ->finally(function (Batch $batch) {
                Log::info(
                    "FetchForecastsJob: batch {$batch->id} terminé "
                    . "({$batch->processedJobs()} / {$batch->totalJobs} jobs, "
                    . "{$batch->failedJobs} échecs)."
                );
            })
            ->dispatch();
    }

    public function failed(\Throwable $e): void
    {
        $this->trackFailure($e);
    }
}
