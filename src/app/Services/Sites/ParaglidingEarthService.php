<?php

declare(strict_types=1);

namespace App\Services\Sites;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Service d'import des sites de vol depuis ParaglidingEarth.
 *
 * Doc API : http://www.paraglidingearth.com/api
 * Endpoint utilisé : /api/geojson/getCountrySites.php
 *
 * On ne crée pas de provider générique pour l'instant (≠ balises) :
 * c'est un import one-shot par pays, pas un polling périodique.
 *
 * Format de réponse (extrait) :
 *   {
 *     "type":"FeatureCollection",
 *     "features":[
 *       {
 *         "id":"2498",
 *         "geometry":{"type":"Point","coordinates":[lng, lat]},
 *         "properties":{
 *           "name":"...", "place":"paragliding takeoff",
 *           "takeoff_altitude":"1509",
 *           "landing_lat":"...", "landing_lng":"...",
 *           "N":"0","NE":"0","E":"0","SE":"0",
 *           "S":"0","SW":"0","W":"2","NW":"0",
 *           "paragliding":"1","hanggliding":"0",
 *           "pge_site_id":"2498","pge_link":"...",
 *           "takeoff_description":"...","comments":"...","weather":"...",
 *           "going_there":"...","flight_rules":"...",
 *           "landing":{ ...sous-objet en mode detailled... }
 *         }
 *       }
 *     ]
 *   }
 */
class ParaglidingEarthService
{
    private const BASE_URL  = 'http://www.paraglidingearth.com/api/geojson/getCountrySites.php';
    private const TIMEOUT_S = 60;

    /** Mapping des 8 secteurs cardinaux vers leur centre en degrés (FROM) */
    private const SECTOR_ANGLES = [
        'N'  => 0,
        'NE' => 45,
        'E'  => 90,
        'SE' => 135,
        'S'  => 180,
        'SW' => 225,
        'W'  => 270,
        'NW' => 315,
    ];

    /**
     * Récupère et parse la liste des sites de vol d'un pays.
     *
     * @param  string  $iso     Code ISO 2 lettres (ex: 'fr', 'ch')
     * @param  ?int    $limit   Limite optionnelle (utile pour les tests)
     * @return array<int, array> Liste de DTO normalisés prêts à insérer
     */
    public function fetchCountrySites(string $iso, ?int $limit = null): array
    {
        $params = [
            'iso'   => strtolower($iso),
            'style' => 'detailled',
        ];
        if ($limit !== null && $limit > 0) {
            $params['limit'] = $limit;
        }

        $resp = Http::timeout(self::TIMEOUT_S)->get(self::BASE_URL, $params);

        if (! $resp->ok()) {
            Log::warning('ParaglidingEarth fetch HTTP error', [
                'iso'    => $iso,
                'status' => $resp->status(),
            ]);
            return [];
        }

        $payload  = $resp->json();
        $features = $payload['features'] ?? [];
        if (! is_array($features)) {
            return [];
        }

        $sites = [];
        foreach ($features as $f) {
            $dto = $this->mapFeature($f);
            if ($dto !== null) {
                $sites[] = $dto;
            }
        }
        return $sites;
    }

