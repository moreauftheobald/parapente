<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PageView;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * BackOffice — tableau de bord de fréquentation.
 *
 * Agrégations sur la table `page_views` alimentée par le middleware
 * RecordPageView. Toutes les requêtes filtrent par défaut
 * `device_type != 'bot'` pour ne montrer que le trafic humain.
 *
 * Index sollicités :
 *  - `page_views_visited_at_index` (range scan par fenêtre temporelle)
 *  - `pv_visited_device_idx`        (KPI filtré bots/humains)
 *  - `pv_visitor_visited_idx`       (uniques)
 */
class TrafficController extends Controller
{
    public function index(): View
    {
        $tz       = new \DateTimeZone('Europe/Paris');
        $now      = Carbon::now($tz);
        $today    = $now->copy()->startOfDay();
        $d7       = $now->copy()->subDays(7);
        $d30      = $now->copy()->subDays(30);

        return view('admin.traffic.index', [
            'kpis'         => $this->kpis($today, $d7, $d30, $now),
            'hourlyToday'  => $this->hourly($today),
            'dailyMonth'   => $this->daily($d30),
            'topPages'     => $this->topPages($d30, 10),
            'devices'      => $this->deviceBreakdown($d30),
            'os'           => $this->breakdown($d30, 'os'),
            'browsers'     => $this->breakdown($d30, 'browser'),
            'referers'     => $this->topReferers($d30, 10),
            'now'          => $now,
        ]);
    }

    // ── KPIs ─────────────────────────────────────────────────────────────

    /**
     * @return array<string, array{visits:int, uniques:int, delta_pct:?int}>
     */
    private function kpis(Carbon $today, Carbon $d7, Carbon $d30, Carbon $now): array
    {
        // Aujourd'hui — humains uniquement
        $tToday  = $this->countAndUniques($today, $now);
        // Hier équivalent (même nombre d'heures écoulées) pour le delta
        $hoursElapsed = $today->diffInMinutes($now) / 60;
        $yStart = $today->copy()->subDay();
        $yEnd   = $yStart->copy()->addMinutes((int) round($hoursElapsed * 60));
        $tYest  = $this->countAndUniques($yStart, $yEnd);

        $t7    = $this->countAndUniques($d7, $now);
        // 7 j avant pour le delta
        $t7Prev = $this->countAndUniques($d7->copy()->subDays(7), $d7);

        $t30   = $this->countAndUniques($d30, $now);
        $t30Prev = $this->countAndUniques($d30->copy()->subDays(30), $d30);

        return [
            'today'      => $tToday + ['delta_pct' => $this->deltaPct($tToday['visits'], $tYest['visits'])],
            'last_7d'    => $t7     + ['delta_pct' => $this->deltaPct($t7['visits'],    $t7Prev['visits'])],
            'last_30d'   => $t30    + ['delta_pct' => $this->deltaPct($t30['visits'],   $t30Prev['visits'])],
        ];
    }

    /**
     * @return array{visits:int, uniques:int}
     */
    private function countAndUniques(Carbon $from, Carbon $to): array
    {
        $row = PageView::query()
            ->whereBetween('visited_at', [$from, $to])
            ->where('device_type', '!=', 'bot')
            ->selectRaw('COUNT(*) AS visits, COUNT(DISTINCT visitor_hash) AS uniques')
            ->first();

        return [
            'visits'  => (int) ($row->visits ?? 0),
            'uniques' => (int) ($row->uniques ?? 0),
        ];
    }

    private function deltaPct(int $current, int $previous): ?int
    {
        if ($previous === 0) {
            return $current === 0 ? 0 : null; // null = N/A (pas de base de comparaison)
        }
        return (int) round((($current - $previous) / $previous) * 100);
    }

    // ── Séries temporelles ───────────────────────────────────────────────

