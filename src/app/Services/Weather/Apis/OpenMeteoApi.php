<?php

declare(strict_types=1);

namespace App\Services\Weather\Apis;

use App\Models\Site;
use App\Models\WeatherApi;
use App\Models\WeatherModel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenMeteoApi implements WeatherApiInterface
{
    private const DAYS            = 5;
    private const WIND_UNIT       = 'kmh';
    private const TIMEZONE        = 'Europe/Paris';
    private const BATCH_TIMEOUT_S = 60;
    private const BATCH_CHUNK     = 40;

    /**
     * Variables horaires nécessaires au scoring d'un site (voting logic
     * complète : vent + précip + nuages + plafond via dew_point).
     */
    private const HOURLY_VARS = [
        'wind_speed_10m',
        'wind_gusts_10m',
        'wind_direction_10m',
        'precipitation',
        'cloud_cover',
        'cloud_cover_low',
        'cloud_cover_mid',
        'cloud_cover_high',
        'temperature_2m',
        'relative_humidity_2m',
        'dew_point_2m',
    ];

    /**
     * Sous-ensemble utilisé pour le batch balises (4 variables suffisent :
     * pas de scoring complet, juste les paramètres affichés).
     * Garder ce subset minimal — agrandir = plus de bytes/balise sur le
     * batch (parfois 40 points/chunk × N variables).
     */
    private const HOURLY_VARS_BALISES = [
        'wind_speed_10m',
        'wind_gusts_10m',
        'wind_direction_10m',
        'temperature_2m',
    ];

    /**
     * Variables horaires pour le batch stations météo (payload étendu :
     * les stations pro mesurent humidité, précipitations, pression,
     * couverture nuageuse — on archive tout ce qui est comparable).
     */
    private const HOURLY_VARS_STATIONS = [
        'wind_speed_10m',
        'wind_gusts_10m',
        'wind_direction_10m',
        'temperature_2m',
        'dew_point_2m',
        'relative_humidity_2m',
        'precipitation',
        'pressure_msl',
        'cloud_cover',
    ];

    // Variables journalières — agrégées à la volée par Open-Meteo.
    // temperature_2m_max sert de "température de déclenchement" des
    // thermiques pour l'estimation de la base des cumulus.
    private const DAILY_VARS = [
        'temperature_2m_max',
    ];

    private string $baseUrl = 'https://api.open-meteo.com/v1';
    private ?WeatherApi $config = null;

    public function code(): string
    {
        return 'openmeteo';
    }

    public function setConfig(WeatherApi $config): void
    {
        $this->config  = $config;
        $this->baseUrl = rtrim($config->base_url ?: $this->baseUrl, '/');
    }

    public function supportedModelCodes(): array
    {
        // Modèles servis par le serveur Open-Meteo self-hosted dédié
        // au projet (cf. OPEN_METEO_MODELS dans la config docker), plus
        // les modèles routés sur l'API publique (UKMO).
        return [
            // ── Météo-France ─────────────────────────────────────
            'meteofrance_arome_france_hd',
            'meteofrance_arome_france_hd_15min',
            'meteofrance_arome_france0025',
            'meteofrance_arpege_europe',
            // ── DWD ──────────────────────────────────────────────
            'dwd_icon_eu',
            'dwd_icon_d2',
            'dwd_icon',
            // ── NOAA / NCEP ──────────────────────────────────────
            'ncep_gfs013',
            'ncep_gfs_graphcast025',
            'ncep_aigfs025',
            'ncep_aigefs025',
            'ncep_hgefs025_ensemble_mean',
            // ── ECMWF ────────────────────────────────────────────
            'ecmwf_ifs025',
            'ecmwf_aifs025_single',
            // ── Autres globaux ───────────────────────────────────
            'ukmo_global_deterministic_10km',
            'cmc_gem_gdps',
            'cmc_gem_rdps',
            'bom_access_global',
            'cma_grapes_global',
            'jma_gsm',
        ];
    }

    /**
     * Fetch brut : retourne la réponse JSON décodée (avant parsing/filtrage)
     * pour un site/modèle donné. Sert au diagnostic admin — affiché par
     * le bouton « Brut » du tableau de fraîcheur quand un modèle renvoie
     * 0 créneau après parsing.
     *
     * Retourne null si l'appel HTTP échoue. URL appelée incluse pour
     * faciliter la reproduction en curl.
     */
    public function fetchRaw(Site $site, WeatherModel $model): ?array
    {
        $params = [
            'latitude'        => $site->latitude,
            'longitude'       => $site->longitude,
            'hourly'          => implode(',', self::HOURLY_VARS),
            'daily'           => implode(',', self::DAILY_VARS),
            'models'          => $model->code,
            'forecast_days'   => self::DAYS,
            'wind_speed_unit' => self::WIND_UNIT,
            'timezone'        => self::TIMEZONE,
        ];

        try {
            $response = Http::timeout(15)->get($this->baseUrl . '/forecast', $params);
            return [
                'status'  => $response->status(),
                'success' => $response->successful(),
                'url'     => $this->baseUrl . '/forecast?' . http_build_query($params),
                'body'    => $response->successful() ? $response->json() : $response->body(),
            ];
        } catch (\Exception $e) {
            return [
                'status'  => 0,
                'success' => false,
                'url'     => $this->baseUrl . '/forecast?' . http_build_query($params),
                'body'    => 'Exception: ' . $e->getMessage(),
            ];
        }
    }

    public function fetchForSiteAndModel(Site $site, WeatherModel $model): array
    {
        try {
            $response = Http::timeout(15)
                ->get($this->baseUrl . '/forecast', [
                    'latitude'        => $site->latitude,
                    'longitude'       => $site->longitude,
                    'hourly'          => implode(',', self::HOURLY_VARS),
                    'daily'           => implode(',', self::DAILY_VARS),
                    'models'          => $model->code,
                    'forecast_days'   => self::DAYS,
                    'wind_speed_unit' => self::WIND_UNIT,
                    'timezone'        => self::TIMEZONE,
                ]);

            $this->config?->incrementRequestsToday();

            if (! $response->successful()) {
                $msg = "Open-Meteo HTTP {$response->status()} site={$site->slug} model={$model->code}";
                Log::warning($msg);
                $this->config?->recordError($msg);
                return [];
            }

            $this->config?->recordSuccess();
            return $this->parseResponse($response->json(), (int) ($site->altitude_m ?? 0));
        } catch (\Exception $e) {
            Log::error('OpenMeteoApi fetch failed', [
                'site'    => $site->slug,
                'model'   => $model->code,
                'message' => $e->getMessage(),
            ]);
            $this->config?->recordError($e->getMessage());
            return [];
        }
    }

    /**
     * Fetch batch multi-coordonnées pour les balises (non-standard,
     * spécifique à Open-Meteo et hors interface).
     *
     * @param  array<int, array{id:int|string, lat:float, lng:float}> $points
     */
    public function fetchBatchForBalises(array $points, WeatherModel $model): array
    {
        if (empty($points)) {
            return [];
        }

        $result = [];
        $chunks = array_chunk(array_values($points), self::BATCH_CHUNK);
        foreach ($chunks as $chunk) {
            $partial = $this->fetchBatchChunk($chunk, $model);
            foreach ($partial as $id => $parsed) {
                $result[$id] = $parsed;
            }
        }
        return $result;
    }

    private function fetchBatchChunk(array $points, WeatherModel $model): array
    {
        $lats = array_map(fn ($p) => (string) $p['lat'], $points);
        $lngs = array_map(fn ($p) => (string) $p['lng'], $points);
        $ids  = array_map(fn ($p) => $p['id'], $points);

        try {
            $response = Http::timeout(self::BATCH_TIMEOUT_S)
                ->get($this->baseUrl . '/forecast', [
                    'latitude'        => implode(',', $lats),
                    'longitude'       => implode(',', $lngs),
                    'hourly'          => implode(',', self::HOURLY_VARS_BALISES),
                    'models'          => $model->code,
                    'forecast_days'   => self::DAYS,
                    'wind_speed_unit' => self::WIND_UNIT,
                    'timezone'        => self::TIMEZONE,
                ]);

            $this->config?->incrementRequestsToday();

            if (! $response->successful()) {
                Log::warning('OpenMeteo batch error', [
                    'model'       => $model->code,
                    'status'      => $response->status(),
                    'point_count' => count($points),
                ]);
                return [];
            }

            $payload  = $response->json();
            $stations = isset($payload[0]) ? $payload : [$payload];

            $result = [];
            foreach ($stations as $idx => $stationData) {
                $id = $ids[$idx] ?? null;
                if ($id === null) {
                    continue;
                }
                $parsed = $this->parseBaliseResponse($stationData);
                if (! empty($parsed)) {
                    $result[$id] = $parsed;
                }
            }
            return $result;
        } catch (\Exception $e) {
            Log::error('OpenMeteo batch failed', [
                'model'       => $model->code,
                'message'     => $e->getMessage(),
                'point_count' => count($points),
            ]);
            return [];
        }
    }

    private function parseBaliseResponse(array $data): array
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
            // On garde toute la journée en cours (même les heures déjà passées)
            // pour qu'un site activé à 14h ait quand même le scoring du matin
            // côté lecture. Le scope `Forecast::upcoming()` filtre ensuite à
            // partir de now()->startOfHour() pour ne pas exposer du passé.
            if ($forecastAt->lt(now()->startOfDay())) {
                continue;
            }

            $dir   = $this->getValue($hourly, 'wind_direction_10m', $index);
            $speed = $this->getValue($hourly, 'wind_speed_10m', $index);
            if ($dir === null || $speed === null) {
                $skipped++;
                continue;
            }

            $gust = $this->getValue($hourly, 'wind_gusts_10m', $index);
            $temp = $this->getValue($hourly, 'temperature_2m', $index);

            $parsed[$forecastAt->format('Y-m-d H:i:s')] = [
                'wind_direction' => (int) $dir,
                'wind_speed_avg' => (float) $speed,
                'wind_speed_min' => (float) $speed,
                'wind_speed_max' => (float) ($gust ?? $speed),
                'temperature'    => $temp !== null ? (float) $temp : 0.0,
            ];
        }

        if (empty($parsed) && $skipped > 0) {
            Log::info('OpenMeteoApi parseBaliseResponse: tous les créneaux filtrés', [
                'skipped' => $skipped,
            ]);
        }

        return $parsed;
    }

    /**
     * Fetch batch multi-coordonnées pour les stations météo (payload
     * étendu avec humidité, précip, pression, couverture nuageuse).
     *
     * @param  array<int, array{id:int|string, lat:float, lng:float}> $points
     * @return array<int|string, array<string, array<string, float|int|null>>>
     */
    public function fetchBatchForStations(array $points, WeatherModel $model): array
    {
        if (empty($points)) {
            return [];
        }

        $result = [];
        $chunks = array_chunk(array_values($points), self::BATCH_CHUNK);
        foreach ($chunks as $chunk) {
            $partial = $this->fetchStationBatchChunk($chunk, $model);
            foreach ($partial as $id => $parsed) {
                $result[$id] = $parsed;
            }
        }
        return $result;
    }

    private function fetchStationBatchChunk(array $points, WeatherModel $model): array
    {
        $lats = array_map(fn ($p) => (string) $p['lat'], $points);
        $lngs = array_map(fn ($p) => (string) $p['lng'], $points);
        $ids  = array_map(fn ($p) => $p['id'], $points);

        try {
            $response = Http::timeout(self::BATCH_TIMEOUT_S)
                ->get($this->baseUrl . '/forecast', [
                    'latitude'        => implode(',', $lats),
                    'longitude'       => implode(',', $lngs),
                    'hourly'          => implode(',', self::HOURLY_VARS_STATIONS),
                    'models'          => $model->code,
                    'forecast_days'   => self::DAYS,
                    'wind_speed_unit' => self::WIND_UNIT,
                    'timezone'        => self::TIMEZONE,
                ]);

            $this->config?->incrementRequestsToday();

            if (! $response->successful()) {
                Log::warning('OpenMeteo station batch error', [
                    'model'       => $model->code,
                    'status'      => $response->status(),
                    'point_count' => count($points),
                ]);
                return [];
            }

            $payload  = $response->json();
            $stations = isset($payload[0]) ? $payload : [$payload];

            $result = [];
            foreach ($stations as $idx => $stationData) {
                $id = $ids[$idx] ?? null;
                if ($id === null) {
                    continue;
                }
                $parsed = $this->parseStationResponse($stationData);
                if (! empty($parsed)) {
                    $result[$id] = $parsed;
                }
            }
            return $result;
        } catch (\Exception $e) {
            Log::error('OpenMeteo station batch failed', [
                'model'       => $model->code,
                'message'     => $e->getMessage(),
                'point_count' => count($points),
            ]);
            return [];
        }
    }

    private function parseStationResponse(array $data): array
    {
        $hourly = $data['hourly'] ?? [];
        $times  = $hourly['time'] ?? [];
        if (empty($times)) {
            return [];
        }

        $parsed = [];
        foreach ($times as $index => $time) {
            $forecastAt = \Carbon\Carbon::parse($time, self::TIMEZONE);
            if ($forecastAt->lt(now()->startOfDay())) {
                continue;
            }

            $parsed[$forecastAt->format('Y-m-d H:i:s')] = [
                'wind_direction' => $this->getIntValue($hourly, 'wind_direction_10m', $index),
                'wind_speed_avg' => $this->getFloatValue($hourly, 'wind_speed_10m', $index),
                'wind_speed_max' => $this->getFloatValue($hourly, 'wind_gusts_10m', $index),
                'temperature'    => $this->getFloatValue($hourly, 'temperature_2m', $index),
                'dew_point'      => $this->getFloatValue($hourly, 'dew_point_2m', $index),
                'humidity'       => $this->getIntValue($hourly, 'relative_humidity_2m', $index),
                'precipitation'  => $this->getFloatValue($hourly, 'precipitation', $index),
                'pressure_hpa'   => $this->getFloatValue($hourly, 'pressure_msl', $index),
                'cloud_cover'    => $this->getIntValue($hourly, 'cloud_cover', $index),
            ];
        }

        return $parsed;
    }

    private function getFloatValue(array $hourly, string $key, int $index): ?float
    {
        $val = $this->getValue($hourly, $key, $index);
        return $val !== null ? (float) $val : null;
    }

    private function getIntValue(array $hourly, string $key, int $index): ?int
    {
        $val = $this->getValue($hourly, $key, $index);
        return $val !== null ? (int) $val : null;
    }

    private function parseResponse(array $data, int $siteAltitudeM = 0): array
    {
        $hourly = $data['hourly'] ?? [];
        $times  = $hourly['time'] ?? [];

        if (empty($times)) {
            return [];
        }

        // Référence "sol" pour la base des cumulus = élévation réelle du
        // point de grille du modèle (renvoyée par Open-Meteo), sinon
        // l'altitude du site en repli.
        $refElevationM = isset($data['elevation']) ? (int) round((float) $data['elevation']) : $siteAltitudeM;

        // T max journalière de T₂ₘ (température de déclenchement des
        // thermiques), indexée par date (Y-m-d).
        $tMaxByDay = [];
        $daily = $data['daily'] ?? [];
        if (! empty($daily['time']) && ! empty($daily['temperature_2m_max'])) {
            foreach ($daily['time'] as $i => $d) {
                $v = $daily['temperature_2m_max'][$i] ?? null;
                if ($v !== null) {
                    $tMaxByDay[(string) $d] = (float) $v;
                }
            }
        }

        $parsed = [];
        $skipped = 0;

        foreach ($times as $index => $time) {
            $forecastAt = \Carbon\Carbon::parse($time, self::TIMEZONE);
            // cf. parseBaliseResponse — on garde toute la journée en cours
            // (idem pour la même raison).
            if ($forecastAt->lt(now()->startOfDay())) {
                continue;
            }

            $windDir   = $this->getValue($hourly, 'wind_direction_10m', $index);
            $windSpeed = $this->getValue($hourly, 'wind_speed_10m', $index);

            // Vent direction + vitesse = seules variables indispensables.
            // Humidité, dew point, nuages, plafond, précip restent optionnels
            // (certains modèles globaux comme UKMO ne servent pas
            // `relative_humidity_2m`, sans ça on excluait des modèles entiers
            // pour rien).
            if ($windDir === null || $windSpeed === null) {
                $skipped++;
                continue;
            }

            $parsed[$forecastAt->format('Y-m-d H:i:s')] = [
                'wind_direction'   => $windDir,
                'wind_speed_avg'   => $windSpeed,
                'wind_speed_min'   => $windSpeed,
                'wind_speed_max'   => $this->getValue($hourly, 'wind_gusts_10m', $index),
                'precipitation'    => $this->getValue($hourly, 'precipitation', $index, 0.0),
                'cloud_cover_low'  => $this->getValue($hourly, 'cloud_cover_low', $index, 0),
                'cloud_cover_mid'  => $this->getValue($hourly, 'cloud_cover_mid', $index, 0),
                'cloud_cover_high' => $this->getValue($hourly, 'cloud_cover_high', $index, 0),
                'cloud_base_m'     => $this->estimateCloudBase(
                    $this->getValue($hourly, 'temperature_2m', $index),
                    $tMaxByDay[$forecastAt->format('Y-m-d')] ?? null,
                    $this->getValue($hourly, 'dew_point_2m', $index),
                    $this->getValue($hourly, 'relative_humidity_2m', $index),
                    $refElevationM
                ),
                'temperature'      => $this->getValue($hourly, 'temperature_2m', $index),
                'humidity'         => $this->getValue($hourly, 'relative_humidity_2m', $index),
            ];
        }

        if (empty($parsed) && $skipped > 0) {
            Log::info('OpenMeteoApi parseResponse: tous les créneaux filtrés', [
                'skipped' => $skipped,
                'cause'   => 'wind_direction_10m ou wind_speed_10m null sur tous les créneaux',
            ]);
        }

        return $parsed;
    }

    private function getValue(array $hourly, string $key, int $index, mixed $default = null): mixed
    {
        return $hourly[$key][$index] ?? $default;
    }

    /**
     * Base des cumulus estimée (altitude absolue ASL, en mètres).
     *
     * Règle d'Espy / "spread rule" avec température de déclenchement :
     *
     *   plafond_AGL ≈ 125 m × (T_déclenchement − Td₂ₘ)
     *   plafond_ASL  = élévation_modèle + plafond_AGL
     *
     * Le 125 vient du resserrement de l'écart T/Td à la montée :
     * adiabatique sèche ≈ 9,8 °C/km, lapse rate du point de rosée
     * ≈ 1,8 °C/km ⇒ le spread se referme à ≈ 8 °C/km, soit 1 °C / 125 m.
     *
     * - T_déclenchement = T₂ₘ max du jour (la base des cumulus thermiques
     *   est fixée par la phase de chauffe la plus forte) ; repli sur la
     *   T₂ₘ horaire si la max journalière est absente.
     * - Td : `dew_point_2m` du modèle ; à défaut, approximé depuis
     *   l'humidité relative (règle du 1/5e).
     * - Référence verticale : élévation du point de grille du modèle.
     */
    private function estimateCloudBase(
        ?float $temperatureHour,
        ?float $temperatureMaxDay,
        ?float $dewPoint,
        ?int $humidity,
        int $referenceElevationM
    ): ?int {
        $trigger = $temperatureMaxDay ?? $temperatureHour;
        if ($trigger === null) {
            return null;
        }

        if ($dewPoint === null) {
            if ($humidity === null) {
                return null;
            }
            $dewPoint = ($temperatureHour ?? $trigger) - ((100 - $humidity) / 5);
        }

        $spread       = max(0.0, $trigger - $dewPoint); // l'écart ne peut être négatif
        $cloudBaseAgl = (int) round(125 * $spread);

        return max(0, $referenceElevationM + $cloudBaseAgl);
    }
}