    /**
     * Normalise une feature PGE en DTO. Filtre :
     *   - garde uniquement paragliding=1 et place commençant par
     *     'paragliding takeoff' (on n'importe pas les atterrissages
     *     comme sites distincts pour l'instant)
     *   - exige des coordonnées valides
     *
     * @return ?array{
     *     external_id:string,
     *     name:string,
     *     latitude:float,
     *     longitude:float,
     *     altitude_m:?int,
     *     landing_lat:?float,
     *     landing_lng:?float,
     *     wind_dir_min:?int,
     *     wind_dir_max:?int,
     *     description:?string,
     *     pge_link:?string,
     * }
     */
    private function mapFeature(array $feature): ?array
    {
        $props = $feature['properties'] ?? [];
        $coords = $feature['geometry']['coordinates'] ?? null;

        if (! is_array($coords) || count($coords) < 2) return null;
        $lng = (float) $coords[0];
        $lat = (float) $coords[1];
        if ($lat === 0.0 && $lng === 0.0) return null;

        // Filtres : parapente uniquement + décollage
        if ((string) ($props['paragliding'] ?? '0') !== '1') return null;
        $place = (string) ($props['place'] ?? '');
        if (! str_contains($place, 'takeoff')) return null;

        $extId = (string) ($feature['id'] ?? $props['pge_site_id'] ?? '');
        if ($extId === '') return null;

        $name = trim((string) ($props['name'] ?? ''));
        if ($name === '') return null;

        // Atterrissage : peut être dans properties.landing.* (mode detailled)
        // ou directement dans properties.landing_lat/lng
        $landingLat = $this->floatOrNull($props['landing']['landing_lat'] ?? $props['landing_lat'] ?? null);
        $landingLng = $this->floatOrNull($props['landing']['landing_lng'] ?? $props['landing_lng'] ?? null);

        // Orientations favorables
        [$dirMin, $dirMax] = $this->favorableDirRange($props);

        // Description : on concatène les champs textuels non vides
        $descParts = array_filter([
            trim((string) ($props['takeoff_description'] ?? '')),
            trim((string) ($props['going_there']         ?? '')),
            trim((string) ($props['weather']             ?? '')),
            trim((string) ($props['comments']            ?? '')),
        ], fn ($s) => $s !== '');

        return [
            'external_id'  => $extId,
            'name'         => $name,
            'latitude'     => $lat,
            'longitude'    => $lng,
            'altitude_m'   => $this->parseAltitude($props['takeoff_altitude'] ?? null),
            'landing_lat'  => $landingLat,
            'landing_lng'  => $landingLng,
            'wind_dir_min' => $dirMin,
            'wind_dir_max' => $dirMax,
            'description'  => empty($descParts) ? null : implode("\n\n", $descParts),
            'pge_link'     => trim((string) ($props['pge_link'] ?? '')) ?: null,
        ];
    }

    /**
     * Altitude PGE → ?int. PGE remplit "-1" pour "altitude inconnue"
     * et accepte aussi des valeurs aberrantes occasionnelles. On
     * borne strictement à [0, 9000] (Everest = 8849m, on est large).
     * Retourne null si hors plage ou non numérique.
     */
    private function parseAltitude(mixed $v): ?int
    {
        if ($v === null || $v === '' || ! is_numeric($v)) return null;
        $alt = (int) $v;
        if ($alt < 0 || $alt > 9000) return null;
        return $alt;
    }

    /**
     * Convertit la grille 8-secteurs PGE (clés N/NE/E/.../NW, valeurs
     * "0"/"1"/"2") en plage [dirMin, dirMax] continue (en degrés FROM).
     *
     * Stratégie :
     *   - Chaque secteur "favorable" (≥1) couvre ±22.5° autour de son
     *     centre (45° de large).
     *   - On choisit l'enveloppe la plus serrée qui contient tous les
     *     secteurs favorables, en testant les rotations possibles
     *     (gère le wrap autour du Nord, ex: NW + N + NE).
     *
     * @return array{0:?int, 1:?int}
     */
    private function favorableDirRange(array $props): array
    {
        $favorable = [];
        foreach (self::SECTOR_ANGLES as $key => $angle) {
            $val = (int) ($props[$key] ?? 0);
            if ($val >= 1) {
                $favorable[] = $angle;
            }
        }
        if (empty($favorable)) return [null, null];

        sort($favorable);
        $n = count($favorable);

        if ($n === 1) {
            $a = $favorable[0];
            return [(int) (($a - 22 + 360) % 360), (int) (($a + 22) % 360)];
        }
        // Cas tous secteurs favorables
        if ($n === 8) return [0, 360];

        // Trouve la plus petite enveloppe contenant tous les secteurs
        // favorables (en testant le wrap).
        $bestSpan  = 360;
        $bestStart = $favorable[0];
        $bestEnd   = $favorable[$n - 1];

        for ($i = 0; $i < $n; $i++) {
            $start = $favorable[$i];
            $end   = $favorable[($i + $n - 1) % $n];
            // Span avec gestion du wrap
            $span = ($end - $start + 360) % 360;
            if ($span === 0) $span = 360;
            if ($span < $bestSpan) {
                $bestSpan  = $span;
                $bestStart = $start;
                $bestEnd   = $end;
            }
        }

        $min = (int) (($bestStart - 22 + 360) % 360);
        $max = (int) (($bestEnd + 22) % 360);
        return [$min, $max];
    }

    private function intOrNull(mixed $v): ?int
    {
        if ($v === null || $v === '' || ! is_numeric($v)) return null;
        return (int) $v;
    }

    private function floatOrNull(mixed $v): ?float
    {
        if ($v === null || $v === '' || ! is_numeric($v)) return null;
        $f = (float) $v;
        if ($f === 0.0) return null; // PGE remplit "0" pour "non disponible"
        return $f;
    }
}
