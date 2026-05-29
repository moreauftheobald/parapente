<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserHiddenSite;
use App\Services\Map\MapBundleBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sur-couche utilisateur du map bundle : sites masqués par l'utilisateur
 * connecté + agrégat journalier global recalculé en les excluant.
 *
 * Le bundle global servi par `/api/map-bundle` est partagé (les sites
 * masqués y figurent toujours). Cet endpoint renvoie :
 *  - `hidden_site_ids` : la liste à masquer côté client (filtre marqueurs)
 *  - `days_summary` : l'agrégat « X h de vol possible » / meilleur statut
 *    par jour, RECALCULÉ sans les sites masqués (cohérent avec ce que
 *    l'utilisateur voit réellement). `null` si aucun site masqué — le
 *    client garde alors l'agrégat global.
 *
 * Le recalcul est purement en mémoire à partir du bundle déjà en cache
 * (pas de requête DB supplémentaire). Cf. FF_site_blacklist.md.
 */
class MeHiddenSitesController extends Controller
{
    public function __construct(
        private readonly MapBundleBuilder $builder,
    ) {}

    public function overrides(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['hidden_site_ids' => [], 'days_summary' => null]);
        }

        $hiddenIds = UserHiddenSite::forUser($user->id)
            ->pluck('site_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        // Pas de site masqué : rien à recalculer, le client garde
        // l'agrégat global du bundle.
        if ($hiddenIds === []) {
            return response()->json([
                'user_id'         => $user->id,
                'hidden_site_ids' => [],
                'days_summary'    => null,
            ]);
        }

        $bundle      = $this->builder->getOrBuild();
        $daysSummary = MapBundleBuilder::summarizeDays($bundle['sites'] ?? [], $hiddenIds);

        return response()->json([
            'user_id'         => $user->id,
            'hidden_site_ids' => $hiddenIds,
            'days_summary'    => $daysSummary,
        ]);
    }
}