    /**
     * Visites par heure pour la journée en cours (24 buckets).
     *
     * @return array<int, array{hour:int, visits:int, uniques:int}>
     */
    private function hourly(Carbon $dayStart): array
    {
        $raw = PageView::query()
            ->where('visited_at', '>=', $dayStart)
            ->where('visited_at', '<', $dayStart->copy()->addDay())
            ->where('device_type', '!=', 'bot')
            ->selectRaw('HOUR(visited_at) AS hour, COUNT(*) AS visits, COUNT(DISTINCT visitor_hash) AS uniques')
            ->groupBy('hour')
            ->get()
            ->keyBy('hour');

        $out = [];
        for ($h = 0; $h < 24; $h++) {
            $r = $raw->get($h);
            $out[] = [
                'hour'    => $h,
                'visits'  => $r ? (int) $r->visits : 0,
                'uniques' => $r ? (int) $r->uniques : 0,
            ];
        }
        return $out;
    }

    /**
     * Visites par jour sur les 30 derniers jours.
     *
     * @return array<int, array{date:string, label:string, visits:int, uniques:int}>
     */
    private function daily(Carbon $from): array
    {
        $raw = PageView::query()
            ->where('visited_at', '>=', $from)
            ->where('device_type', '!=', 'bot')
            ->selectRaw('DATE(visited_at) AS d, COUNT(*) AS visits, COUNT(DISTINCT visitor_hash) AS uniques')
            ->groupBy('d')
            ->get()
            ->keyBy('d');

        $out = [];
        $cursor = $from->copy()->startOfDay();
        $end    = Carbon::now($from->timezone)->startOfDay();
        while ($cursor->lte($end)) {
            $key = $cursor->format('Y-m-d');
            $r   = $raw->get($key);
            $out[] = [
                'date'    => $key,
                'label'   => $cursor->translatedFormat('d/m'),
                'visits'  => $r ? (int) $r->visits : 0,
                'uniques' => $r ? (int) $r->uniques : 0,
            ];
            $cursor->addDay();
        }
        return $out;
    }

    // ── Breakdown ────────────────────────────────────────────────────────

    /**
     * @return array<int, array{path:string, visits:int}>
     */
    private function topPages(Carbon $from, int $limit): array
    {
        return PageView::query()
            ->where('visited_at', '>=', $from)
            ->where('device_type', '!=', 'bot')
            ->select('path', DB::raw('COUNT(*) AS visits'))
            ->groupBy('path')
            ->orderByDesc('visits')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => ['path' => (string) $r->path, 'visits' => (int) $r->visits])
            ->toArray();
    }

    /**
     * Inclut les bots — l'admin verra leur volume séparément.
     *
     * @return array<int, array{key:string, visits:int}>
     */
    private function deviceBreakdown(Carbon $from): array
    {
        return PageView::query()
            ->where('visited_at', '>=', $from)
            ->select('device_type', DB::raw('COUNT(*) AS visits'))
            ->groupBy('device_type')
            ->orderByDesc('visits')
            ->get()
            ->map(fn ($r) => ['key' => (string) $r->device_type, 'visits' => (int) $r->visits])
            ->toArray();
    }

    /**
     * Breakdown générique humans-only (os / browser).
     *
     * @return array<int, array{key:string, visits:int}>
     */
    private function breakdown(Carbon $from, string $column): array
    {
        return PageView::query()
            ->where('visited_at', '>=', $from)
            ->where('device_type', '!=', 'bot')
            ->whereNotNull($column)
            ->select($column, DB::raw('COUNT(*) AS visits'))
            ->groupBy($column)
            ->orderByDesc('visits')
            ->limit(10)
            ->get()
            ->map(fn ($r) => ['key' => (string) $r->{$column}, 'visits' => (int) $r->visits])
            ->toArray();
    }

    /**
     * @return array<int, array{host:string, visits:int}>
     */
    private function topReferers(Carbon $from, int $limit): array
    {
        return PageView::query()
            ->where('visited_at', '>=', $from)
            ->where('device_type', '!=', 'bot')
            ->whereNotNull('referer_host')
            ->select('referer_host', DB::raw('COUNT(*) AS visits'))
            ->groupBy('referer_host')
            ->orderByDesc('visits')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => ['host' => (string) $r->referer_host, 'visits' => (int) $r->visits])
            ->toArray();
    }
}
