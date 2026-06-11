<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WeatherApi;
use App\Services\Weather\Apis\WeatherApiRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * BackOffice — gestion des sources API météo (Open-Meteo, MET Norway,
 * Météo-France, DWD, ECMWF…).
 *
 * Pas de création/suppression : la liste des APIs reconnues est figée
 * dans le code (cf. WeatherApiRegistry). Le seeder crée les lignes.
 * L'admin édite : credentials, user-agent, activation, quota déclaré.
 */
class WeatherApiController extends Controller
{
    public function index(): View
    {
        return view('admin.apis.index', [
            'apis'        => WeatherApi::orderBy('name')->get(),
            'stationApis' => \App\Models\StationApi::orderBy('name')->get(),
        ]);
    }

    public function edit(WeatherApi $api, WeatherApiRegistry $registry): View
    {
        // Liste des modèles que cette API sait servir (pour info dans l'UI)
        $impl              = $registry->for($api);
        $supportedModelsCodes = $impl->supportedModelCodes();

        return view('admin.apis.edit', [
            'api'                  => $api,
            'supportedModelsCodes' => $supportedModelsCodes,
        ]);
    }

    public function update(WeatherApi $api, Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'        => ['required', 'string', 'max:100'],
            'base_url'    => ['required', 'url', 'max:255'],
            'user_agent'  => ['nullable', 'string', 'max:255'],
            'api_key'     => ['nullable', 'string', 'max:512'],
            'daily_quota' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'active'      => ['nullable', 'boolean'],
        ]);

        $api->name     = $data['name'];
        $api->base_url = $data['base_url'];
        $api->active   = (bool) ($data['active'] ?? false);

        // user_agent et daily_quota peuvent être absents du payload si la
        // vue ne les a pas rendus (ex : auth_type=none → pas d'input
        // user_agent). On utilise ?? pour gérer l'absence.
        if ($api->auth_type === 'user_agent') {
            $api->user_agent = ($data['user_agent'] ?? null) ?: null;
        }
        $api->daily_quota = isset($data['daily_quota']) ? (int) $data['daily_quota'] : null;

        // Credentials API-level : on ne les écrase que si remplis
        // (les credentials OAuth2 vivent au niveau modèle, pas ici).
        if (! empty($data['api_key'])) {
            $api->api_key = $data['api_key'];
        }

        $api->save();

        return redirect()
            ->route('admin.apis.edit', $api)
            ->with('status', "API « {$api->name} » enregistrée.");
    }

    public function toggleActive(WeatherApi $api): RedirectResponse
    {
        $api->active = ! $api->active;
        $api->save();

        return back()->with('status', sprintf(
            '%s « %s » %s.',
            $api->active ? '✓' : '○',
            $api->name,
            $api->active ? 'activée' : 'désactivée'
        ));
    }
}
