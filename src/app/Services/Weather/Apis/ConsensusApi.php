<?php

declare(strict_types=1);

namespace App\Services\Weather\Apis;

use App\Models\Site;
use App\Models\WeatherApi;
use App\Models\WeatherModel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Client du sidecar `parapente-consensus-grid`.
 *
 * Le sidecar calcule en continu le consensus multi-modèles sur une
 * grille couvrant la France (+ marge) et l'expose via une API
 * **compatible Open-Meteo** : `GET /v1/forecast?latitude=&longitude=
 * &hourly=…&models=qui_vole_consensus`. La réponse a la même forme
 * qu'Open-Meteo (`hourly_units`, `hourly`) plus un champ `qui_vole_meta`.
 *
 * Cette classe sert UN seul modèle (`qui_vole_consensus`). Le consensus
 * récupéré est stocké dans `forecasts` comme n'importe quel modèle, puis
 * repris **en priorité** par le `ScoringService` (cf. son fallback interne
 * si le consensus est indisponible).
 *
 * ⚠️ Différences avec l'instance Open-Meteo classique :
 *  - le vent (`wind_speed_10m`, `wind_gusts_10m`) est renvoyé en **m/s**
 *    (l'endpoint n'accepte pas `wind_speed_unit`) → conversion ×3.6 en km/h,
 *    unité de toute l'app et des seuils de scoring ;
 *  - le plafond de vol consensus est fourni directement
 *    (`qui_vole_cloud_base`, m AMSL) → mappé sur `cloud_base_m` sans
 *    recalcul d'Espy ;
 *  - deux compteurs propriétaires (`qui_vole_models_count` /
 *    `qui_vole_models_converging`) alimentent la confiance du scoring.
 */
class ConsensusApi implements WeatherApiInterface
{
    /** Code du modèle météo unique servi par cette API. */
    public const MODEL_CODE = 'qui_vole_consensus';

    private const DAYS         = 5;
    private const TIMEZONE     = 'Europe/Paris';
    private const MS_TO_KMH    = 3.6;
    private const HTTP_TIMEOUT = 10;

    // Mode batch multi-coordonnées (le sidecar accepte des listes CSV de
    // latitudes/longitudes et renvoie un tableau racine, ordre préservé).
    // 40 points/appel = ~75 appels pour 3000 sites (au lieu de 3000 appels
    // mono qui saturaient le sidecar — cf. incident 2026-05-29).
    private const BATCH_CHUNK     = 40;
    private const BATCH_TIMEOUT_S = 20;

    /**
     * Variables horaires demandées au sidecar. Noms Open-Meteo natifs +
     * variables propriétaires `qui_vole_*` (préfixe conservé pour les
     * distinguer).
     */
    private const HOURLY_VARS = [
        'wind_speed_10m',
        'wind_gusts_10m',
        'wind_direction_10m',
        'precipitation',
        'cloud_cover_low',
        'cloud_cover_mid',
        'cloud_cover_high',
        'temperature_2m',
        'relative_humidity_2m',
        'qui_vole_cloud_base',
        'qui_vole_models_count',
        'qui_vole_models_converging',
    ];

    private string $baseUrl = 'http://parapente-consensus-grid:8082/v1';
    private ?WeatherApi $config = null;

    public function code(): string
    {
        return 'consensus';
    }

    public function setConfig(WeatherApi $config): void
    {
        $this->config  = $config;
        $this->baseUrl = rtrim($config->base_url ?: $this->baseUrl, '/');
    }

    public function supportedModelCodes(): array
    {
        return [self::MODEL_CODE];
    }

    public function fetchForSiteAndModel(Site $site, WeatherModel $model): array
    {
        $params = [
            'latitude'   => $site->latitude,
            'longitude'  => $site->longitude,
            'hourly'     => implode(',', self::HOURLY_VARS),
            'models'     => self::MODEL_CODE,
            'start_date' => now(self::TIMEZONE)->startOfDay()->toDateString(),
            'end_date'   => now(self::TIMEZONE)->addDays(self::DAYS - 1)->toDateString(),
            'timezone'   => self::TIMEZONE,
        ];

        try {
            $response = Http::timeout(self::HTTP_TIMEOUT)
                ->get($this->baseUrl . '/forecast', $params);

            $this->config?->incrementRequestsToday();

            if (! $response->successful()) {
                $msg = "Consensus HTTP {$response->status()} site={$site->slug} model={$model->code}";
                Log::warning($msg);
                $this->config?->recordError($msg);
                return [];
            }

            $this->config?->recordSuccess();
            return $this->parseResponse($response->json());
        } catch (\Exception $e) {
            Log::error('ConsensusApi fetch failed', [
                'site'    => $site->slug,
                'model'   => $model->code,
                'message' => $e->getMessage(),
            ]);
            $this->config?->recordError($e->getMessage());
            return [];
        }
    }

    /**
     * Fetch batch multi-coordonnées (toutes les coordonnées d'un coup).
     *
     * Le sidecar accepte `latitude`/`longitude` en CSV et renvoie un
     * **tableau racine** d'objets stations dans le **même ordre** que les
     * coordonnées en entrée (un objet seul si un seul point). On remappe
     * par position sur les ids fournis.
     *
     * @param  array<int, array{id:int|string, lat:float, lng:float}>  $points
     * @return array<int|string, array<string, array<string, mixed>>>  id => (forecast_at => values)
     */
    public function fetchBatchForSites(array $points): array
    {
        if (empty($points)) {
            return [];
        }

        $result = [];
        foreach (array_chunk(array_values($points), self::BATCH_CHUNK) as $chunk) {
            foreach ($this->fetchBatchChunk($chunk) as $id => $parsed) {
                $result[$id] = $parsed;
            }
        }

        return $result;
    }

