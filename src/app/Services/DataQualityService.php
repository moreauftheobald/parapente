<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Balise;
use App\Models\IgnoredDuplicate;
use App\Models\Site;
use Illuminate\Support\Collection;

/**
 * Détection des doublons (sites de vol et balises) sur la base des
 * coordonnées géographiques, alimentée par les seuils éditables dans
 * /admin/settings (groupe `quality`).
 *
 * Algorithme : O(n²) avec early-exit sur l'écart latitudinal pour
 * éviter le calcul Haversine sur des paires manifestement trop
 * éloignées. Suffisant jusqu'à plusieurs dizaines de milliers
 * d'entités ; au-delà il faudra introduire un bucket spatial.
 */
class DataQualityService
{
    /** Rayon terrestre moyen (km) */
    private const EARTH_RADIUS_KM = 6371.0;

    public function __construct(private readonly Settings $settings) {}

    // ── Sites ────────────────────────────────────────────────────────────

    /**
     * Détecte les paires de sites potentiellement doublonnés.
     *
     * Une paire est retenue si :
     *  - distance Haversine ≤ `quality.site_dup_distance_m` (m)
     *  - écart d'altitude ≤ `quality.site_dup_altitude_m` (m)
     *  - chevauchement d'orientation ≥ `quality.site_dup_orientation_overlap_pct` (%)
     *    [critère ignoré si l'un des deux sites n'a pas de site_conditions]
     *
     * @return array<int, array{
     *   a: Site, b: Site,
     *   distance_m: int,
     *   altitude_diff_m: ?int,
     *   orientation_overlap_pct: ?int,
     *   ignored: bool,
     *   ignored_id: ?int,
     * }>
     */
    public function detectSiteDuplicates(): array
    {
        $maxDistM        = (int) $this->settings->get('quality.site_dup_distance_m', 200);
        $maxAltitudeDiff = (int) $this->settings->get('quality.site_dup_altitude_m', 30);
        $minOverlapPct   = (int) $this->settings->get('quality.site_dup_orientation_overlap_pct', 60);

        // Approche grossière : on charge tous les sites + leurs conditions.
        // O(n²) ensuite avec early-exit sur l'écart latitudinal.
        $sites = Site::query()
            ->with('conditions:site_id,wind_dir_min,wind_dir_max')
            ->orderBy('latitude')
            ->get(['id', 'name', 'slug', 'region', 'latitude', 'longitude', 'altitude_m', 'active']);

        $maxLatDelta = ($maxDistM / 111000.0) * 1.05;
        $ignored     = $this->ignoredPairsIndex(IgnoredDuplicate::TYPE_SITE);

        $pairs = [];
        $n = $sites->count();

        for ($i = 0; $i < $n; $i++) {
            $a = $sites[$i];
            for ($j = $i + 1; $j < $n; $j++) {
                $b = $sites[$j];

                // sites triés par latitude → on peut couper la boucle interne
                $latDiff = (float) $b->latitude - (float) $a->latitude;
                if ($latDiff > $maxLatDelta) {
                    break;
                }

                $distM = (int) round($this->distanceKm(
                    (float) $a->latitude, (float) $a->longitude,
                    (float) $b->latitude, (float) $b->longitude,
                ) * 1000);
                if ($distM > $maxDistM) {
                    continue;
                }

                // Critère altitude (uniquement si les deux la connaissent)
                $altDiff = null;
                if ($a->altitude_m !== null && $b->altitude_m !== null) {
                    $altDiff = abs((int) $a->altitude_m - (int) $b->altitude_m);
                    if ($altDiff > $maxAltitudeDiff) {
                        continue;
                    }
                }

                // Critère orientation (uniquement si les deux ont des conditions)
                $overlapPct = null;
                $condA = $a->conditions;
                $condB = $b->conditions;
                if ($condA && $condB
                    && $condA->wind_dir_min !== null && $condA->wind_dir_max !== null
                    && $condB->wind_dir_min !== null && $condB->wind_dir_max !== null
                ) {
                    $overlapPct = $this->orientationOverlapPct(
                        (int) $condA->wind_dir_min, (int) $condA->wind_dir_max,
                        (int) $condB->wind_dir_min, (int) $condB->wind_dir_max,
                    );
                    if ($overlapPct < $minOverlapPct) {
                        continue;
                    }
                }

                [$type, $aId, $bId] = IgnoredDuplicate::pairKey(IgnoredDuplicate::TYPE_SITE, (int) $a->id, (int) $b->id);
                $ignoredEntry = $ignored["{$aId}_{$bId}"] ?? null;

                $pairs[] = [
                    'a'                       => $sites->firstWhere('id', $aId),
                    'b'                       => $sites->firstWhere('id', $bId),
                    'distance_m'              => $distM,
                    'altitude_diff_m'         => $altDiff,
                    'orientation_overlap_pct' => $overlapPct,
                    'ignored'                 => (bool) $ignoredEntry,
                    'ignored_id'              => $ignoredEntry,
                ];
            }
        }

        // Tri : non-ignorés d'abord, puis par distance croissante
        usort($pairs, function ($x, $y) {
            if ($x['ignored'] !== $y['ignored']) {
                return $x['ignored'] ? 1 : -1;
            }
            return $x['distance_m'] <=> $y['distance_m'];
        });

        return $pairs;
    }

    // ── Balises ──────────────────────────────────────────────────────────

