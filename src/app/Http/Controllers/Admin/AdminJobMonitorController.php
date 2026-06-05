<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\JobMonitor;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminJobMonitorController extends Controller
{
    private const PER_PAGE = 50;

    private const GROUP_CONFIG = [
        'sites' => [
            'title'    => 'Monitoring — Sites',
            'subtitle' => 'Suivi des jobs de fetch prévisions, consensus et scoring des sites de vol.',
            'icon'     => 'fa-mountain-sun',
            'color'    => 'sky',
            'jobs'     => [
                'App\Jobs\FetchForecastsJob'      => ['label' => 'Orchestrateur horaire',  'schedule' => 'Toutes les heures'],
                'App\Jobs\FetchConsensusBatchJob'  => ['label' => 'Consensus batch',        'schedule' => 'Toutes les heures'],
                'App\Jobs\RebuildMapBundleJob'     => ['label' => 'Rebuild map bundle',     'schedule' => 'Post-scoring'],
            ],
        ],
        'stations' => [
            'title'    => 'Monitoring — Stations météo',
            'subtitle' => 'Suivi des jobs de polling des réseaux Météo-France, METAR et Infoclimat, et archivage des prévisions.',
            'icon'     => 'fa-tower-broadcast',
            'color'    => 'blue',
            'jobs'     => [
                'App\Jobs\FetchMfStationReadingsJob'         => ['label' => 'Météo-France (6min)',  'schedule' => 'xx:09/21/33/45/57'],
                'App\Jobs\FetchMetarStationReadingsJob'      => ['label' => 'METAR (NOAA)',         'schedule' => 'Toutes les 30 min'],
                'App\Jobs\FetchInfoclimatStationReadingsJob' => ['label' => 'Infoclimat (StatIC)',  'schedule' => 'Toutes les heures'],
                'App\Jobs\FetchStationForecastsJob'          => ['label' => 'Archive prévisions',   'schedule' => 'Toutes les heures (:15)'],
            ],
        ],
        'balises' => [
            'title'    => 'Monitoring — Balises',
            'subtitle' => 'Suivi des jobs de polling PiouPiou / METAR / Windy, archivage prévisions et agrégation horaire.',
            'icon'     => 'fa-tower-broadcast',
            'color'    => 'emerald',
            'jobs'     => [
                'App\Jobs\FetchPiouPiouReadingsJob'         => ['label' => 'PiouPiou',              'schedule' => 'Toutes les 10 min'],
                'App\Jobs\FetchMetarReadingsJob'            => ['label' => 'METAR (balises)',        'schedule' => 'Toutes les 30 min'],
                'App\Jobs\FetchWindyReadingsJob'            => ['label' => 'Windy Open Data',       'schedule' => 'Toutes les 30 min'],
                'App\Jobs\FetchBaliseForecastsJob'          => ['label' => 'Archive prévisions',    'schedule' => 'Toutes les heures'],
                'App\Jobs\AggregateBaliseReadingsHourlyJob' => ['label' => 'Agrégation horaire',    'schedule' => 'Toutes les heures (:05)'],
            ],
        ],
    ];

    public function sites(Request $request): View
    {
        return $this->renderGroup('sites', $request);
    }

    public function stations(Request $request): View
    {
        return $this->renderGroup('stations', $request);
    }

    public function balises(Request $request): View
    {
        return $this->renderGroup('balises', $request);
    }

    private function renderGroup(string $group, Request $request): View
    {
        $config    = self::GROUP_CONFIG[$group];
        $jobFilter = $request->input('job');
        $status    = $request->input('status');

        $query = JobMonitor::where('job_group', $group)
            ->orderByDesc('started_at');

        if ($jobFilter) {
            $query->where('job_class', $jobFilter);
        }
        if ($status) {
            $query->where('status', $status);
        }

        $entries = $query->paginate(self::PER_PAGE)->withQueryString();

        $summary = $this->buildSummary($group, $config['jobs']);

        return view('admin.job-monitor.index', [
            'group'     => $group,
            'config'    => $config,
            'entries'   => $entries,
            'summary'   => $summary,
            'jobFilter' => $jobFilter,
            'status'    => $status,
        ]);
    }

    private function buildSummary(string $group, array $jobsConfig): array
    {
        $summary = [];

        foreach ($jobsConfig as $class => $meta) {
            $last = JobMonitor::where('job_group', $group)
                ->where('job_class', $class)
                ->orderByDesc('started_at')
                ->first();

            $lastSuccess = null;
            $lastFailure = null;

            if ($last && $last->status !== 'success') {
                $lastSuccess = JobMonitor::where('job_group', $group)
                    ->where('job_class', $class)
                    ->where('status', 'success')
                    ->orderByDesc('started_at')
                    ->first();
            }
            if ($last && $last->status !== 'failed') {
                $lastFailure = JobMonitor::where('job_group', $group)
                    ->where('job_class', $class)
                    ->where('status', 'failed')
                    ->orderByDesc('started_at')
                    ->first();
            }

            $last24h = JobMonitor::where('job_group', $group)
                ->where('job_class', $class)
                ->where('started_at', '>=', now()->subHours(24))
                ->selectRaw('COUNT(*) as total')
                ->selectRaw("SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as success_count")
                ->selectRaw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_count")
                ->selectRaw("AVG(duration_ms) as avg_duration_ms")
                ->first();

            $summary[$class] = [
                'label'          => $meta['label'],
                'schedule'       => $meta['schedule'],
                'last'           => $last,
                'last_success'   => $last?->status === 'success' ? $last : $lastSuccess,
                'last_failure'   => $last?->status === 'failed' ? $last : $lastFailure,
                'total_24h'      => (int) ($last24h->total ?? 0),
                'success_24h'    => (int) ($last24h->success_count ?? 0),
                'failed_24h'     => (int) ($last24h->failed_count ?? 0),
                'avg_duration'   => $last24h->avg_duration_ms ? $this->formatDuration((int) $last24h->avg_duration_ms) : '—',
            ];
        }

        return $summary;
    }

    private function formatDuration(int $ms): string
    {
        if ($ms < 1000) return $ms . ' ms';
        $s = $ms / 1000;
        if ($s < 60) return number_format($s, 1) . ' s';
        return (int) floor($s / 60) . 'm ' . (int) ($s % 60) . 's';
    }
}
