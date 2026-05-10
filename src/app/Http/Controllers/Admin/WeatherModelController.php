<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WeatherApi;
use App\Models\WeatherModel;
use App\Services\Weather\Apis\WeatherApiRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use App\Models\Site;
use App\Services\Weather\ForecastFetcher;
use Illuminate\Http\JsonResponse;

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

    public function edit(WeatherModel $model, WeatherApiRegistry $registry): View
    {
        // Dropdown filtré : on ne propose que les APIs déclarant supporter
        // ce code de modèle (via WeatherApiInterface::supportedModelCodes).
        $compatibleApis = WeatherApi::orderBy('name')->get()
            ->filter(fn (WeatherApi $api) => $registry->supports($api, $model->code))
            ->values();

        return view('admin.models.edit', [
            'model'          => $model,
            'compatibleApis' => $compatibleApis,
        ]);
    }

    public function update(WeatherModel $model, Request $request, WeatherApiRegistry $registry): RedirectResponse
    {
        // resolution_km et max_horizon_h ne sont PAS éditables : ce sont
        // des propriétés intrinsèques du modèle source (Open-Meteo). Les
        // modifier côté admin créerait une désynchronisation avec la
        // réalité du fournisseur. On les ignore silencieusement si le
        // formulaire les renvoie.
        $data = $request->validate([
            'name'                      => ['required', 'string', 'max:100'],
            'provider'                  => ['required', 'string', 'max:100'],
            'weather_api_id'            => ['nullable', Rule::exists('weather_apis', 'id')],
            'oauth_client_id'           => ['nullable', 'string', 'max:512'],
            'oauth_client_secret'       => ['nullable', 'string', 'max:512'],
            'endpoint_url'              => ['nullable', 'url', 'max:500'],
            'weight_short'              => ['required', 'numeric', 'min:0', 'max:5'],
            'weight_medium'             => ['required', 'numeric', 'min:0', 'max:5'],
            'refresh_frequency_minutes' => ['required', 'integer', 'min:5', 'max:1440'],
            'active'                    => ['nullable', 'boolean'],
        ]);

        // Validation supplémentaire : l'API choisie doit déclarer servir ce modèle.
        if (! empty($data['weather_api_id'])) {
            $api = WeatherApi::find($data['weather_api_id']);
            if ($api && ! $registry->supports($api, $model->code)) {
                return back()
                    ->withInput()
                    ->withErrors(['weather_api_id' => "L'API « {$api->name} » ne sait pas servir le modèle {$model->code}."]);
            }
        }

        $model->fill([
            'name'                      => $data['name'],
            'provider'                  => $data['provider'],
            'weather_api_id'            => $data['weather_api_id'] ?: null,
            'endpoint_url'              => $data['endpoint_url'] ?: null,
            'weight_short'              => $data['weight_short'],
            'weight_medium'             => $data['weight_medium'],
            'refresh_frequency_minutes' => $data['refresh_frequency_minutes'],
            'active'                    => (bool) ($data['active'] ?? false),
        ]);

        // Credentials : on ne les écrase que si remplis (sinon un champ
        // vide effacerait une clé existante).
        if (! empty($data['oauth_client_id'])) {
            $model->oauth_client_id = $data['oauth_client_id'];
            // Invalide le token : il sera renouvelé au prochain fetch
            $model->oauth_token      = null;
            $model->oauth_expires_at = null;
        }
        if (! empty($data['oauth_client_secret'])) {
            $model->oauth_client_secret = $data['oauth_client_secret'];
            $model->oauth_token         = null;
            $model->oauth_expires_at    = null;
        }

        $model->save();

        return redirect()
            ->route('admin.models.edit', $model)
            ->with('status', "Modèle « {$model->name} » enregistré.");
    }

    /**
     * Test live de la configuration : tente un fetch sur le 1er site actif
     * via l'API associée au modèle. Retourne JSON avec succès/erreur et
     * un échantillon de données.
     *
     * Utilise les valeurs sauvegardées en base — l'admin doit sauvegarder
     * avant de tester si une modif est en cours.
     */
    public function test(WeatherModel $model, ForecastFetcher $fetcher): JsonResponse
    {
        $model->loadMissing('api');

        if (! $model->api) {
            return response()->json([
                'success' => false,
                'message' => 'Aucune API associée. Sauvegarder le modèle avec une API choisie avant de tester.',
            ], 422);
        }

        if (! $model->api->active) {
            return response()->json([
                'success' => false,
                'message' => "L'API « {$model->api->name} » est désactivée.",
            ], 422);
        }

        $site = Site::active()->orderBy('id')->first();
        if (! $site) {
            return response()->json([
                'success' => false,
                'message' => 'Aucun site actif disponible pour le test.',
            ], 422);
        }

        $startedAt  = microtime(true);
        $data       = $fetcher->fetch($site, $model);
        $durationMs = (int) ((microtime(true) - $startedAt) * 1000);

        $model->api->refresh();

        if (empty($data)) {
            return response()->json([
                'success'     => false,
                'message'     => "Le fetch n'a renvoyé aucune donnée. Vérifie credentials, endpoint et logs.",
                'site'        => $site->slug,
                'duration_ms' => $durationMs,
                'last_error'  => $model->api->last_error,
            ], 200);
        }

        $sample = array_slice($data, 0, 3, preserve_keys: true);

        return response()->json([
            'success'     => true,
            'message'     => sprintf('OK — %d créneaux récupérés en %d ms pour [%s]', count($data), $durationMs, $site->slug),
            'site'        => $site->slug,
            'duration_ms' => $durationMs,
            'slots_count' => count($data),
            'sample'      => $sample,
        ]);
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
