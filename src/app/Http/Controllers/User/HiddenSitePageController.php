<?php

declare(strict_types=1);

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\UserHiddenSite;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Écran complet de gestion des sites masqués sur la carte de volabilité.
 *
 * La page est purement Blade : elle rend l'UI Alpine.js qui consomme
 * l'API `/api/users/me/hidden-sites/*` (liste + masquer/réafficher) et
 * `/api/sites` (recherche dans tous les sites actifs). On ne passe ici
 * qu'un compteur initial pour éviter un flash au chargement.
 * Cf. FF_site_blacklist.md.
 */
class HiddenSitePageController extends Controller
{
    public function index(Request $request): View
    {
        return view('user.hidden-sites', [
            'initialHiddenCount' => UserHiddenSite::forUser($request->user()->id)->count(),
        ]);
    }
}
