<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Map\MapBundleBuilder;
use Illuminate\Http\JsonResponse;

/**
 * Endpoint unique pour le boot de la vue carte : retourne le bundle
 * pré-calculé (markers, statuts journaliers, fenêtres solaires,
 * agrégat global par jour).
 *
 * Le bundle est **partagé entre tous les utilisateurs**. Les
 * sur-couches utilisateur (badge user_scoring, override de statut
 * via scoring perso) sont servies par /api/me/scoring-overrides et
 * fusionnées côté client.
 *
 * Cf. FF_map_bundle_cache.md.
 */
class MapBundleController extends Controller
{
    public function __construct(
        private readonly MapBundleBuilder $builder,
    ) {}

    public function show(): JsonResponse
    {
        $bundle = $this->builder->getOrBuild();

        // Cache-Control public : le bundle est identique pour tout le
        // monde, les intermédiaires (CDN, proxies) peuvent le cacher.
        // max-age court (60s) car on veut que la régénération horaire
        // se propage rapidement. ETag basé sur generated_at pour
        // 304 Not Modified si le client revient avec le même bundle.
        $etag = '"' . md5((string) ($bundle['generated_at'] ?? '')) . '"';

        return response()
            ->json($bundle)
            ->header('Cache-Control', 'public, max-age=60, must-revalidate')
            ->header('ETag', $etag);
    }
}
