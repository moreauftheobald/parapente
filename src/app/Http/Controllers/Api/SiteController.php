<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\UserSiteCondition;
use App\Services\Map\SiteDetailCache;
use App\Services\Weather\SiteChartPayloadBuilder;
use App\Services\Weather\SiteMultimodelPayloadBuilder;
use App\Services\Weather\SiteScoresPayloadBuilder;
use App\Services\Weather\UserScoringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SiteController extends Controller
{
    public function __construct(
        private UserScoringService $userScoring,
        private SiteDetailCache $detailCache,
        private SiteScoresPayloadBuilder $scoresBuilder,
        private SiteChartPayloadBuilder $chartBuilder,
        private SiteMultimodelPayloadBuilder $multimodelBuilder,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $userId       = $request->user()?->id;
        $userScorings = $userId !== null
            ? UserSiteCondition::forUser($userId)
                ->get(['site_id', 'is_active'])
                ->keyBy('site_id')
            : collect();

        $sites = Site::active()->with('conditions')->get()->map(function (Site $site) use ($userScorings) {
            $usc = $userScorings->get($site->id);

            return [
                'id'           => $site->id,
                'name'         => $site->name,
                'lat'          => (float) $site->latitude,
                'lng'          => (float) $site->longitude,
                'altitude'     => $site->altitude_m,
                'level'        => $site->level,
                'region'       => $site->region,
                // Enrichissement géocodage (nullable) — utilisé par les
                // filtres de la page « Sites masqués » (pays / région / dépt).
                'country'      => $site->country,
                'admin_region' => $site->admin_region,
                'department'   => $site->department,
                // Plage favorable d'orientation du décollage (depuis site_conditions)
                // utilisée par siteIconUrl() pour calculer le bitmask SpotAir.
                'wind_dir_min' => $site->conditions?->wind_dir_min,
                'wind_dir_max' => $site->conditions?->wind_dir_max,
                // Présence et état du scoring perso de l'utilisateur sur ce site.
                // null = pas de scoring perso enregistré ; 'active' / 'inactive'
                // = badge à afficher sur le marqueur (cf. FF_personnal_scoring).
                'user_scoring' => $usc === null
                    ? null
                    : ((bool) $usc->is_active ? 'active' : 'inactive'),
            ];
        });
        return response()->json($sites);
    }

    /**
     * Scores horaires + sun windows + day quality d'un site.
     *
     * Le payload "global" (sans rescore perso) est mis en cache Redis
     * via SiteDetailCache (TTL 90 min, invalidé au flip du buffer de
     * scoring par WatchScoringTableJob). Si
     * l'utilisateur a un scoring perso ACTIF sur ce site, on lit le
     * cache puis on applique le rescore par-dessus (status, couleurs,
     * day_quality). Sinon on renvoie le cache tel quel — cas dominant.
     */
    public function scores(int $id, Request $request): JsonResponse
    {
        $site = Site::active()->findOrFail($id);

        $payload = $this->detailCache->rememberScores(
            $site->id,
            fn () => $this->scoresBuilder->buildGlobal($site),
        );

        $userId       = $request->user()?->id;
        $userRescored = $userId !== null
            ? $this->userScoring->rescoreForUserSite($userId, $site->id)
            : null;

        if ($userRescored !== null) {
            $payload = $this->scoresBuilder->applyUserRescore($payload, $userRescored);
        }

        return response()->json($payload);
    }

    /**
     * Données horaires agrégées pour le popup graphique :
     * vent min/moy/max, couverture nuageuse haute/moy/basse, direction.
     *
     * Mis en cache transparent (TTL 90 min, invalidé au flip du buffer
     * de scoring par WatchScoringTableJob).
     */
    public function chart(int $id): JsonResponse
    {
        $site = Site::active()->with('conditions')->findOrFail($id);

        $payload = $this->detailCache->rememberChart(
            $site->id,
            fn () => $this->chartBuilder->build($site),
        );

        return response()->json($payload);
    }

    /**
     * Données multi-modèles pour le panel de comparaison détaillée.
     *
     * Query params :
     *  - day    : YYYY-MM-DD (obligatoire)
     *  - period : daylight (défaut) | 24h
     */
    public function multimodel(int $id, Request $request): JsonResponse
    {
        $site = Site::active()->with('conditions')->findOrFail($id);

        $day = (string) $request->query('day', '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
            return response()->json(['error' => 'Invalid or missing "day" parameter (YYYY-MM-DD).'], 400);
        }

        $period = $request->query('period', 'daylight');
        if (!in_array($period, ['daylight', '24h'], true)) {
            $period = 'daylight';
        }

        $payload = $this->detailCache->rememberMultimodel(
            $site->id,
            $day,
            $period,
            fn () => $this->multimodelBuilder->build($site, $day, $period),
        );

        return response()->json($payload);
    }
}
