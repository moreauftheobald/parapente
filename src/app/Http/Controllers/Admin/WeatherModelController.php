<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WeatherModel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * BackOffice — gestion des modèles météo.
 *
 * Pas de création : les modèles sont seedés depuis WeatherModelSeeder
 * (les codes sont des identifiants Open-Meteo précis qui ne s'inventent
 * pas). On expose : liste, édition (poids voting logic + cadence de
 * rafraîchissement + activation), pas de suppression non plus (un
 * modèle inactif suffit, et la suppression casserait les forecasts
 * historiques via cascade).
 */
class WeatherModelController extends Controller
{
    public function index(Request $request): View
    {
        $query = WeatherModel::query();

        if ($search = trim((string) $request->input('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%")
                  ->orWhere('provider', 'like', "%{$search}%");
            });
        }
        if ($provider = $request->input('provider')) {
            $query->where('provider', $provider);
        }
        $activeRaw = $request->input('active');
        if ($activeRaw === '1' || $activeRaw === '0') {
            $query->where('active', (int) $activeRaw);
        }

        $sort = $request->input('sort', 'name');
        $allowed = ['name', 'code', 'provider', 'resolution_km', 'max_horizon_h',
                    'weight_short', 'weight_medium', 'refresh_frequency_minutes', 'active'];
        if (! in_array($sort, $allowed, true)) {
            $sort = 'name';
        }
        $dir = $request->input('dir', 'asc') === 'desc' ? 'desc' : 'asc';
        $query->orderBy($sort, $dir);

        $models    = $query->get();
        $providers = WeatherModel::query()->distinct()->orderBy('provider')->pluck('provider');

        return view('admin.models.index', [
            'models'    => $models,
            'providers' => $providers,
            'sort'      => $sort,
            'dir'       => $dir,
        ]);
    }

    public function edit(WeatherModel $model): View
    {
        return view('admin.models.edit', ['model' => $model]);
    }

    public function update(WeatherModel $model, Request $request): RedirectResponse
    {
        // resolution_km et max_horizon_h ne sont PAS éditables : ce sont
        // des propriétés intrinsèques du modèle source (Open-Meteo). Les
        // modifier côté admin créerait une désynchronisation avec la
        // réalité du fournisseur. On les ignore silencieusement si le
        // formulaire les renvoie.
        $data = $request->validate([
            'name'                      => ['required', 'string', 'max:100'],
            'provider'                  => ['required', 'string', 'max:100'],
            'weight_short'              => ['required', 'numeric', 'min:0', 'max:5'],
            'weight_medium'             => ['required', 'numeric', 'min:0', 'max:5'],
            'refresh_frequency_minutes' => ['required', 'integer', 'min:5', 'max:1440'],
            'active'                    => ['nullable', 'boolean'],
        ]);

        $model->fill([
            'name'                      => $data['name'],
            'provider'                  => $data['provider'],
            'weight_short'              => $data['weight_short'],
            'weight_medium'             => $data['weight_medium'],
            'refresh_frequency_minutes' => $data['refresh_frequency_minutes'],
            'active'                    => (bool) ($data['active'] ?? false),
        ])->save();

        return redirect()
            ->route('admin.models.edit', $model)
            ->with('status', "Modèle « {$model->name} » enregistré.");
    }

    public function toggleActive(WeatherModel $model): RedirectResponse
    {
        $model->active = ! $model->active;
        $model->save();

        return redirect()
            ->back()
            ->with('status', sprintf(
                '%s « %s » %s.',
                $model->active ? '✓' : '○',
                $model->name,
                $model->active ? 'activé' : 'désactivé'
            ));
    }
}
