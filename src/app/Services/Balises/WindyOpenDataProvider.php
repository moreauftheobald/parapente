<?php

declare(strict_types=1);

namespace App\Services\Balises;

use App\Models\Balise;
use App\Services\Settings;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fournisseur de balises Windy.com — endpoint Open Data.
 *
 * API : Windy Stations API v2 (mise en service janvier 2026).
 * Host : https://stations.windy.com/
 * Doc  : https://api.windy.com (section Stations API)
 *
 * Endpoints utilisés :
 *  - GET /api/v2/opendata/station            (catalogue, paginé)
 *  - GET /api/v2/opendata/station/{id}/observation
 *
 * Authentification :
 *  - Clé API stockée dans `settings.windy.api_key` (saisie via
 *    /admin/settings, groupe « Sources balises »).
 *  - Transmise en en-tête `windy-api-key` (compatible avec
 *    `?key=...` mais l'en-tête évite de fuiter dans les logs Nginx).
 *
 * ── Format catalogue (vérifié par sonde, 2026-05-15) ─────────────
 *  Racine : { data: [...100 stations...], pagination: {...} }
 *  Station :
 *    { id, name, lat, lon, agl_temp, agl_wind, elev_m,
 *      operator_text, operator_url, is_online,
 *      last_observation_time, station_type, share_option }
 *
 *  IMPORTANT : le filtre bbox côté serveur (lat_min/lat_max/lng_min/
 *  lng_max) est IGNORÉ — l'API renvoie toujours la même page complète.
 *  Le filtre client en bas reste donc autoritaire.
 *
 *  Pagination : la première page contient 100 stations. La structure
 *  exacte de l'objet `pagination` n'est pas figée dans la doc
 *  publique — on essaye plusieurs conventions (offset/limit, page,
 *  next cursor) et on s'arrête dès qu'on retombe sur des données
 *  déjà vues ou un payload vide.
 *
 * ── Format observation (partiellement vérifié) ────────────────────
 *  Racine : { header: { …mêmes champs que station… },
 *             data:   { wind: [N valeurs en m/s], … } }
 *  Les séries (`wind`, `gust`, `wind_dir`, `temp`, `time`…) sont
 *  alignées sur un même axe temporel — on prend la valeur la plus
 *  récente (dernier élément). Noms exacts de séries autres que `wind`
 *  à confirmer par probe2.
 */
class WindyOpenDataProvider implements BaliseProviderInterface
{
    private const BASE_URL  = 'https://stations.windy.com';
    private const TIMEOUT_S = 30;

    /** Garde-fou pagination : 200 pages × 100 stations = 20 000 max
     *  (le catalogue mondial fait ~14 300 stations à la mise en service
     *  de l'API, avec ~77 % offline donc ~3 300 utiles) */
    private const MAX_PAGES = 200;

    /** Fraîcheur minimale d'une station pour la retenir à la découverte */
    private const DISCOVERY_FRESHNESS_HOURS = 24;

    /**
     * Heuristique station_type → reliability_class.
     *
     * Le champ `station_type` est du TEXTE LIBRE saisi par les
     * propriétaires de stations (orthographe variable, casse non
     * normalisée). On normalise (lower + trim) puis on cherche par
     * SUBSTRING contre une whitelist de marques/modèles considérés
     * pro pour l'aérologie parapente.
     *
     * Par défaut : `amateur`. Ouvrir Windy opendata = ouvrir
     * majoritairement des Davis Vantage et des Netatmo de jardin —
     * la voting logic doit pouvoir les exclure d'un revers de filtre
     * sur `reliability_class`.
     */
    private const STATION_TYPE_PRO_NEEDLES = [
        'holfuy',     // anémo parapente, calibré, déploiement déco
        'pioupiou',   // au cas où Windy re-publierait des pioupious
        'metar',      // ICAO aéroport
        'wmo',        // station OMM officielle
        'fmi',        // Finland Meteorological Institute
        'vaisala',    // matériel pro météo / aviation
        'mtsat',
    ];

    public function __construct(private readonly Settings $settings) {}

    public function source(): string
    {
        return 'windy';
    }

    /**
     * GET /api/v2/opendata/station — paginé, filtre bbox côté client.
     */
    public function discoverStations(
        float $latMin,
        float $latMax,
        float $lngMin,
        float $lngMax
    ): array {
        $key = $this->apiKey();
        if ($key === null) {
            Log::warning('WindyOpenDataProvider::discoverStations skipped — no API key configured');
            return [];
        }

        $client = $this->client($key);
        $out = [];
        $cutoff = now()->subHours(self::DISCOVERY_FRESHNESS_HOURS);
        $seenIds = [];

        $params = ['page' => 0, 'pageSize' => 100];
        $page = 0;

        while ($page < self::MAX_PAGES) {
            $page++;
            $resp = $client->get('/api/v2/opendata/station', $params);

            if (! $resp->ok()) {
                Log::warning('WindyOpenDataProvider catalog HTTP error', [
                    'status' => $resp->status(),
                    'page'   => $page,
                    'body'   => substr($resp->body(), 0, 300),
                ]);
                break;
            }

            $payload = $resp->json();
            if (! is_array($payload)) break;

            $batch = is_array($payload['data'] ?? null) ? $payload['data'] : [];
            if (empty($batch)) break;

            $newOnThisPage = 0;
            foreach ($batch as $s) {
                $norm = $this->normalizeStation($s, $cutoff);
                if ($norm === null) continue;

                // dédoublonnage entre pages (si pagination tourne en boucle)
                if (isset($seenIds[$norm['external_id']])) continue;
                $seenIds[$norm['external_id']] = true;
                $newOnThisPage++;

                // Filtre bbox client
                if ($norm['latitude']  < $latMin || $norm['latitude']  > $latMax) continue;
                if ($norm['longitude'] < $lngMin || $norm['longitude'] > $lngMax) continue;

                $out[] = $norm;
            }

            // Si aucune nouvelle station sur cette page → l'API ne pagine
            // pas comme on croit, on arrête.
            if ($newOnThisPage === 0) break;

            $nextParams = $this->nextPageParams($payload['pagination'] ?? null, $params, $batch);
            if ($nextParams === null) break;
            $params = $nextParams;
        }

        return $out;
    }

    /**
     * GET /api/v2/opendata/station/{id}/observation pour chaque
     * balise Windy active connue en base.
     */
    public function fetchLatestReadings(): array
    {
        $key = $this->apiKey();
        if ($key === null) {
            Log::warning('WindyOpenDataProvider::fetchLatestReadings skipped — no API key configured');
            return [];
        }

        $balises = Balise::query()
            ->where('source', $this->source())
            ->where('active', true)
            ->get(['id', 'external_id']);

        if ($balises->isEmpty()) return [];

        $client = $this->client($key);
        $out = [];

        foreach ($balises as $b) {
            $extId = (string) $b->external_id;
            if ($extId === '') continue;

            $reading = $this->fetchOneObservation($client, $extId);
            if ($reading !== null) {
                $out[$extId] = $reading;
            }
        }

        return $out;
    }

    // ── Internals : auth + http ──────────────────────────────────────────

    private function apiKey(): ?string
    {
        $k = trim((string) $this->settings->get('windy.api_key', ''));
        return $k === '' ? null : $k;
    }

    private function client(string $key): \Illuminate\Http\Client\PendingRequest
    {
        return Http::baseUrl(self::BASE_URL)
            ->timeout(self::TIMEOUT_S)
            ->withHeaders(['windy-api-key' => $key])
            ->acceptJson();
    }

    // ── Internals : pagination ───────────────────────────────────────────

    /**
     * Convention de pagination Windy (vérifiée par sonde 2026-05-15) :
     *   { page: 0,
     *     pageSize: 100,
     *     totalPages: 142,
     *     offset: 0,
     *     totalItems: 14297 }
     *
     * On pilote par `page` 0-indexé contre `totalPages`. Pas de
     * cursor à transporter — la page suivante = page courante + 1.
     *
     * @param mixed                $pagination  Bloc `pagination` brut
     * @param array<string, mixed> $currentParams Params utilisés pour la page courante
     * @param array<int, mixed>    $batch       Données reçues pour la page courante
     * @return array<string, mixed>|null  null = fin de pagination
     */
    private function nextPageParams(mixed $pagination, array $currentParams, array $batch): ?array
    {
        if (! is_array($pagination)) return null;
        if (count($batch) === 0)     return null;

        $page       = isset($pagination['page'])       ? (int) $pagination['page']       : 0;
        $totalPages = isset($pagination['totalPages']) ? (int) $pagination['totalPages'] : 0;

        if ($totalPages <= 0 || $page + 1 >= $totalPages) return null;

        $pageSize = (int) ($pagination['pageSize'] ?? 100);

        return [
            'page'     => $page + 1,
            'pageSize' => $pageSize,
        ];
    }

    // ── Internals : normalisation ────────────────────────────────────────

    /**
     * Normalise une station Windy + filtre on/off, fraîcheur et share.
     *
     * @return array{
     *   external_id:string, name:string,
     *   latitude:float, longitude:float,
     *   altitude_m:?int, height_agl_m:?int,
     *   reliability_class:'pro'|'amateur',
     * }|null
     */
    private function normalizeStation(array $s, Carbon $freshnessCutoff): ?array
    {
        $id  = $this->stringOrNull($s, ['id', 'station_id', 'stationId']);
        $lat = $this->floatOrNull($s,  ['lat', 'latitude']);
        $lng = $this->floatOrNull($s,  ['lon', 'lng', 'longitude']);

        if ($id === null || $lat === null || $lng === null) return null;

        // Filtres serveur (`is_online`, `share_option`, fraîcheur)
        if (array_key_exists('is_online', $s) && $s['is_online'] === false) return null;
        if (isset($s['share_option']) && $s['share_option'] !== 'public') return null;

        $lastObs = $s['last_observation_time'] ?? null;
        if ($lastObs !== null) {
            try {
                if (Carbon::parse((string) $lastObs)->lt($freshnessCutoff)) return null;
            } catch (\Throwable) { /* on garde la station si timestamp imparsable */ }
        }

        $name = $this->stringOrNull($s, ['name']) ?? "Windy {$id}";
        $alt  = $this->intOrNull($s,    ['elev_m', 'elevation', 'altitude_m']);
        $agl  = $this->intOrNull($s,    ['agl_wind', 'wind_agl', 'agl']);

        return [
            'external_id'       => $id,
            'name'              => $name,
            'latitude'          => $lat,
            'longitude'         => $lng,
            'altitude_m'        => $alt,
            'height_agl_m'      => $agl,
            'reliability_class' => $this->classifyReliability((string) ($s['station_type'] ?? '')),
        ];
    }

    /**
     * Mapping `station_type` (texte libre) → reliability_class.
     * Substring match insensible à la casse contre une whitelist
     * de marques/modèles pro. Tout le reste → `amateur`.
     */
    private function classifyReliability(string $stationType): string
    {
        $needle = strtolower(trim($stationType));
        if ($needle === '') return 'amateur';

        foreach (self::STATION_TYPE_PRO_NEEDLES as $pro) {
            if (str_contains($needle, $pro)) return 'pro';
        }
        return 'amateur';
    }

    /**
     * Récupère la dernière mesure d'une station Windy en prenant
     * l'index le plus récent de chaque série de `data`.
     *
     * Format vérifié par sonde 2026-05-15 :
     *   { header: { …mêmes champs que la station… },
     *     data: {
     *       wind:      [floats m/s, len N],
     *       wind_dir:  [floats degrés, convention FROM],
     *       wind_gust: [floats m/s],
     *       pressure:  [int Pa],
     *       ts:        [int unix MILLISECONDES, len N]
     *     } }
     *
     * `temp` et `humidity` sont absents sur certaines stations
     * (typiquement Netatmo sans module T°). Traités comme nullable.
     * `pressure` est récupéré mais ignoré faute de colonne en base.
     *
     * @return array{
     *   read_at: Carbon,
     *   wind_direction: ?int,
     *   wind_speed_avg: ?float,
     *   wind_speed_min: ?float,
     *   wind_speed_max: ?float,
     *   temperature: ?float,
     *   humidity: ?int,
     * }|null
     */
    private function fetchOneObservation(\Illuminate\Http\Client\PendingRequest $client, string $externalId): ?array
    {
        $resp = $client->get('/api/v2/opendata/station/' . urlencode($externalId) . '/observation');

        if (! $resp->ok()) {
            Log::info('WindyOpenDataProvider observation HTTP non-OK', [
                'station' => $externalId,
                'status'  => $resp->status(),
            ]);
            return null;
        }

        $payload = $resp->json();
        if (! is_array($payload)) return null;

        $data = is_array($payload['data'] ?? null) ? $payload['data'] : null;
        if ($data === null) return null;

        // Timestamp obligatoire : data.ts (unix ms). Fallback header.
        $idx    = null;
        $readAt = null;
        if (isset($data['ts']) && is_array($data['ts']) && ! empty($data['ts'])) {
            $idx = $this->lastNumericIndex($data['ts']);
            if ($idx !== null) {
                try {
                    // Windy publie en MILLISECONDES — diviser par 1000.
                    $readAt = Carbon::createFromTimestampMs((int) $data['ts'][$idx]);
                } catch (\Throwable) {
                    $readAt = null;
                }
            }
        }
        if ($readAt === null) {
            $readAt = $this->headerTimestamp($payload['header'] ?? null);
            if ($readAt === null) return null;
        }

        // Si on connaît l'index temporel, on aligne toutes les séries
        // dessus ; sinon, on prend le dernier numérique de chaque série
        // (résilient à des séries de longueur incohérente).
        $pick = fn (string $key): ?float => $idx !== null
            ? $this->valueAtIndex($data, $key, $idx)
            : $this->lastNumeric($data, [$key]);

        $wind = $pick('wind');
        $gust = $pick('wind_gust');
        $wdir = $pick('wind_dir');
        $temp = $pick('temp');
        $hum  = $pick('humidity');

        return [
            'read_at'        => $readAt,
            'wind_direction' => $wdir !== null ? ((int) round($wdir) + 360) % 360 : null,
            'wind_speed_avg' => $wind !== null ? $this->msToKmh($wind) : null,
            // Pas de série wind_min côté Windy → null
            'wind_speed_min' => null,
            'wind_speed_max' => $gust !== null ? $this->msToKmh($gust) : null,
            'temperature'    => $temp,
            'humidity'       => $hum !== null ? (int) round($hum) : null,
        ];
    }

    /**
     * Index du dernier élément numérique non-null d'une série.
     *
     * @param array<int, mixed> $series
     */
    private function lastNumericIndex(array $series): ?int
    {
        for ($i = count($series) - 1; $i >= 0; $i--) {
            if ($series[$i] !== null && is_numeric($series[$i])) {
                return $i;
            }
        }
        return null;
    }

    /**
     * Valeur d'une série à un index donné (si présente et numérique).
     */
    private function valueAtIndex(array $data, string $key, int $idx): ?float
    {
        if (! isset($data[$key]) || ! is_array($data[$key])) return null;
        $v = $data[$key][$idx] ?? null;
        return ($v !== null && is_numeric($v)) ? (float) $v : null;
    }

    /**
     * Dernier élément numérique d'une série, parmi plusieurs alias.
     * Utilisé en fallback quand on n'a pas d'axe `ts` exploitable.
     *
     * @param list<string> $keys
     */
    private function lastNumeric(array $data, array $keys): ?float
    {
        foreach ($keys as $k) {
            if (! isset($data[$k]) || ! is_array($data[$k])) continue;
            $idx = $this->lastNumericIndex($data[$k]);
            if ($idx !== null) return (float) $data[$k][$idx];
        }
        return null;
    }

    /**
     * Fallback timestamp : header.last_observation_time (ISO 8601).
     */
    private function headerTimestamp(mixed $header): ?Carbon
    {
        if (! is_array($header)) return null;
        $raw = $header['last_observation_time'] ?? null;
        if ($raw === null) return null;
        try {
            return Carbon::parse((string) $raw);
        } catch (\Throwable) {
            return null;
        }
    }

    private function msToKmh(float $ms): float
    {
        return round($ms * 3.6, 1);
    }

    /** @param list<string> $keys */
    private function floatOrNull(array $a, array $keys): ?float
    {
        foreach ($keys as $k) {
            if (isset($a[$k]) && is_numeric($a[$k])) return (float) $a[$k];
        }
        return null;
    }

    /** @param list<string> $keys */
    private function intOrNull(array $a, array $keys): ?int
    {
        foreach ($keys as $k) {
            if (isset($a[$k]) && is_numeric($a[$k])) return (int) round((float) $a[$k]);
        }
        return null;
    }

    /** @param list<string> $keys */
    private function stringOrNull(array $a, array $keys): ?string
    {
        foreach ($keys as $k) {
            if (isset($a[$k]) && (is_string($a[$k]) || is_numeric($a[$k]))) {
                $v = (string) $a[$k];
                if ($v !== '') return $v;
            }
        }
        return null;
    }
}
