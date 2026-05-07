<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Balise;
use App\Models\Site;
use App\Models\User;
use Illuminate\View\View;

/**
 * Page d'accueil du BackOffice. Affiche quelques compteurs synthétiques
 * pour donner une vue d'ensemble dès la connexion.
 */
class DashboardController extends Controller
{
    public function index(): View
    {
        return view('admin.dashboard', [
            'sitesTotal'     => Site::count(),
            'sitesActive'    => Site::where('active', true)->count(),
            'sitesByOrigin'  => Site::selectRaw('source, COUNT(*) as n')
                                    ->groupBy('source')
                                    ->pluck('n', 'source')
                                    ->all(),
            'balisesTotal'   => Balise::count(),
            'balisesActive'  => Balise::where('active', true)->count(),
            'usersTotal'     => User::count(),
        ]);
    }
}
