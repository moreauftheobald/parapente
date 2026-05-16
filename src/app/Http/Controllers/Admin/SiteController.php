<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\HasFilterableIndex;
use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\SiteCondition;
use App\Services\DataCoverage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
    use HasFilterableIndex;

    /** Champs autorisés au tri (whitelist, anti-injection) */
    private const SORTABLE = [
        'name', 'source', 'level', 'altitude_m', 'active', 'region', 'created_at',
    ];

    public function index(Request $request): View
    {
        $query = Site::query();

        $this->applySearch($query, $request->input('search'), ['name']);
        if ($source = $request->input('source')) {
            $query->where('source', $source);
        }
        $this->applyTriStateFilter($query, $request->input('active'), 'active');
        if ($level = $request->input('level')) {
            $query->where('level', $level);
        }
        if ($region = $request->input('region')) {
            $query->where('region', $region);
        }

        [$sort, $dir] = $this->applySorting($query, $request, self::SORTABLE, 'name');
        // Ordre stable secondaire
        if ($sort !== 'id') $query->orderBy('id');

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
     * Vue carte du back-office : tous les sites (actifs et inactifs)
     * sur une carte Leaflet, avec toggle au clic et panneau latéral.
     */
    public function map(): View
    {
        $sites = Site::with('conditions')
            ->orderBy('name')
            ->get()
            ->map(fn (Site $s) => [
                'id'           => $s->id,
                'name'         => $s->name,
                'slug'         => $s->slug,
                'region'       => $s->region,
                'source'       => $s->source,
                'level'        => $s->level,
                'lat'          => (float) $s->latitude,
                'lng'          => (float) $s->longitude,
                'altitude_m'   => $s->altitude_m,
                'landing_lat'  => $s->landing_lat !== null ? (float) $s->landing_lat : null,
                'landing_lng'  => $s->landing_lng !== null ? (float) $s->landing_lng : null,
                'active'       => (bool) $s->active,
                'description'  => $s->description,
                'edit_url'     => route('admin.sites.edit', $s),
                'toggle_url'   => route('admin.sites.toggle', $s),
                'conditions'   => $s->conditions ? [
                    'wind_dir_min'     => $s->conditions->wind_dir_min,
                    'wind_dir_max'     => $s->conditions->wind_dir_max,
                    'wind_speed_min'   => $s->conditions->wind_speed_min,
                    'wind_speed_max'   => $s->conditions->wind_speed_max,
                    'wind_speed_ideal' => $s->conditions->wind_speed_ideal,
                ] : null,
            ])
            ->values();

        return view('admin.sites.map', [
            'sites'        => $sites,
            'activeCount'  => $sites->where('active', true)->count(),
            'totalCount'   => $sites->count(),
        ]);
    }

    /**
     * Toggle l'activation d'un site (active=true ↔ false).
     * L'activation rend le site éligible au fetch météo et le fait
     * apparaître sur la carte publique.
     *
     * Répond en JSON pour les appels AJAX (vue carte), en redirect
     * sinon (boutons des listes).
     */
    public function toggleActive(Site $site, Request $request): RedirectResponse|JsonResponse
    {
        $site->active = ! $site->active;
        $site->save();

        app(DataCoverage::class)->flush();

        $message = sprintf(
            '%s "%s" %s.',
            $site->active ? '✓' : '○',
            $site->name,
            $site->active ? 'activé' : 'désactivé'
        );

        if ($request->expectsJson()) {
            return response()->json([
                'id'      => $site->id,
                'active'  => $site->active,
                'message' => $message,
            ]);
        }

        return redirect()->back()->with('status', $message);
    }

    public function edit(Site $site): View
    {
        $site->load('conditions');
        return view('admin.sites.edit', ['site' => $site]);
    }

    public function update(Site $site, Request $request): RedirectResponse
    {
        $data = $request->validate([
            // Site
            'name'        => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'region'      => ['nullable', 'string', 'max:100'],
            'level'       => ['required', 'in:debutant,intermediaire,confirme'],
            'latitude'    => ['required', 'numeric', 'between:-90,90'],
            'longitude'   => ['required', 'numeric', 'between:-180,180'],
            'altitude_m'  => ['nullable', 'integer', 'between:0,9000'],
            'landing_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'landing_lng' => ['nullable', 'numeric', 'between:-180,180'],
            'active'      => ['nullable', 'boolean'],
            // Conditions
            'wind_dir_min'         => ['required', 'integer', 'between:0,360'],
            'wind_dir_max'         => ['required', 'integer', 'between:0,360'],
            'wind_speed_min'       => ['required', 'numeric', 'min:0', 'max:100'],
            'wind_speed_max'       => ['required', 'numeric', 'min:0', 'max:100'],
            'wind_speed_ideal'     => ['required', 'numeric', 'min:0', 'max:100'],
            'wind_gust_orange_kmh' => ['nullable', 'numeric', 'min:0', 'max:200'],
            'wind_gust_red_kmh'    => ['nullable', 'numeric', 'min:0', 'max:200'],
            'cloud_base_min_m'     => ['required', 'integer', 'between:0,5000'],
            'cloud_cover_low_max'  => ['required', 'integer', 'between:0,100'],
            'notes'                => ['nullable', 'string'],
        ]);

        // Slug regénéré automatiquement depuis le nom (suffixe -pge-{id}
        // conservé pour les sites importés afin de garder la traçabilité).
        $slug = Str::slug($data['name']);
        if ($site->source === 'paraglidingearth' && $site->external_id) {
            $slug .= '-pge-' . $site->external_id;
        }

        DB::transaction(function () use ($site, $data, $slug) {
            $site->fill([
                'name'        => $data['name'],
                'slug'        => $slug,
                'description' => $data['description'] ?? null,
                'region'      => $data['region']      ?? null,
                'level'       => $data['level'],
                'latitude'    => $data['latitude'],
                'longitude'   => $data['longitude'],
                'altitude_m'  => $data['altitude_m']  ?? null,
                'landing_lat' => $data['landing_lat'] ?? null,
                'landing_lng' => $data['landing_lng'] ?? null,
                'active'      => (bool) ($data['active'] ?? false),
            ])->save();

            SiteCondition::updateOrCreate(
                ['site_id' => $site->id],
                [
                    'wind_dir_min'         => $data['wind_dir_min'],
                    'wind_dir_max'         => $data['wind_dir_max'],
                    'wind_speed_min'       => $data['wind_speed_min'],
                    'wind_speed_max'       => $data['wind_speed_max'],
                    'wind_speed_ideal'     => $data['wind_speed_ideal'],
                    'wind_gust_orange_kmh' => $data['wind_gust_orange_kmh'] ?? null,
                    'wind_gust_red_kmh'    => $data['wind_gust_red_kmh'] ?? null,
                    'cloud_base_min_m'     => $data['cloud_base_min_m'],
                    'cloud_cover_low_max'  => $data['cloud_cover_low_max'],
                    'notes'                => $data['notes'] ?? null,
                ]
            );
        });

        return redirect()
            ->route('admin.sites.edit', $site)
            ->with('status', 'Site "' . $site->name . '" enregistré.');
    }

    public function destroy(Site $site): RedirectResponse
    {
        $name = $site->name;
        $site->delete(); // cascade DB sur site_conditions, forecasts, site_scores

        return redirect()
            ->route('admin.sites.index')
            ->with('status', 'Site "' . $name . '" supprimé.');
    }
}
