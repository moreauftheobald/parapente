<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Forecast;
use App\Models\Site;
use App\Models\WeatherFetchLog;
use App\Models\WeatherModel;
use App\Services\Weather\ForecastFetcher;
use App\Services\Weather\ScoringService;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Fetch d'UN modèle météo pour UN site, via l'API associée.
 *
 * Politique single-shot : si l'API échoue, on log et on s'arrête —
 * pas de fallback, pas de retry. Le prochain cycle de refresh
 * (refresh_frequency_minutes plus tard) retentera.
 */
class FetchSiteModelJob implements ShouldQueue
{
    use Batchable, Queueable;

    public int $timeout = 60;
    public int $tries   = 1; // single-shot

    public function __construct(
        private readonly int $siteId,
        private readonly int $weatherModelId,
        private readonly bool $rescore = true
    ) {}

    public function handle(ForecastFetcher $fetcher, ScoringService $scoring): void
    {
        $site  = Site::with('conditions')->find($this->siteId);
        $model = WeatherModel::with('api')->find($this->weatherModelId);

        if (! $site || ! $model) {
            Log::error("FetchSiteModelJob: site={$this->siteId}, model={$this->weatherModelId} introuvable.");
            return;
        }

        Log::info("FetchSiteModelJob: début [{$site->slug}] / [{$model->code}]");

        $hourlyData = $fetcher->fetch($site, $model);

        if (empty($hourlyData)) {
            Log::warning("FetchSiteModelJob: aucune donnée [{$site->slug}] / [{$model->code}]");
            // On trace quand même le fetch vide pour que l'écran admin
            // « Couverture des données » détecte les modèles cassés.
            WeatherFetchLog::create([
                'weather_model_id' => $model->id,
                'scope'            => 'site',
                'fetched_at'       => now(),
                'rows_upserted'    => 0,
                'provider_run_at'  => null,
            ]);
            return;
        }

        $this->upsertForecasts($site, $model, $hourlyData);

        WeatherFetchLog::create([
            'weather_model_id' => $model->id,
            'scope'            => 'site',
            'fetched_at'       => now(),
            'rows_upserted'    => count($hourlyData),
            'provider_run_at'  => null,
        ]);

        if ($this->rescore) {
            $scoring->computeScoresForSite($site);
        }

        Log::info("FetchSiteModelJob: terminé [{$site->slug}] / [{$model->code}] — " . count($hourlyData) . ' créneaux.');
    }

    private function upsertForecasts(Site $site, WeatherModel $model, array $hourlyData): void
    {
        $fetchedAt = now()->toDateTimeString();
        $rows      = [];

        foreach ($hourlyData as $forecastAt => $values) {
            $rows[] = [
                'site_id'          => $site->id,
                'weather_model_id' => $model->id,
                'forecast_at'      => $forecastAt,
                'fetched_at'       => $fetchedAt,
                'wind_direction'   => $values['wind_direction'] ?? null,
                'wind_speed_avg'   => $values['wind_speed_avg'] ?? null,
                'wind_speed_min'   => $values['wind_speed_min'] ?? null,
                'wind_speed_max'   => $values['wind_speed_max'] ?? null,
                'precipitation'    => $values['precipitation'] ?? null,
                'cloud_cover_low'  => $values['cloud_cover_low'] ?? null,
                'cloud_cover_mid'  => $values['cloud_cover_mid'] ?? null,
                'cloud_cover_high' => $values['cloud_cover_high'] ?? null,
                'cloud_base_m'     => $values['cloud_base_m'] ?? null,
                'temperature'      => $values['temperature'] ?? null,
                'humidity'         => $values['humidity'] ?? null,
                'created_at'       => $fetchedAt,
                'updated_at'       => $fetchedAt,
            ];

            if (count($rows) >= 500) {
                $this->upsertBatch($rows);
                $rows = [];
            }
        }

        if (! empty($rows)) {
            $this->upsertBatch($rows);
        }
    }

    private function upsertBatch(array $rows): void
    {
        Forecast::upsert(
            $rows,
            ['site_id', 'weather_model_id', 'forecast_at'],
            [
                'fetched_at', 'wind_direction',
                'wind_speed_avg', 'wind_speed_min', 'wind_speed_max',
                'precipitation', 'cloud_cover_low', 'cloud_cover_mid',
                'cloud_cover_high', 'cloud_base_m', 'temperature',
                'humidity', 'updated_at',
            ]
        );
    }
}
