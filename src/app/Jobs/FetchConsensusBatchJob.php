<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\TracksExecution;
use App\Models\Forecast;
use App\Models\Site;
use App\Models\WeatherModel;
use App\Services\Weather\Apis\ConsensusApi;
use App\Services\Weather\Apis\WeatherApiRegistry;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Fetch du consensus (`qui_vole_consensus`) pour TOUS les sites actifs en
 * **batch multi-coordonnées** (1 appel HTTP au sidecar pour 40 sites).
 *
 * Remplace, pour ce seul modèle, le fetch par site de `FetchSiteModelJob`
 * (sorti de la chaîne par site dans `FetchForecastsJob`). À ~3000 sites,
 * ça fait ~75 appels/heure au lieu de ~3000 appels mono qui saturaient le
 * sidecar (incident 2026-05-29 : timeouts en cascade, run consensus
 * étouffé, carte météo HS).
 *
 * Garde-fou **circuit breaker** : après N échecs de chunk consécutifs
 * (sidecar injoignable / en surcharge), on coupe le cycle pour ne pas
 * matraquer un sidecar déjà à terre. Les sites non servis retombent
 * naturellement sur la voting logic interne au scoring (consensus absent).
 *
 * Le scoring lui-même reste fait par `ScoreSiteJob` (chaînes par site du
 * `FetchForecastsJob`) : ce job ne fait qu'alimenter `forecasts`.
 */
class FetchConsensusBatchJob implements ShouldQueue
{
    use Queueable;
    use TracksExecution;

    public int $timeout = 600;
    public int $tries   = 1;

    /** Sites par appel HTTP (aligné sur ConsensusApi::BATCH_CHUNK). */
    private const CHUNK = 40;

    /** Circuit breaker : nb d'échecs de chunk consécutifs avant abandon. */
    private const MAX_CONSECUTIVE_FAILURES = 3;

    protected function monitorGroup(): string
    {
        return 'sites';
    }

    public function handle(WeatherApiRegistry $registry): void
    {
        $this->trackStart();

        $model = WeatherModel::with('api')
            ->where('active', true)
            ->where('code', ConsensusApi::MODEL_CODE)
            ->first();

        if (! $model) {
            $this->trackSuccess('Modèle consensus inactif/absent');
            Log::info('FetchConsensusBatchJob: modèle consensus inactif/absent — rien à faire.');
            return;
        }
        if (! $model->api || ! $model->api->active) {
            $this->trackSuccess('API consensus inactive');
            Log::info('FetchConsensusBatchJob: API consensus inactive — rien à faire.');
            return;
        }

        $impl = $registry->for($model->api);
        if (! $impl instanceof ConsensusApi) {
            $this->trackSuccess('Implémentation inattendue pour l\'API consensus');
            Log::warning('FetchConsensusBatchJob: implémentation inattendue pour l\'API consensus.');
            return;
        }

        $sites = Site::where('active', true)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->get(['id', 'latitude', 'longitude']);

        if ($sites->isEmpty()) {
            $this->trackSuccess('Aucun site actif');
            Log::info('FetchConsensusBatchJob: aucun site actif.');
            return;
        }

        $fetchedAt           = now()->toDateTimeString();
        $consecutiveFailures = 0;
        $okChunks            = 0;
        $totalRows           = 0;

        foreach ($sites->chunk(self::CHUNK) as $chunk) {
            $points = $chunk->map(fn (Site $s) => [
                'id'  => $s->id,
                'lat' => (float) $s->latitude,
                'lng' => (float) $s->longitude,
            ])->all();

            $batch = $impl->fetchBatchForSites($points);

            if (empty($batch)) {
                if (++$consecutiveFailures >= self::MAX_CONSECUTIVE_FAILURES) {
                    Log::warning('FetchConsensusBatchJob: circuit breaker déclenché — sidecar consensus injoignable/saturé, arrêt du cycle.', [
                        'consecutive_failures' => $consecutiveFailures,
                        'chunks_ok'            => $okChunks,
                    ]);
                    break;
                }
                continue;
            }

            $consecutiveFailures = 0;
            $okChunks++;

            $rows = [];
            foreach ($batch as $siteId => $forecasts) {
                foreach ($forecasts as $forecastAt => $v) {
                    $rows[] = [
                        'site_id'           => $siteId,
                        'weather_model_id'  => $model->id,
                        'forecast_at'       => $forecastAt,
                        'fetched_at'        => $fetchedAt,
                        'wind_direction'    => $v['wind_direction'] ?? null,
                        'wind_speed_avg'    => $v['wind_speed_avg'] ?? null,
                        'wind_speed_min'    => $v['wind_speed_min'] ?? null,
                        'wind_speed_max'    => $v['wind_speed_max'] ?? null,
                        'precipitation'     => $v['precipitation'] ?? null,
                        'cloud_cover_low'   => $v['cloud_cover_low'] ?? null,
                        'cloud_cover_mid'   => $v['cloud_cover_mid'] ?? null,
                        'cloud_cover_high'  => $v['cloud_cover_high'] ?? null,
                        'cloud_base_m'      => $v['cloud_base_m'] ?? null,
                        'temperature'       => $v['temperature'] ?? null,
                        'humidity'          => $v['humidity'] ?? null,
                        'models_count'      => $v['models_count'] ?? null,
                        'models_converging' => $v['models_converging'] ?? null,
                        'created_at'        => $fetchedAt,
                        'updated_at'        => $fetchedAt,
                    ];

                    if (count($rows) >= 500) {
                        $this->upsert($rows);
                        $totalRows += count($rows);
                        $rows = [];
                    }
                }
            }

            if (! empty($rows)) {
                $this->upsert($rows);
                $totalRows += count($rows);
            }
        }

        $model->last_fetch_at = now();
        $model->save();

        $this->trackSuccess("{$sites->count()} sites, {$okChunks} chunks, {$totalRows} rows", ['sites' => $sites->count(), 'chunks_ok' => $okChunks, 'rows' => $totalRows]);

        Log::info('FetchConsensusBatchJob terminé', [
            'sites'      => $sites->count(),
            'chunks_ok'  => $okChunks,
            'rows'       => $totalRows,
        ]);
    }

    public function failed(\Throwable $e): void
    {
        $this->trackFailure($e);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function upsert(array $rows): void
    {
        Forecast::upsert(
            $rows,
            ['site_id', 'weather_model_id', 'forecast_at'],
            [
                'fetched_at', 'wind_direction',
                'wind_speed_avg', 'wind_speed_min', 'wind_speed_max',
                'precipitation', 'cloud_cover_low', 'cloud_cover_mid',
                'cloud_cover_high', 'cloud_base_m', 'temperature',
                'humidity', 'models_count', 'models_converging', 'updated_at',
            ]
        );
    }
}
