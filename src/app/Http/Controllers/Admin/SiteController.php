<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Site;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * BackOffice — gestion des sites de vol.
 *
 * Pour la V1 : liste filtrable + paginée + toggle d'activation.
 * L'édition fine (conditions de vol, niveau, etc.) viendra en
 * session 3.
 */
class SiteController extends Controller
{
    /** Champs autorisés au tri (whitelist, anti-injection) */
    private const SORTABLE = [
        'name', 'source', 'level', 'altitude_m', 'active', 'region', 'created_at',
    ];

    public function index(Request $request): View
    {
        $query = Site::query();

        // ── Filtres ──────────────────────────────────────────────
        if ($search = trim((string) $request->input('search', ''))) {
            $query->where('name', 'like', '%' . $search . '%');
        }
        if ($source = $request->input('source')) {
            $query->where('source', $source);
        }
        // active : '1', '0' ou '' (= tous)
        $activeRaw = $request->input('active');
        if ($activeRaw === '1' || $activeRaw === '0') {
            $query->where('active', (int) $activeRaw);
        }
        if ($level = $request->input('level')) {
            $query->where('level', $level);
        }
        if ($region = $request->input('region')) {
            $query->where('region', $region);
        }

        // ── Tri ──────────────────────────────────────────────────
        $sort = $request->input('sort', 'name');
        if (! in_array($sort, self::SORTABLE, true)) {
            $sort = 'name';
        }
        $dir = $request->input('dir', 'asc') === 'desc' ? 'desc' : 'asc';
        $query->orderBy($sort, $dir);
        // Ordre stable secondaire
        if ($sort !== 'id') $query->orderBy('id');

        // ── Pagination ───────────────────────────────────────────
        $sites = $query->paginate(50)->withQueryString();

        // Listes d'options pour les selects
        $sources = Site::query()->distinct()->orderBy('source')->pluck('source');
        $regions = Site::query()->whereNotNull('region')->distinct()->orderBy('region')->pluck('region');

        return view('admin.sites.index', [
            'sites'      => $sites,
            'sources'    => $sources,
            'regions'    => $regions,
            'sort'       => $sort,
            'dir'        => $dir,
            'totalCount' => Site::count(),
        ]);
    }

    /**
     * Toggle l'activation d'un site (active=true ↔ false).
     * L'activation rend le site éligible au fetch météo et le fait
     * apparaître sur la carte publique.
     */
    public function toggleActive(Site $site, Request $request): RedirectResponse
    {
        $site->active = ! $site->active;
        $site->save();

        return redirect()
            ->back()
            ->with('status', sprintf(
                '%s "%s" %s.',
                $site->active ? '✓' : '○',
                $site->name,
                $site->active ? 'activé' : 'désactivé'
            ));
    }
}