    /**
     * @param  array<int, array{id:int|string, lat:float, lng:float}>  $points
     * @return array<int|string, array<string, array<string, mixed>>>
     */
    private function fetchBatchChunk(array $points): array
    {
        $lats = array_map(fn ($p) => (string) $p['lat'], $points);
        $lngs = array_map(fn ($p) => (string) $p['lng'], $points);
        $ids  = array_map(fn ($p) => $p['id'], $points);

        try {
            $response = Http::timeout(self::BATCH_TIMEOUT_S)
                ->get($this->baseUrl . '/forecast', [
                    'latitude'   => implode(',', $lats),
                    'longitude'  => implode(',', $lngs),
                    'hourly'     => implode(',', self::HOURLY_VARS),
                    'models'     => self::MODEL_CODE,
                    'start_date' => now(self::TIMEZONE)->startOfDay()->toDateString(),
                    'end_date'   => now(self::TIMEZONE)->addDays(self::DAYS - 1)->toDateString(),
                    'timezone'   => self::TIMEZONE,
                ]);

            $this->config?->incrementRequestsToday();

            if (! $response->successful()) {
                $msg = "Consensus batch HTTP {$response->status()} (" . count($points) . ' points)';
                Log::warning($msg);
                $this->config?->recordError($msg);
                return [];
            }

            $this->config?->recordSuccess();

            $payload = $response->json();
            if (! is_array($payload)) {
                return [];
            }
            // Tableau racine pour le multi-points ; objet seul pour 1 point.
            $stations = isset($payload[0]) ? $payload : [$payload];

            $result = [];
            foreach ($stations as $idx => $stationData) {
                $id = $ids[$idx] ?? null;
                if ($id === null || ! is_array($stationData)) {
                    continue;
                }
                $parsed = $this->parseResponse($stationData);
                if (! empty($parsed)) {
                    $result[$id] = $parsed;
                }
            }
            return $result;
        } catch (\Exception $e) {
            Log::error('ConsensusApi batch failed', [
                'message'     => $e->getMessage(),
                'point_count' => count($points),
            ]);
            $this->config?->recordError($e->getMessage());
            return [];
        }
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string, array<string, mixed>>
     */
    private function parseResponse(array $data): array
    {
        $hourly = $data['hourly'] ?? [];
        $times  = $hourly['time'] ?? [];

        if (empty($times)) {
            return [];
        }

        $parsed  = [];
        $skipped = 0;

        foreach ($times as $index => $time) {
            $forecastAt = \Carbon\Carbon::parse($time, self::TIMEZONE);
            // On garde toute la journée en cours (cf. OpenMeteoApi) — le scope
            // `Forecast::upcoming()` re-filtre ensuite à partir de now().
            if ($forecastAt->lt(now()->startOfDay())) {
                continue;
            }

            $dir   = $this->getValue($hourly, 'wind_direction_10m', $index);
            $speed = $this->getValue($hourly, 'wind_speed_10m', $index);

            // Direction + vitesse = seules variables indispensables.
            if ($dir === null || $speed === null) {
                $skipped++;
                continue;
            }

            $gust = $this->getValue($hourly, 'wind_gusts_10m', $index);

            $parsed[$forecastAt->format('Y-m-d H:i:s')] = [
                'wind_direction'    => (int) round((float) $dir),
                'wind_speed_avg'    => $this->msToKmh((float) $speed),
                'wind_speed_min'    => $this->msToKmh((float) $speed),
                'wind_speed_max'    => $gust !== null ? $this->msToKmh((float) $gust) : $this->msToKmh((float) $speed),
                'precipitation'     => (float) $this->getValue($hourly, 'precipitation', $index, 0.0),
                'cloud_cover_low'   => (int) $this->getValue($hourly, 'cloud_cover_low', $index, 0),
                'cloud_cover_mid'   => (int) $this->getValue($hourly, 'cloud_cover_mid', $index, 0),
                'cloud_cover_high'  => (int) $this->getValue($hourly, 'cloud_cover_high', $index, 0),
                // Plafond consensus déjà calculé par le sidecar (Espy, m AMSL).
                'cloud_base_m'      => $this->intOrNull($this->getValue($hourly, 'qui_vole_cloud_base', $index)),
                'temperature'       => (float) $this->getValue($hourly, 'temperature_2m', $index, 0.0),
                'humidity'          => (int) $this->getValue($hourly, 'relative_humidity_2m', $index, 0),
                // Métadonnées consensus (clés extra hors interface, stockées
                // par FetchSiteModelJob dans forecasts.models_*).
                'models_count'      => $this->intOrNull($this->getValue($hourly, 'qui_vole_models_count', $index)),
                'models_converging' => $this->intOrNull($this->getValue($hourly, 'qui_vole_models_converging', $index)),
            ];
        }

        if (empty($parsed) && $skipped > 0) {
            Log::info('ConsensusApi parseResponse: tous les créneaux filtrés', [
                'skipped' => $skipped,
            ]);
        }

        return $parsed;
    }

    private function getValue(array $hourly, string $key, int $index, mixed $default = null): mixed
    {
        return $hourly[$key][$index] ?? $default;
    }

    private function msToKmh(float $ms): float
    {
        return round($ms * self::MS_TO_KMH, 1);
    }

    private function intOrNull(mixed $value): ?int
    {
        return $value === null ? null : (int) round((float) $value);
    }
}
