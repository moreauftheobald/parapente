<?php

declare(strict_types=1);

namespace App\Services\Stations;

use App\Models\StationApi;
use App\Models\WeatherStation;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fournisseur Infoclimat pour les stations météo (réseau StatIC).
 *
 * - Découverte : GET /opendata/stations_xhr.php (sans auth)
 * - Observations : GET /opendata/?method=get&format=json&token=…&stations[]=…
 *
 * Les données sont remontées toutes les ~10 min par les stations.
 * On ne garde que la dernière observation par station.
 * Unités natives : °C, km/h, hPa, % — aucune conversion nécessaire.
 */
class InfoclimatStationProvider implements StationProviderInterface
{
    private const TIMEOUT_S = 30;
    private const STATIONS_PER_REQUEST = 50;

    public function network(): string
    {
        return WeatherStation::NETWORK_INFOCLIMAT;
    }

    private function api(): ?StationApi
    {
        return StationApi::where('code', 'infoclimat')->first();
    }

    public function discoverStations(
        float $latMin,
        float $latMax,
        float $lngMin,
        float $lngMax
    ): array {
        $resp = Http::timeout(self::TIMEOUT_S)
            ->get('https://www.infoclimat.fr/opendata/stations_xhr.php');

        if (! $resp->ok()) {
            Log::warning('Infoclimat stations_xhr HTTP error', ['status' => $resp->status()]);
            return [];
        }

        $all = $resp->json();
        if (! is_array($all)) {
            return [];
        }

        $stations = [];
        $cutoff = now()->subMonths(6);

        foreach ($all as $s) {
            $id  = $s['id'] ?? null;
            $lat = isset($s['latitude']) ? (float) $s['latitude'] : null;
            $lng = isset($s['longitude']) ? (float) $s['longitude'] : null;

            if (! $id || $lat === null || $lng === null) continue;
            if ($lat < $latMin || $lat > $latMax) continue;
            if ($lng < $lngMin || $lng > $lngMax) continue;

            $lastReport = $s['derniere_activite'] ?? $s['last_report'] ?? null;
            if ($lastReport && $lastReport !== '0000-00-00 00:00:00') {
                try {
                    if (Carbon::parse($lastReport)->lt($cutoff)) continue;
                } catch (\Throwable) {
                }
            } elseif ($lastReport === '0000-00-00 00:00:00') {
                continue;
            }

            $stations[] = [
                'external_id' => (string) $id,
                'name'        => trim((string) ($s['libelle'] ?? $id)),
                'latitude'    => $lat,
                'longitude'   => $lng,
                'altitude_m'  => isset($s['altitude']) ? (int) $s['altitude'] : null,
            ];
        }

        return $stations;
    }

    /**
     * Toutes les observations brutes par station, indexées par external_id.
     * Chaque entrée est un tableau d'observations triées chronologiquement.
     * Alimenté lors du dernier fetchLatestReadings().
     *
     * @var array<string, list<array>>
     */
    private array $allObservations = [];

    public function getAllObservations(): array
    {
        return $this->allObservations;
    }

    public function fetchLatestReadings(): array
    {
        $api = $this->api();
        if (! $api) {
            return [];
        }

        $token = $api->api_key;
        if (empty($token)) {
            Log::warning('Infoclimat: no API key configured');
            return [];
        }

        $stationIds = WeatherStation::where('network', $this->network())
            ->where('active', true)
            ->pluck('external_id')
            ->all();

        if (empty($stationIds)) {
            return [];
        }

        $today = now()->format('Y-m-d');
        $result = [];
        $this->allObservations = [];

        foreach (array_chunk($stationIds, self::STATIONS_PER_REQUEST) as $chunk) {
            $query = http_build_query([
                'method' => 'get',
                'format' => 'json',
                'token'  => $token,
                'start'  => $today,
                'end'    => $today,
            ]);
            foreach ($chunk as $id) {
                $query .= '&' . urlencode('stations[]') . '=' . urlencode($id);
            }

            $resp = Http::timeout(self::TIMEOUT_S)
                ->get('https://www.infoclimat.fr/opendata/?' . $query);

            $api->incrementRequestsToday();

            if (! $resp->ok()) {
                Log::warning('Infoclimat fetch HTTP error', [
                    'status' => $resp->status(),
                    'chunk'  => count($chunk),
                ]);
                $api->recordError("Infoclimat HTTP {$resp->status()}");
                continue;
            }

            $data = $resp->json();
            if (($data['status'] ?? '') !== 'OK') {
                Log::warning('Infoclimat fetch status not OK', [
                    'status' => $data['status'] ?? 'unknown',
                    'errors' => $data['errors'] ?? [],
                ]);
                continue;
            }

            $hourly = $data['hourly'] ?? [];
            foreach ($hourly as $stationId => $observations) {
                if (! is_array($observations) || empty($observations)) continue;

                $parsed = [];
                foreach ($observations as $obs) {
                    if (! is_array($obs)) continue;
                    $row = $this->parseRow($obs);
                    if ($row) $parsed[] = $row;
                }

                if (empty($parsed)) continue;

                $this->allObservations[(string) $stationId] = $parsed;
                $result[(string) $stationId] = end($parsed);
            }
        }

        if (! empty($result)) {
            $api->recordSuccess();
        }

        Log::info('Infoclimat fetch: OK', [
            'stations_queried'    => count($stationIds),
            'readings_parsed'     => count($result),
            'total_observations'  => array_sum(array_map('count', $this->allObservations)),
        ]);

        return $result;
    }

    private function parseRow(array $obs): ?array
    {
        $obsTimeRaw = $obs['dh_utc'] ?? null;
        if (! $obsTimeRaw) return null;

        try {
            $obsTime = Carbon::parse($obsTimeRaw);
        } catch (\Throwable) {
            return null;
        }

        return [
            'observed_at'      => $obsTime,
            'wind_direction'   => $this->parseInt($obs['vent_direction'] ?? null),
            'wind_speed_avg'   => $this->parseFloat($obs['vent_moyen'] ?? null),
            'wind_speed_max'   => $this->parseFloat($obs['vent_rafales'] ?? null),
            'temperature'      => $this->parseFloat($obs['temperature'] ?? null),
            'humidity'         => $this->parseInt($obs['humidite'] ?? null),
            'pressure_hpa'     => $this->parseFloat($obs['pression'] ?? null),
            'precipitation_mm' => $this->parseFloat($obs['pluie_1h'] ?? null),
            'cloud_cover_pct'  => null,
            'visibility_m'     => null,
            'dew_point'        => $this->parseFloat($obs['point_de_rosee'] ?? null),
            'raw_data'         => $obs,
        ];
    }

    private function parseFloat(mixed $val): ?float
    {
        if ($val === null || $val === '' || ! is_numeric($val)) return null;
        return round((float) $val, 1);
    }

    private function parseInt(mixed $val): ?int
    {
        if ($val === null || $val === '' || ! is_numeric($val)) return null;
        return (int) round((float) $val);
    }
}
