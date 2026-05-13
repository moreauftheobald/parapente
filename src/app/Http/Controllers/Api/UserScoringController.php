<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreUserScoringRequest;
use App\Http\Requests\Api\UpdateUserScoringRequest;
use App\Models\Site;
use App\Models\UserSiteCondition;
use App\Services\Weather\UserScoringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API REST des scorings perso de l'utilisateur authentifié.
 *
 * Toutes les routes sont sous `auth` (cf. routes/api.php) — l'identité
 * est lue depuis `$request->user()`, on n'accepte jamais un user_id en
 * payload.
 */
class UserScoringController extends Controller
{
    public function __construct(private UserScoringService $userScoring)
    {
    }

    /**
     * Liste tous les scorings perso du user (actifs + dormants).
     *
     * Réponse :
     * ```
     * {
     *   "max_active": 10,
     *   "max_stored": 50,
     *   "active_count": 3,
     *   "scorings": [
     *     { id, site: { id, name, slug, level, latitude, longitude },
     *       is_active, activated_at,
     *       wind_dir_min, …, notes },
     *     …
     *   ]
     * }
     * ```
     */
    public function index(Request $request): JsonResponse
    {
        $scorings = UserSiteCondition::forUser($request->user())
            ->with(['site:id,name,slug,level,latitude,longitude,altitude_m,region'])
            ->orderByDesc('is_active')
            ->orderByDesc('activated_at')
            ->orderBy('id')
            ->get();

        $activeCount = $scorings->where('is_active', true)->count();

        return response()->json([
            'max_active'   => UserSiteCondition::MAX_ACTIVE,
            'max_stored'   => UserSiteCondition::MAX_STORED,
            'active_count' => $activeCount,
            'scorings'     => $scorings->map(fn ($u) => $this->present($u))->all(),
        ]);
    }

    public function store(StoreUserScoringRequest $request): JsonResponse
    {
        $data = $request->validated();

        $usc = new UserSiteCondition($data);
        $usc->user_id   = $request->user()->id;
        $usc->is_active = false;
        $usc->save();

        return response()->json([
            'scoring' => $this->present($usc->load('site:id,name,slug,level,latitude,longitude,altitude_m,region')),
        ], 201);
    }

    public function update(UpdateUserScoringRequest $request, UserSiteCondition $scoring): JsonResponse
    {
        $scoring->fill($request->validated())->save();

        // Si le scoring était actif, ses conditions ont changé → cache stale.
        if ($scoring->is_active) {
            $this->userScoring->invalidate($scoring->user_id, $scoring->site_id);
        }

        return response()->json([
            'scoring' => $this->present($scoring->load('site:id,name,slug,level,latitude,longitude,altitude_m,region')),
        ]);
    }

    public function destroy(Request $request, UserSiteCondition $scoring): JsonResponse
    {
        $this->authorizeOwnership($request, $scoring);

        if ($scoring->is_active) {
            $this->userScoring->invalidate($scoring->user_id, $scoring->site_id);
        }
        $scoring->delete();

        return response()->json(['deleted' => true]);
    }

    public function activate(Request $request, UserSiteCondition $scoring): JsonResponse
    {
        $this->authorizeOwnership($request, $scoring);

        $result = $this->userScoring->activate($scoring);

        return response()->json([
            'activated' => $this->present($result['activated']->load('site:id,name,slug')),
            'demoted'   => $result['demoted']
                ? $this->present($result['demoted']->load('site:id,name,slug'))
                : null,
        ]);
    }

    public function deactivate(Request $request, UserSiteCondition $scoring): JsonResponse
    {
        $this->authorizeOwnership($request, $scoring);

        $this->userScoring->deactivate($scoring);

        return response()->json([
            'scoring' => $this->present($scoring->refresh()->load('site:id,name,slug')),
        ]);
    }

    /** @return array<string,mixed> */
    private function present(UserSiteCondition $u): array
    {
        return [
            'id'           => $u->id,
            'site'         => $u->relationLoaded('site') && $u->site
                ? [
                    'id'         => $u->site->id,
                    'name'       => $u->site->name,
                    'slug'       => $u->site->slug,
                    'level'      => $u->site->level   ?? null,
                    'region'     => $u->site->region  ?? null,
                    'altitude_m' => $u->site->altitude_m ?? null,
                    'latitude'   => $u->site->latitude  ?? null,
                    'longitude'  => $u->site->longitude ?? null,
                  ]
                : ['id' => $u->site_id],
            'is_active'    => (bool) $u->is_active,
            'activated_at' => $u->activated_at?->toIso8601String(),

            'wind_dir_min'         => $u->wind_dir_min,
            'wind_dir_max'         => $u->wind_dir_max,
            'wind_speed_min'       => $u->wind_speed_min,
            'wind_speed_max'       => $u->wind_speed_max,
            'wind_speed_ideal'     => $u->wind_speed_ideal,
            'wind_gust_orange_kmh' => $u->wind_gust_orange_kmh !== null ? (float) $u->wind_gust_orange_kmh : null,
            'wind_gust_red_kmh'    => $u->wind_gust_red_kmh    !== null ? (float) $u->wind_gust_red_kmh    : null,
            'cloud_base_min_m'     => $u->cloud_base_min_m,
            'cloud_cover_low_max'  => $u->cloud_cover_low_max,
            'notes'                => $u->notes,
        ];
    }

    private function authorizeOwnership(Request $request, UserSiteCondition $scoring): void
    {
        abort_unless(
            (int) $scoring->user_id === (int) $request->user()->id,
            403,
            'Ce scoring n\'appartient pas à l\'utilisateur authentifié.'
        );
    }
}
