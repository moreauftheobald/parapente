<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Forecast;
use App\Models\Site;
use App\Models\WeatherModel;
use App\Services\Weather\OpenMeteoService;
use App\Services\Weather\ScoringService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class FetchSiteForecastsJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300; // 5 min max (10 modèles × ~200ms + marge)
    public int $tries   = 2;

    public function __construct(
        private readonly int $siteId
    ) {}

    public function handle(OpenMeteoService $openMeteo, ScoringService $scoring): void
    {
        $site = Site::with('conditions')->find($this->siteId);

        if (! $site) {
            Log::error("FetchSiteForecastsJob: site {$this->siteId} introuvable.");
            return;
        }

        Log::info("FetchSiteForecastsJob: début fetch pour [{$site->slug}]");

        // ── 1. Récupération des prévisions par modèle ────────────
        $allModelData = $openMeteo->fetchAllModelsForSite($site);

        if (empty($allModelData)) {
            Log::warning("FetchSiteForecastsJob: aucune donnée reçue pour [{$site->slug}]");
            return;
        }

        // ── 2. Persistance en base (upsert par site/modèle/créneau) ──
        $this->storeForecastsForSite($site, $allModelData);

        // ── 3. Calcul des scores (voting logic) ──────────────────
        $scoring->computeScoresForSite($site);

        Log::info("FetchSiteForecastsJob: terminé pour [{$site->slug}] — {$this->countStored($allModelData)} prévisions traitées.");
    }

    /**
     * Persiste les prévisions de tous les modèles pour un site.
     */
    private function storeForecastsForSite(Site $site, array $allModelData): void
    {
        $models    = WeatherModel::active()->get()->keyBy('code');
        $fetchedAt = now()->toDateTimeString();
        $rows      = [];

        foreach ($allModelData as $modelCode => $hourlyData) {
            $model = $models->get($modelCode);

            if (! $model) {
                Log::warning("FetchSiteForecastsJob: modèle inconnu [{$modelCode}], ignoré.");
                continue;
            }

            foreach ($hourlyData as $forecastAt => $values) {
                $rows[] = [
                    'site_id'          => $site->id,
                    'weather_model_id' => $model->id,
                    'forecast_at'      => $forecastAt,
                    'fetched_at'       => $fetchedAt,
                    'wind_direction'   => $values['wind_direction'],
                    'wind_speed_avg'   => $values['wind_speed_avg'],
                    'wind_speed_min'   => $values['wind_speed_min'],
                    'wind_speed_max'   => $values['wind_speed_max'],
                    'precipitation'    => $values['precipitation'],
                    'cloud_cover_low'  => $values['cloud_cover_low'],
                    'cloud_cover_mid'  => $values['cloud_cover_mid'],
                    'cloud_cover_high' => $values['cloud_cover_high'],
                    'cloud_base_m'     => $values['cloud_base_m'],
                    'temperature'      => $values['temperature'],
                    'humidity'         => $values['humidity'],
                    'created_at'       => $fetchedAt,
                    'updated_at'       => $fetchedAt,
                ];

                // Upsert par lots de 500 pour ne pas exploser la mémoire
                if (count($rows) >= 500) {
                    $this->upsertBatch($rows);
                    $rows = [];
                }
            }
        }

        // Flush du reste
        if (! empty($rows)) {
            $this->upsertBatch($rows);
        }
    }

    /**
     * Upsert d'un lot de prévisions.
     * La clé unique (site_id, weather_model_id, forecast_at) évite les doublons.
     */
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

    private function countStored(array $allModelData): int
    {
        return array_sum(array_map('count', $allModelData));
    }
}