    /**
     * Détecte les paires de balises potentiellement doublonnées.
     * Tous réseaux confondus : `same_network` indique si les deux balises
     * sont du même provider (vraie suspicion) ou d'un réseau différent
     * (souvent légitime : pioupiou + metar sur le même aéroport).
     *
     * @return array<int, array{
     *   a: Balise, b: Balise,
     *   distance_m: int,
     *   same_network: bool,
     *   ignored: bool,
     *   ignored_id: ?int,
     * }>
     */
    public function detectBaliseDuplicates(): array
    {
        $maxDistM = (int) $this->settings->get('quality.balise_dup_distance_m', 300);

        $balises = Balise::query()
            ->orderBy('latitude')
            ->get(['id', 'name', 'source', 'external_id', 'latitude', 'longitude', 'altitude_m', 'active', 'site_id']);

        $maxLatDelta = ($maxDistM / 111000.0) * 1.05;
        $ignored     = $this->ignoredPairsIndex(IgnoredDuplicate::TYPE_BALISE);

        $pairs = [];
        $n = $balises->count();

        for ($i = 0; $i < $n; $i++) {
            $a = $balises[$i];
            for ($j = $i + 1; $j < $n; $j++) {
                $b = $balises[$j];

                $latDiff = (float) $b->latitude - (float) $a->latitude;
                if ($latDiff > $maxLatDelta) {
                    break;
                }

                $distM = (int) round($this->distanceKm(
                    (float) $a->latitude, (float) $a->longitude,
                    (float) $b->latitude, (float) $b->longitude,
                ) * 1000);
                if ($distM > $maxDistM) {
                    continue;
                }

                [$type, $aId, $bId] = IgnoredDuplicate::pairKey(IgnoredDuplicate::TYPE_BALISE, (int) $a->id, (int) $b->id);
                $ignoredEntry = $ignored["{$aId}_{$bId}"] ?? null;

                $pairs[] = [
                    'a'            => $balises->firstWhere('id', $aId),
                    'b'            => $balises->firstWhere('id', $bId),
                    'distance_m'   => $distM,
                    'same_network' => $a->source === $b->source,
                    'ignored'      => (bool) $ignoredEntry,
                    'ignored_id'   => $ignoredEntry,
                ];
            }
        }

        // Tri : non-ignorés d'abord, puis même-réseau d'abord, puis distance
        usort($pairs, function ($x, $y) {
            if ($x['ignored'] !== $y['ignored']) {
                return $x['ignored'] ? 1 : -1;
            }
            if ($x['same_network'] !== $y['same_network']) {
                return $x['same_network'] ? -1 : 1;
            }
            return $x['distance_m'] <=> $y['distance_m'];
        });

        return $pairs;
    }

    // ── Mutations ────────────────────────────────────────────────────────

    public function ignorePair(string $type, int $idA, int $idB, ?int $userId): IgnoredDuplicate
    {
        [$type, $a, $b] = IgnoredDuplicate::pairKey($type, $idA, $idB);

        return IgnoredDuplicate::firstOrCreate(
            ['entity_type' => $type, 'entity_a_id' => $a, 'entity_b_id' => $b],
            ['ignored_by'  => $userId],
        );
    }

    public function unignorePair(IgnoredDuplicate $ignored): void
    {
        $ignored->delete();
    }

    // ── Internals ────────────────────────────────────────────────────────

    /**
     * Index "aId_bId" => ignored_id pour un type d'entité.
     */
    private function ignoredPairsIndex(string $type): array
    {
        return IgnoredDuplicate::query()
            ->where('entity_type', $type)
            ->get(['id', 'entity_a_id', 'entity_b_id'])
            ->mapWithKeys(fn ($r) => ["{$r->entity_a_id}_{$r->entity_b_id}" => (int) $r->id])
            ->all();
    }

    /**
     * Haversine en km.
     */
    private function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return self::EARTH_RADIUS_KM * 2 * asin(min(1.0, sqrt($a)));
    }

    /**
     * Pourcentage de chevauchement entre deux arcs angulaires
     * définis sur [0, 360) (convention météo FROM).
     *
     * Méthode : on discrétise chaque arc en 360 booléens (1 par
     * degré), on compte l'intersection, on rapporte au plus petit
     * des deux arcs. Si l'un des arcs est de longueur 0 (min==max),
     * on retourne 100 si l'autre arc contient ce degré, 0 sinon.
     */
    private function orientationOverlapPct(int $minA, int $maxA, int $minB, int $maxB): int
    {
        $arcA = $this->arcDegrees($minA, $maxA);
        $arcB = $this->arcDegrees($minB, $maxB);

        $countA = count($arcA);
        $countB = count($arcB);
        if ($countA === 0 || $countB === 0) {
            return 0;
        }

        $intersect = count(array_intersect_key(array_flip($arcA), array_flip($arcB)));
        $min = min($countA, $countB);

        return (int) round($intersect / $min * 100);
    }

    /**
     * Liste des degrés (0-359) appartenant à un arc [min, max] sur le cercle.
     * Si min > max, l'arc traverse le Nord (ex. 315 → 45 = arc de 91°).
     *
     * @return list<int>
     */
    private function arcDegrees(int $min, int $max): array
    {
        $min = (($min % 360) + 360) % 360;
        $max = (($max % 360) + 360) % 360;

        $out = [];
        $i = $min;
        $guard = 0;
        while ($guard++ < 361) {
            $out[] = $i;
            if ($i === $max) break;
            $i = ($i + 1) % 360;
        }
        return $out;
    }
}
