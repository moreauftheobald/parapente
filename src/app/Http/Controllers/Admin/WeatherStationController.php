<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\HasFilterableIndex;
use App\Http\Controllers\Controller;
use App\Models\WeatherStation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WeatherStationController extends Controller
{
    use HasFilterableIndex;

    private const SORTABLE = [
        'name', 'network', 'external_id', 'active', 'altitude_m',
        'country_code', 'admin_region', 'department', 'last_obs_at', 'created_at',
    ];

    public function index(Request $request): View
    {
        $query = WeatherStation::query();

        $this->applySearch($query, $request->input('search'), ['name', 'external_id']);

        if ($network = $request->input('network')) {
            $query->where('network', $network);
        }

        $this->applyTriStateFilter($query, $request->input('active'), 'active');

        if ($countryCode = $request->input('country_code')) {
            $query->where('country_code', $countryCode);
        }
        if ($adminRegion = $request->input('admin_region')) {
            $query->where('admin_region', $adminRegion);
        }
        if ($department = $request->input('department')) {
            $query->where('department', $department);
        }

        [$sort, $dir] = $this->applySorting($query, $request, self::SORTABLE, 'name');

        $query->with(['latestObservation']);

        $stations = $query->paginate(50)->withQueryString();

        $networks      = WeatherStation::query()->distinct()->orderBy('network')->pluck('network');
        $countryCodes  = WeatherStation::query()->whereNotNull('country_code')->distinct()->orderBy('country_code')->pluck('country_code');
        $adminRegions  = WeatherStation::query()->whereNotNull('admin_region')->distinct()->orderBy('admin_region')->pluck('admin_region');
        $departments   = WeatherStation::query()->whereNotNull('department')->distinct()->orderBy('department')->pluck('department');

        return view('admin.weather-stations.index', [
            'stations'      => $stations,
            'networks'      => $networks,
            'countryCodes'  => $countryCodes,
            'adminRegions'  => $adminRegions,
            'departments'   => $departments,
            'sort'          => $sort,
            'dir'           => $dir,
            'totalCount'    => WeatherStation::count(),
        ]);
    }

    public function show(WeatherStation $weatherStation): View
    {
        $weatherStation->load(['latestObservation']);
        $recentObservations = $weatherStation->observations()
            ->orderByDesc('observed_at')
            ->limit(50)
            ->get();

        return view('admin.weather-stations.show', [
            'station'            => $weatherStation,
            'recentObservations' => $recentObservations,
        ]);
    }

    public function toggleActive(WeatherStation $weatherStation): RedirectResponse
    {
        $weatherStation->active = ! $weatherStation->active;
        $weatherStation->save();

        return redirect()
            ->back()
            ->with('status', sprintf(
                '%s « %s » %s.',
                $weatherStation->active ? '✓' : '○',
                $weatherStation->name,
                $weatherStation->active ? 'activée' : 'désactivée'
            ));
    }

    public function togglePanel(WeatherStation $weatherStation): RedirectResponse
    {
        $weatherStation->in_reliability_panel = ! $weatherStation->in_reliability_panel;
        $weatherStation->save();

        return redirect()
            ->back()
            ->with('status', sprintf(
                'Panel fiabilité : « %s » %s.',
                $weatherStation->name,
                $weatherStation->in_reliability_panel ? 'incluse' : 'retirée'
            ));
    }

    public function destroy(WeatherStation $weatherStation): RedirectResponse
    {
        $name = $weatherStation->name;
        $weatherStation->delete();

        return redirect()
            ->route('admin.weather-stations.index')
            ->with('status', "Station « {$name} » supprimée.");
    }
}
