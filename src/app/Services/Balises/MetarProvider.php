<?php

declare(strict_types=1);

namespace App\Services\Balises;

use App\Models\Balise;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fournisseur METAR via l'API NOAA Aviation Weather Center.
 *
 * Doc : https://aviationweather.gov/data/api/
 *
 * Endpoints :
 *  - GET /api/data/metar?bbox=lat0,lon0,lat1,lon1&format=json&hours=24
 *      → toutes les obs récentes dans une bbox (utilisé pour la
 *        découverte : on extrait les stations uniques)
 *  - GET /api/data/metar?ids=LFSF,LFSN&format=json&hours=2
 *      → dernière obs de chaque station listée (utilisé pour le
 *        polling périodique)
 *
 * METAR sortent toutes les 30 min en standard, parfois plus vite
 * (SPECI) en cas de changement météo brutal. Le polling à 30 min
 * suffit pour ne rien manquer de significatif.
 *
 * ⚠️ Conventions :
 *  - Vitesse vent : METAR exprime en KNOTS, on convertit en km/h
 *    (×1.852).
 *  - Direction : convention FROM (météo standard) — alignée avec
 *    notre stack.
 *  - METAR ne fournit pas wind_speed_min ; on met l'avg comme
 *    fallback (cohérent avec OpenMeteoApi).
 */
class MetarProvider implements BaliseProviderInterface
{
    private const BASE_URL  = 'https://aviationweather.gov/api/data/metar';
    private const TIMEOUT_S = 30;

    /** Conversion knots → km/h */
    private const KT_TO_KMH = 1.852;

    /** Au-delà, on considère que la station n'a pas émis récemment et on ne la retient pas */
    private const DISCOVERY_FRESHNESS_HOURS = 6;

    public function source(): string
    {
        return 'metar';
    }

    /**
     * Découverte par bbox : récupère toutes les obs des dernières
     * heures et extrait les stations uniques avec leurs métadonnées.
     */
    public function discoverStations(
        float $latMin,
        float $latMax,
        float $lngMin,
        float $lngMax
    ): array {
        // Convention bbox NOAA : minLat,minLon,maxLat,maxLon
        $bbox = sprintf('%s,%s,%s,%s', $latMin, $lngMin, $latMax, $lngMax);

        $resp = Http::timeout(self::TIMEOUT_S)
            ->get(self::BASE_URL, [
                'bbox'   => $bbox,
                'format' => 'json',
                'hours'  => self::DISCOVERY_FRESHNESS_HOURS,
            ]);

        if (! $resp->ok()) {
            Log::warning('METAR discovery HTTP error', [
                'status' => $resp->status(),
                'bbox'   => $bbox,
            ]);
            return [];
        }

        $obs = $resp->json();
        if (! is_array($obs) || empty($obs)) {
            return [];
        }

        // On déduplique par icaoId (plusieurs obs possibles par station)
        $byIcao = [];
        foreach ($obs as $o) {
            $icao = $o['icaoId'] ?? null;
            if (! $icao) {
                continue;
            }
            $lat = isset($o['lat']) ? (float) $o['lat'] : null;
            $lng = isset($o['lon']) ? (float) $o['lon'] : null;
            if ($lat === null || $lng === null) {
                continue;
            }
            // Coupure stricte sur la bbox (filet de sécurité au cas où NOAA serait laxiste)
            if ($lat < $latMin || $lat > $latMax) continue;
            if ($lng < $lngMin || $lng > $lngMax) continue;

            // Garde la première occurrence (la plus récente vu hours=N)
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

    /**
     * Polling : récupère la dernière obs de chaque balise METAR active
     * en base. Une seule requête HTTP avec ids=LFSF,LFSN,…
     */
    public function fetchLatestReadings(): array
    {
        $icaoIds = Balise::where('source', $this->source())
            ->where('active', true)
            ->pluck('external_id')
            ->all();

        if (empty($icaoIds)) {
            return [];
        }

        $resp = Http::timeout(self::TIMEOUT_S)
            ->get(self::BASE_URL, [
                'ids'    => implode(',', $icaoIds),
                'format' => 'json',
                'hours'  => 2,
            ]);

        if (! $resp->ok()) {
            Log::warning('METAR fetch HTTP error', [
                'status'    => $resp->status(),
                'ids_count' => count($icaoIds),
            ]);
            return [];
        }

        $obs = $resp->json();
        if (! is_array($obs)) {
            return [];
        }

        // Garde la plus récente par icaoId (l'API peut en renvoyer plusieurs)
        $latestByIcao = [];
        foreach ($obs as $o) {
            $icao = $o['icaoId'] ?? null;
            if (! $icao) continue;

            $obsTime = $this->parseObsTime($o);
            if (! $obsTime) continue;

            if (isset($latestByIcao[$icao]) && $latestByIcao[$icao]['_t']->gte($obsTime)) {
                continue;
            }

            $latestByIcao[$icao] = [
                '_t'             => $obsTime,
                'read_at'        => $obsTime,
                'wind_direction' => $this->parseWindDir($o['wdir'] ?? null),
                'wind_speed_avg' => $this->ktToKmh($o['wspd'] ?? null),
                'wind_speed_min' => $this->ktToKmh($o['wspd'] ?? null), // pas de min en METAR
                'wind_speed_max' => $this->ktToKmh($o['wgst'] ?? ($o['wspd'] ?? null)),
                'temperature'    => isset($o['temp']) ? (float) $o['temp'] : null,
                'humidity'       => $this->computeRh(
                    isset($o['temp']) ? (float) $o['temp'] : null,
                    isset($o['dewp']) ? (float) $o['dewp'] : null
                ),
            ];
        }

        // On retire la clé interne _t avant retour
        $result = [];
        foreach ($latestByIcao as $icao => $reading) {
            unset($reading['_t']);
            $result[(string) $icao] = $reading;
        }
        return $result;
    }

    /**
     * Extrait l'horodatage de l'obs. NOAA expose plusieurs champs ;
     * on prend obsTime (timestamp Unix) en priorité, sinon reportTime.
     */
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

    /**
     * Direction du vent : METAR utilise "VRB" pour vent variable
     * (calme tournant). On retourne null dans ce cas.
     */
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

    /**
     * Humidité relative depuis température et point de rosée
     * (formule de Magnus-Tetens, approximation classique).
     */
    private function computeRh(?float $temp, ?float $dewp): ?int
    {
        if ($temp === null || $dewp === null) return null;
        $a = 17.625; $b = 243.04;
        $alpha = exp(($a * $dewp) / ($b + $dewp)) / exp(($a * $temp) / ($b + $temp));
        $rh = (int) round($alpha * 100);
        return max(0, min(100, $rh));
    }
}
