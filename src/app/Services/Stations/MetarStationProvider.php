<?php

declare(strict_types=1);

namespace App\Services\Stations;

use App\Models\StationApi;
use App\Models\WeatherStation;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fournisseur METAR pour les stations météo, via l'API NOAA Aviation
 * Weather Center.
 *
 * Migré depuis App\Services\Balises\MetarProvider — même logique de
 * découverte et de polling, mais cible WeatherStation au lieu de Balise
 * et renvoie des observations plus riches (pression, visibilité,
 * point de rosée, données brutes).
 */
class MetarStationProvider implements StationProviderInterface
{
    private const TIMEOUT_S = 30;
    private const KT_TO_KMH = 1.852;
    private const DISCOVERY_FRESHNESS_HOURS = 6;

    public function network(): string
    {
        return WeatherStation::NETWORK_METAR;
    }

    private function baseUrl(): string
    {
        $api = StationApi::where('code', 'metar')->first();

        return $api?->base_url ?: 'https://aviationweather.gov/api/data/metar';
    }

    public function discoverStations(
        float $latMin,
        float $latMax,
        float $lngMin,
        float $lngMax
    ): array {
        $bbox = sprintf('%s,%s,%s,%s', $latMin, $lngMin, $latMax, $lngMax);

        $resp = Http::timeout(self::TIMEOUT_S)
            ->get($this->baseUrl(), [
                'bbox'   => $bbox,
                'format' => 'json',
                'hours'  => self::DISCOVERY_FRESHNESS_HOURS,
            ]);

        if (! $resp->ok()) {
            Log::warning('METAR station discovery HTTP error', [
                'status' => $resp->status(),
                'bbox'   => $bbox,
            ]);
            return [];
        }

        $obs = $resp->json();
        if (! is_array($obs) || empty($obs)) {
            return [];
        }

        $byIcao = [];
        foreach ($obs as $o) {
            $icao = $o['icaoId'] ?? null;
            if (! $icao) continue;

            $lat = isset($o['lat']) ? (float) $o['lat'] : null;
            $lng = isset($o['lon']) ? (float) $o['lon'] : null;
            if ($lat === null || $lng === null) continue;
            if ($lat < $latMin || $lat > $latMax) continue;
            if ($lng < $lngMin || $lng > $lngMax) continue;
            if (isset($byIcao[$icao])) continue;

            $byIcao[$icao] = [
                'external_id' => (string) $icao,
                'name'        => trim((string) ($o['name'] ?? $icao)),
                'latitude'    => $lat,
                'longitude'   => $lng,
                'altitude_m'  => isset($o['elev']) ? (int) $o['elev'] : null,
            ];
        }

        return array_values($byIcao);
    }

    public function fetchLatestReadings(): array
    {
        $icaoIds = WeatherStation::where('network', $this->network())
            ->where('active', true)
            ->pluck('external_id')
            ->all();

        if (empty($icaoIds)) {
            return [];
        }

        $resp = Http::timeout(self::TIMEOUT_S)
            ->get($this->baseUrl(), [
                'ids'    => implode(',', $icaoIds),
                'format' => 'json',
                'hours'  => 2,
            ]);

        if (! $resp->ok()) {
            Log::warning('METAR station fetch HTTP error', [
                'status'    => $resp->status(),
                'ids_count' => count($icaoIds),
            ]);
            return [];
        }

        $obs = $resp->json();
        if (! is_array($obs)) {
            return [];
        }

        $latestByIcao = [];
        foreach ($obs as $o) {
            $icao = $o['icaoId'] ?? null;
            if (! $icao) continue;

            $obsTime = $this->parseObsTime($o);
            if (! $obsTime) continue;

            if (isset($latestByIcao[$icao]) && $latestByIcao[$icao]['_t']->gte($obsTime)) {
                continue;
            }

            $temp = isset($o['temp']) ? (float) $o['temp'] : null;
            $dewp = isset($o['dewp']) ? (float) $o['dewp'] : null;

            $latestByIcao[$icao] = [
                '_t'               => $obsTime,
                'observed_at'      => $obsTime,
                'wind_direction'   => $this->parseWindDir($o['wdir'] ?? null),
                'wind_speed_avg'   => $this->ktToKmh($o['wspd'] ?? null),
                'wind_speed_max'   => $this->ktToKmh($o['wgst'] ?? ($o['wspd'] ?? null)),
                'temperature'      => $temp,
                'humidity'         => $this->computeRh($temp, $dewp),
                'pressure_hpa'     => isset($o['altim']) ? round((float) $o['altim'] * 33.8639, 1) : null,
                'precipitation_mm' => null,
                'cloud_cover_pct'  => $this->parseCloudCover($o['clouds'] ?? null),
                'visibility_m'     => $this->parseVisibility($o['visib'] ?? null),
                'dew_point'        => $dewp,
                'raw_data'         => $o,
            ];
        }

        $result = [];
        foreach ($latestByIcao as $icao => $reading) {
            unset($reading['_t']);
            $result[(string) $icao] = $reading;
        }
        return $result;
    }

    private function parseObsTime(array $o): ?Carbon
    {
        if (isset($o['obsTime']) && is_numeric($o['obsTime'])) {
            return Carbon::createFromTimestamp((int) $o['obsTime']);
        }
        if (! empty($o['reportTime'])) {
            try {
                return Carbon::parse($o['reportTime']);
            } catch (\Throwable) {
                return null;
            }
        }
        return null;
    }

    private function parseWindDir(mixed $wdir): ?int
    {
        if ($wdir === null || $wdir === '' || $wdir === 'VRB') {
            return null;
        }
        if (! is_numeric($wdir)) return null;
        $dir = (int) $wdir;
        if ($dir < 0 || $dir > 360) return null;
        return $dir % 360;
    }

    private function ktToKmh(mixed $kt): ?float
    {
        if ($kt === null || $kt === '' || ! is_numeric($kt)) {
            return null;
        }
        return round((float) $kt * self::KT_TO_KMH, 1);
    }

    private function computeRh(?float $temp, ?float $dewp): ?int
    {
        if ($temp === null || $dewp === null) return null;
        $a = 17.625; $b = 243.04;
        $alpha = exp(($a * $dewp) / ($b + $dewp)) / exp(($a * $temp) / ($b + $temp));
        $rh = (int) round($alpha * 100);
        return max(0, min(100, $rh));
    }

    /**
     * METAR cloud layers → cloud cover percentage estimate.
     * SKC/CLR=0%, FEW=25%, SCT=50%, BKN=75%, OVC=100%.
     */
    private function parseCloudCover(mixed $clouds): ?int
    {
        if (! is_array($clouds) || empty($clouds)) {
            return null;
        }

        $coverMap = [
            'SKC' => 0, 'CLR' => 0, 'NSC' => 0,
            'FEW' => 25, 'SCT' => 50, 'BKN' => 75, 'OVC' => 100,
        ];

        $maxCover = 0;
        foreach ($clouds as $layer) {
            $code = strtoupper((string) ($layer['cover'] ?? ''));
            if (isset($coverMap[$code])) {
                $maxCover = max($maxCover, $coverMap[$code]);
            }
        }

        return $maxCover > 0 ? $maxCover : null;
    }

    /**
     * METAR visibility : statute miles → metres.
     */
    private function parseVisibility(mixed $visib): ?int
    {
        if ($visib === null || $visib === '' || ! is_numeric($visib)) {
            return null;
        }
        return (int) round((float) $visib * 1609.34);
    }
}
