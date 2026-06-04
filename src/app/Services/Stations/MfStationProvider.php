<?php

declare(strict_types=1);

namespace App\Services\Stations;

use App\Models\StationApi;
use App\Models\WeatherStation;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fournisseur Météo-France pour les stations météo.
 *
 * Endpoints utilisés (API PaquetObs / Paquet Stations Obs) :
 * - GET /liste-stations             → CSV de toutes les stations
 * - GET /paquet/stations/horaire    → observations horaires de toutes les stations
 *
 * Auth : OAuth2 client_credentials via StationApi::getOAuth2Token().
 * Quota : 100 req/min (très confortable pour 1-2 req/heure).
 */
class MfStationProvider implements StationProviderInterface
{
    private const TIMEOUT_S = 60;

    private const OBS_API_BASE = 'https://public-api.meteofrance.fr/public/DPPaquetObs/v1';

    public function network(): string
    {
        return WeatherStation::NETWORK_MF;
    }

    private function api(): ?StationApi
    {
        return StationApi::where('code', 'mf')->first();
    }

    /**
     * Découverte via /liste-stations : requiert un Bearer token OAuth2.
     * Renvoie le CSV de toutes les stations MF. Filtre bbox côté client.
     */
    public function discoverStations(
        float $latMin,
        float $latMax,
        float $lngMin,
        float $lngMax
    ): array {
        $api = $this->api();
        if (! $api) {
            Log::warning('MF station discovery: no API config found');
            return [];
        }

        $token = $api->getOAuth2Token();

        $resp = Http::timeout(self::TIMEOUT_S)
            ->withToken($token)
            ->get(self::OBS_API_BASE . '/liste-stations');

        $api->incrementRequestsToday();

        if (! $resp->ok()) {
            $msg = "MF liste-stations HTTP {$resp->status()}";
            Log::warning($msg, ['body' => mb_substr($resp->body(), 0, 500)]);
            $api->recordError($msg);
            return [];
        }

        $api->recordSuccess();

        return $this->parseCsvStationList($resp->body(), $latMin, $latMax, $lngMin, $lngMax);
    }

    /**
     * Fetch infrahoraire : /paquet/stations/infrahoraire-6m → toutes les
     * stations, 2 appels par run (2 slots de 6 min dans la fenêtre de 12 min).
     * Appelé toutes les 12 min à +9 min du slot pair (ex: xx:09 → xx:00 + xx:06).
     * Coût : 10 appels/heure (quota MF = 100 req/h).
     */
    public function fetchLatestReadings(): array
    {
        $api = $this->api();
        if (! $api) {
            return [];
        }

        $token = $api->getOAuth2Token();

        $now = now();
        $latestMinute = (int) floor($now->minute / 6) * 6;
        $previousMinute = $latestMinute - 6;

        $dates = [];
        if ($previousMinute >= 0) {
            $dates[] = $now->copy()->minute($previousMinute)->second(0)->format('Y-m-d\TH:i:s\Z');
        } else {
            $dates[] = $now->copy()->subHour()->minute(54)->second(0)->format('Y-m-d\TH:i:s\Z');
        }
        $dates[] = $now->copy()->minute($latestMinute)->second(0)->format('Y-m-d\TH:i:s\Z');

        $result = [];

        foreach ($dates as $date) {
            $resp = Http::timeout(self::TIMEOUT_S)
                ->withToken($token)
                ->accept('*/*')
                ->get(self::OBS_API_BASE . '/paquet/stations/infrahoraire-6m', [
                    'format' => 'json',
                    'date'   => $date,
                ]);

            $api->incrementRequestsToday();

            if (! $resp->ok()) {
                $msg = "MF infrahoraire-6m HTTP {$resp->status()} (date={$date})";
                Log::warning($msg, ['body' => mb_substr($resp->body(), 0, 500)]);
                $api->recordError($msg);
                continue;
            }

            $api->recordSuccess();

            $data = $resp->json();

            if (! is_array($data)) {
                Log::warning('MF infrahoraire-6m: réponse non-JSON', [
                    'date'         => $date,
                    'body_preview' => mb_substr($resp->body(), 0, 500),
                ]);
                continue;
            }

            $parsed = $this->parseObservations($data);

            Log::info('MF infrahoraire-6m: OK', [
                'date'      => $date,
                'raw_items' => count($data),
                'parsed'    => count($parsed),
            ]);

            foreach ($parsed as $stationId => $reading) {
                if (! isset($result[$stationId]) || $reading['observed_at']->gt($result[$stationId]['observed_at'])) {
                    $result[$stationId] = $reading;
                }
            }
        }

        return $result;
    }

