<?php

declare(strict_types=1);

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\UserSiteCondition;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Écran complet de gestion des scorings perso de l'utilisateur authentifié.
 *
 * La page elle-même est purement Blade — elle ne fait que rendre l'UI
 * Alpine.js qui consommera l'API `/api/users/me/scorings/*` pour le CRUD.
 * On n'expose ici que quelques métadonnées (compteurs initiaux) pour
 * éviter un flash visuel au chargement.
 */
class ScoringPageController extends Controller
{
    public function index(Request $request): View
    {
        $userId = $request->user()->id;

        return view('user.scorings', [
            'maxActive'          => UserSiteCondition::MAX_ACTIVE,
            'maxStored'          => UserSiteCondition::MAX_STORED,
            'initialActiveCount' => UserSiteCondition::forUser($userId)->active()->count(),
            'initialStoredCount' => UserSiteCondition::forUser($userId)->count(),
        ]);
    }
}
