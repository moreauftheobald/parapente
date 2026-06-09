<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Map\MapBundleBuilder;
use App\Services\Map\ScoringFreshness;
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
        private readonly ScoringFreshness $freshness,
    ) {}

    public function show(): JsonResponse
    {
        $bundle = $this->builder->getOrBuild();

        // Statut de fraîcheur du scoring — ajouté **en direct** (pas mis en
        // cache dans le bundle, c'est sensible au temps) pour le bandeau
        // « prévisions non rafraîchies » côté carte.
        $bundle['scoring_status'] = $this->freshness->status();

        // `green_hours_set` n'est utile qu'au recalcul serveur de
        // l'agrégat journalier par utilisateur (MeHiddenSitesController) ;
        // on le retire du payload client pour ne pas l'alourdir. On copie
        // les sites pour ne pas muter l'entrée de cache partagée.
        if (isset($bundle['sites']) && is_array($bundle['sites'])) {
            $bundle['sites'] = array_map(static function (array $site): array {
                unset($site['green_hours_set']);
                return $site;
            }, $bundle['sites']);
        }

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
