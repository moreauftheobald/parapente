<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\StationApi;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StationApiController extends Controller
{
    public function index(): View
    {
        $apis = StationApi::orderBy('name')->get();

        return view('admin.station-apis.index', [
            'apis' => $apis,
        ]);
    }

    public function edit(StationApi $stationApi): View
    {
        return view('admin.station-apis.edit', [
            'api' => $stationApi,
        ]);
    }

    public function update(StationApi $stationApi, Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'        => ['required', 'string', 'max:100'],
            'base_url'    => ['nullable', 'url', 'max:500'],
            'user_agent'  => ['nullable', 'string', 'max:255'],
            'api_key'     => ['nullable', 'string', 'max:512'],
            'daily_quota' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'active'      => ['nullable', 'boolean'],
        ]);

        $stationApi->name       = $data['name'];
        $stationApi->base_url   = $data['base_url'] ?: null;
        $stationApi->user_agent = ($data['user_agent'] ?? null) ?: null;
        $stationApi->active     = (bool) ($data['active'] ?? false);
        $stationApi->daily_quota = isset($data['daily_quota']) ? (int) $data['daily_quota'] : null;

        if (! empty($data['api_key'])) {
            $stationApi->api_key = $data['api_key'];
        }

        $stationApi->save();

        return redirect()
            ->route('admin.station-apis.edit', $stationApi)
            ->with('status', "API « {$stationApi->name} » enregistrée.");
    }

    public function toggleActive(StationApi $stationApi): RedirectResponse
    {
        $stationApi->active = ! $stationApi->active;
        $stationApi->save();

        return back()->with('status', sprintf(
            '%s « %s » %s.',
            $stationApi->active ? '✓' : '○',
            $stationApi->name,
            $stationApi->active ? 'activée' : 'désactivée'
        ));
    }
}
