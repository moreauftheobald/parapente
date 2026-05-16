<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\HasFilterableIndex;
use App\Http\Controllers\Controller;
use App\Models\Balise;
use App\Services\DataCoverage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * BackOffice — gestion des balises météo (PiouPiou, METAR, …).
 *
 * Les balises sont auto-découvertes et auto-mises-à-jour par les jobs
 * d'ingest (FetchPiouPiouReadingsJob, FetchMetarReadingsJob, …). On
 * n'expose donc PAS d'édition de coordonnées/nom (ces données viennent
 * des fournisseurs). Les seules actions admin sont :
 *   - Voir la liste / les dernières lectures
 *   - Activer/désactiver une balise (la désactivation manuelle empêche
 *     son ré-activation automatique tant que l'admin ne la réactive
 *     pas, au contraire de la désactivation auto pour inactivité qui
 *     peut être levée naturellement par une nouvelle lecture)
 *   - Supprimer (cascade DB sur balise_readings)
 */
class BaliseController extends Controller
{
    use HasFilterableIndex;

    private const SORTABLE = [
        'name', 'source', 'external_id', 'active', 'created_at',
    ];

    public function index(Request $request): View
    {
        $query = Balise::query();

        $this->applySearch($query, $request->input('search'), ['name', 'external_id']);
        if ($source = $request->input('source')) {
            $query->where('source', $source);
        }
        $this->applyTriStateFilter($query, $request->input('active'), 'active');

        [$sort, $dir] = $this->applySorting($query, $request, self::SORTABLE, 'name');

        // Eager-load la dernière lecture pour afficher la fraîcheur en colonne
        $query->with(['latestReading']);

        $balises = $query->paginate(50)->withQueryString();

        $sources = Balise::query()->distinct()->orderBy('source')->pluck('source');

        return view('admin.balises.index', [
            'balises'    => $balises,
            'sources'    => $sources,
            'sort'       => $sort,
            'dir'        => $dir,
            'totalCount' => Balise::count(),
        ]);
    }

    public function show(Balise $balise): View
    {
        // Dernières 50 lectures pour le bloc historique
        $balise->load(['latestReading']);
        $recentReadings = $balise->readings()
            ->orderByDesc('read_at')
            ->limit(50)
            ->get();

        return view('admin.balises.show', [
            'balise'         => $balise,
            'recentReadings' => $recentReadings,
        ]);
    }

    public function toggleActive(Balise $balise): RedirectResponse
    {
        $balise->active = ! $balise->active;
        $balise->save();

        app(DataCoverage::class)->flush();

        return redirect()
            ->back()
            ->with('status', sprintf(
                '%s « %s » %s.',
                $balise->active ? '✓' : '○',
                $balise->name,
                $balise->active ? 'activée' : 'désactivée'
            ));
    }

    public function destroy(Balise $balise): RedirectResponse
    {
        $name = $balise->name;
        $balise->delete(); // cascade DB sur balise_readings

        return redirect()
            ->route('admin.balises.index')
            ->with('status', "Balise « {$name} » supprimée.");
    }
}