    /**
     * Parse la liste des stations MF.
     *
     * L'API peut renvoyer du CSV (séparateur ;) ou du texte tabulé.
     * On tente d'abord de détecter le séparateur puis on résout les
     * colonnes par recherche case-insensitive partielle.
     */
    private function parseCsvStationList(
        string $body,
        float $latMin,
        float $latMax,
        float $lngMin,
        float $lngMax
    ): array {
        $body = trim($body);

        if ($body === '') {
            Log::warning('MF liste-stations: réponse vide');
            return [];
        }

        $lines = preg_split('/\r?\n/', $body);
        if (count($lines) < 2) {
            Log::warning('MF liste-stations: moins de 2 lignes', ['preview' => mb_substr($body, 0, 300)]);
            return [];
        }

        $firstLine = $lines[0];
        $sep = (substr_count($firstLine, ';') >= 3) ? ';'
             : ((substr_count($firstLine, "\t") >= 3) ? "\t" : ',');

        $header = str_getcsv(array_shift($lines), $sep);
        $header = array_map('trim', $header);

        Log::info('MF liste-stations: colonnes détectées', [
            'sep'     => $sep === "\t" ? 'TAB' : $sep,
            'header'  => $header,
            'lines'   => count($lines),
        ]);

        $colId   = $this->findColFuzzy($header, ['id_station', 'id', 'numero', 'num_sta', 'numer_sta']);
        $colName = $this->findColFuzzy($header, ['nom_usuel', 'nom', 'name', 'libelle']);
        $colLat  = $this->findColFuzzy($header, ['latitude', 'lat']);
        $colLng  = $this->findColFuzzy($header, ['longitude', 'lon', 'lng']);
        $colAlt  = $this->findColFuzzy($header, ['altitude', 'alt', 'alti']);

        if ($colId === null || $colLat === null || $colLng === null) {
            Log::warning('MF liste-stations: colonnes ID/LAT/LNG introuvables', ['header' => $header]);
            return [];
        }

        $stations = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;

            $cols = str_getcsv($line, $sep);

            $id  = trim($cols[$colId] ?? '');
            $lat = (isset($cols[$colLat]) && is_numeric($cols[$colLat])) ? (float) $cols[$colLat] : null;
            $lng = (isset($cols[$colLng]) && is_numeric($cols[$colLng])) ? (float) $cols[$colLng] : null;

            if ($id === '' || $lat === null || $lng === null) continue;
            if ($lat < $latMin || $lat > $latMax) continue;
            if ($lng < $lngMin || $lng > $lngMax) continue;

            $stations[] = [
                'external_id' => $id,
                'name'        => trim($cols[$colName] ?? $id),
                'latitude'    => $lat,
                'longitude'   => $lng,
                'altitude_m'  => ($colAlt !== null && isset($cols[$colAlt]) && is_numeric($cols[$colAlt]))
                    ? (int) $cols[$colAlt]
                    : null,
            ];
        }

