<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\UserHiddenSite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CRUD des sites masqués de l'utilisateur authentifié.
 *
 * Toutes les routes sont sous `auth:web` (cf. routes/api.php) —
 * l'identité est lue depuis `$request->user()`, jamais en payload.
 *
 * Modèle d'exclusion pure : « masquer » = créer une ligne,
 * « réafficher » = la supprimer. Idempotent des deux côtés. Pas de
 * limite de cardinalité (un utilisateur peut masquer autant de sites
 * qu'il veut). Cf. FF_site_blacklist.md.
 */
class UserHiddenSiteController extends Controller
{
    /**
     * Liste des id de sites masqués par l'utilisateur (+ métadonnées
     * site pour l'affichage de la page de gestion).
     *
     * Réponse :
     * ```
     * {
     *   "hidden_site_ids": [3, 7],
     *   "sites": [ { id, name, region, level, altitude }, … ]   // masqués uniquement
     * }
     * ```
     */
    public function index(Request $request): JsonResponse
    {
        $hidden = UserHiddenSite::forUser($request->user()->id)
            ->with(['site:id,name,region,level,altitude_m'])
            ->get();

        return response()->json([
            'hidden_site_ids' => $hidden->pluck('site_id')->map(fn ($id) => (int) $id)->values()->all(),
            'sites'           => $hidden
                ->filter(fn (UserHiddenSite $h) => $h->site !== null)
                ->map(fn (UserHiddenSite $h) => [
                    'id'       => $h->site->id,
                    'name'     => $h->site->name,
                    'region'   => $h->site->region,
                    'level'    => $h->site->level,
                    'altitude' => $h->site->altitude_m,
                ])->values()->all(),
        ]);
    }

    /**
     * Masque un site pour l'utilisateur (idempotent).
     *
     * On accepte n'importe quel site existant en binding ; inutile de
     * restreindre aux sites actifs (un site désactivé n'apparaît de
     * toute façon pas sur la carte, et le masquer d'avance est inoffensif).
     */
    public function hide(Request $request, Site $site): JsonResponse
    {
        UserHiddenSite::firstOrCreate([
            'user_id' => $request->user()->id,
            'site_id' => $site->id,
        ]);

        return response()->json(['hidden' => true, 'site_id' => $site->id]);
    }

    /** Réaffiche un site (supprime la ligne d'exclusion ; idempotent). */
    public function unhide(Request $request, Site $site): JsonResponse
    {
        UserHiddenSite::forUser($request->user()->id)
            ->where('site_id', $site->id)
            ->delete();

        return response()->json(['hidden' => false, 'site_id' => $site->id]);
    }
}
