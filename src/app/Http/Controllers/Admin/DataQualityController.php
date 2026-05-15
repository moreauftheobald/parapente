<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\IgnoredDuplicate;
use App\Services\DataQualityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * BackOffice — Qualité des données.
 *
 * Pour l'instant, l'écran liste les paires de doublons potentiels
 * (sites et balises) sur la base des coordonnées géographiques, en
 * appliquant les seuils éditables dans /admin/settings (groupe
 * « quality »). L'admin peut désactiver l'une ou l'autre entité, ou
 * marquer la paire comme « non pertinente » (mémorisée en base via
 * `ignored_duplicates` pour ne plus la voir).
 */
class DataQualityController extends Controller
{
    public function index(Request $request, DataQualityService $service): View
    {
        $showIgnored = $request->boolean('show_ignored');

        $sitePairs    = $service->detectSiteDuplicates();
        $balisePairs  = $service->detectBaliseDuplicates();

        if (! $showIgnored) {
            $sitePairs   = array_values(array_filter($sitePairs,   fn ($p) => ! $p['ignored']));
            $balisePairs = array_values(array_filter($balisePairs, fn ($p) => ! $p['ignored']));
        }

        return view('admin.data-quality.index', [
            'sitePairs'   => $sitePairs,
            'balisePairs' => $balisePairs,
            'showIgnored' => $showIgnored,
        ]);
    }

    public function ignore(Request $request, DataQualityService $service): RedirectResponse
    {
        $data = $request->validate([
            'entity_type' => ['required', 'in:' . IgnoredDuplicate::TYPE_SITE . ',' . IgnoredDuplicate::TYPE_BALISE],
            'entity_a_id' => ['required', 'integer', 'min:1'],
            'entity_b_id' => ['required', 'integer', 'min:1', 'different:entity_a_id'],
        ]);

        $service->ignorePair(
            $data['entity_type'],
            (int) $data['entity_a_id'],
            (int) $data['entity_b_id'],
            auth()->id(),
        );

        return back()->with('status', 'Paire marquée comme non pertinente.');
    }

    public function unignore(IgnoredDuplicate $ignored, DataQualityService $service): RedirectResponse
    {
        $service->unignorePair($ignored);

        return back()->with('status', 'Paire restaurée dans la liste.');
    }
}