        return $stations;
    }

    /**
     * Parse les observations JSON du paquet horaire MF.
     *
     * Tableau plat d'objets. Champs MF réels :
     *   geo_id_insee (ID station), validity_time (ISO 8601),
     *   dd (dir °), ff (vent moyen m/s), fxi (rafale instantanée m/s),
     *   fxy (vent max 10min m/s), t (temp K), u (humidité %),
     *   pres (pression station hPa), pmer (pression mer hPa),
     *   rr1 (précip 1h mm), td (point de rosée K),
     *   n (couverture nuageuse octa), vv (visibilité m)
     */
    private function parseObservations(mixed $data): array
    {
        $features = $this->extractFeatures($data);
        if (empty($features)) {
            return [];
        }

        $result = [];
        $skippedNoId = 0;
        $sampleKeys = null;
        foreach ($features as $feature) {
            $props = $feature['properties'] ?? $feature;

            if ($sampleKeys === null) {
                $sampleKeys = array_keys($props);
                $sampleIdFields = array_filter([
                    'Id_station'     => $props['Id_station'] ?? null,
                    'numero_sta'     => $props['numero_sta'] ?? null,
                    'geo_id_station' => $props['geo_id_station'] ?? null,
                    'geo_id_insee'   => $props['geo_id_insee'] ?? null,
                ], fn ($v) => $v !== null);
                Log::info('MF parseObservations: sample feature keys', [
                    'keys'      => $sampleKeys,
                    'id_fields' => $sampleIdFields,
                ]);
            }

            $stationId = (string) ($props['Id_station'] ?? $props['numero_sta'] ?? $props['geo_id_station'] ?? $props['geo_id_insee'] ?? '');
            if ($stationId === '') {
                $skippedNoId++;
                continue;
            }

            $obsTimeRaw = $props['validity_time'] ?? $props['date_obs'] ?? null;
            if (! $obsTimeRaw) continue;

            try {
                $obsTime = Carbon::parse($obsTimeRaw);
            } catch (\Throwable) {
                continue;
            }

            if (isset($result[$stationId]) && $result[$stationId]['observed_at']->gte($obsTime)) {
                continue;
            }

            $result[$stationId] = [
                'observed_at'        => $obsTime,
                'wind_direction'     => $this->parseIntField($props, ['dd']),
                'wind_speed_avg'     => $this->msToKmh($props, ['ff']),
                'wind_speed_max'     => $this->msToKmh($props, ['fxi', 'raf', 'rafper']),
                'wind_speed_max_10m' => $this->msToKmh($props, ['fxy']),
                'wind_direction_max' => $this->parseIntField($props, ['dxy']),
                'wind_direction_gust' => $this->parseIntField($props, ['dxi']),
                'temperature'        => $this->kelvinToCelsius($props, ['t']),
                'temperature_min'    => $this->kelvinToCelsius($props, ['tn']),
                'temperature_max'    => $this->kelvinToCelsius($props, ['tx']),
                'humidity'           => $this->parseIntField($props, ['u']),
                'humidity_min'       => $this->parseIntField($props, ['un']),
                'humidity_max'       => $this->parseIntField($props, ['ux']),
                'pressure_hpa'       => $this->pascalToHpa($props, ['pres', 'pmer']),
                'precipitation_mm'   => $this->parseFloatField($props, ['rr1']),
                'cloud_cover_pct'    => $this->octaToPct($props, ['n']),
                'visibility_m'       => $this->parseIntField($props, ['vv']),
                'dew_point'          => $this->kelvinToCelsius($props, ['td']),
                'raw_data'           => $props,
            ];
        }

        if ($skippedNoId > 0) {
            Log::warning('MF parseObservations: features without station ID', ['count' => $skippedNoId]);
        }

        Log::info('MF parseObservations: parsed', [
            'features_count' => count($features),
            'result_count'   => count($result),
            'sample_ids'     => array_slice(array_keys($result), 0, 5),
        ]);

        return $result;
    }

    private function extractFeatures(mixed $data): array
    {
        if (is_array($data) && isset($data['type']) && $data['type'] === 'FeatureCollection') {
            Log::info('MF extractFeatures: FeatureCollection', ['features_count' => count($data['features'] ?? [])]);
            return $data['features'] ?? [];
        }

        if (is_array($data) && ! isset($data['type'])) {
            Log::info('MF extractFeatures: flat array', ['count' => count($data)]);
            return $data;
        }

        Log::warning('MF extractFeatures: unrecognized structure', [
            'type'    => gettype($data),
            'preview' => is_array($data) ? array_keys(array_slice($data, 0, 3, true)) : mb_substr((string) $data, 0, 200),
        ]);
        return [];
    }

    // ── Helpers de conversion ──────────────────────────────────────

    private function msToKmh(array $props, array $keys): ?float
    {
        $val = $this->rawFloat($props, $keys);
        return $val !== null ? round($val * 3.6, 1) : null;
    }

    private function pascalToHpa(array $props, array $keys): ?float
    {
        $val = $this->rawFloat($props, $keys);
        if ($val === null) return null;
        if ($val > 10000) return round($val / 100, 1);
        return round($val, 1);
    }

    private function kelvinToCelsius(array $props, array $keys): ?float
    {
        $val = $this->rawFloat($props, $keys);
        if ($val === null) return null;
        if ($val > 200) return round($val - 273.15, 1);
        return round($val, 1);
    }

    private function octaToPct(array $props, array $keys): ?int
    {
        $val = $this->rawFloat($props, $keys);
        if ($val === null) return null;
        return (int) round($val / 8 * 100);
    }

    private function parseIntField(array $props, array $keys): ?int
    {
        $val = $this->rawFloat($props, $keys);
        return $val !== null ? (int) round($val) : null;
    }

    private function parseFloatField(array $props, array $keys): ?float
    {
        $val = $this->rawFloat($props, $keys);
        return $val !== null ? round($val, 1) : null;
    }

    private function rawFloat(array $props, array $keys): ?float
    {
        foreach ($keys as $k) {
            if (isset($props[$k]) && is_numeric($props[$k])) {
                return (float) $props[$k];
            }
        }
        return null;
    }

    /**
     * Recherche case-insensitive + substring match dans les headers.
     */
    private function findColFuzzy(array $header, array $candidates): ?int
    {
        $headerLower = array_map('strtolower', $header);

        foreach ($candidates as $name) {
            $nameLower = strtolower($name);
            $idx = array_search($nameLower, $headerLower, true);
            if ($idx !== false) return $idx;
        }

        foreach ($candidates as $name) {
            $nameLower = strtolower($name);
            foreach ($headerLower as $idx => $h) {
                if (str_contains($h, $nameLower)) {
                    return $idx;
                }
            }
        }

        return null;
    }
}
