<?php

declare(strict_types=1);

namespace App\Services\Weather;

use App\Models\Site;
use App\Models\WeatherModel;
use App\Services\Weather\Apis\WeatherApiRegistry;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrateur unique pour récupérer les prévisions d'un (site, modèle).
 *
 * Politique single-shot : chaque modèle a UNE API associée
 * (`weather_models.weather_api_id`). Si le fetch échoue, on log l'erreur
 * sur la WeatherApi et on retourne []. Le prochain cycle de refresh
 * (selon `refresh_frequency_minutes`) tentera à nouveau.
 */
class ForecastFetcher
{
    public function __construct(
        private readonly WeatherApiRegistry $registry
    ) {}

    /**
     * @return array<string, array<string, mixed>>  forecast_at => values
     */
    public function fetch(Site $site, WeatherModel $model): array
    {
        $api = $model->api;

        if ($api === null) {
            Log::warning("ForecastFetcher: modèle {$model->code} sans API associée, fetch ignoré.");
            return [];
        }

        if (! $api->active) {
            Log::info("ForecastFetcher: API {$api->code} inactive, fetch ignoré pour {$model->code}.");
            return [];
        }

        if (! $this->registry->supports($api, $model->code)) {
            Log::warning("ForecastFetcher: API {$api->code} ne supporte pas {$model->code}.");
            return [];
        }

        $impl = $this->registry->for($api);
        return $impl->fetchForSiteAndModel($site, $model);
    }
}
