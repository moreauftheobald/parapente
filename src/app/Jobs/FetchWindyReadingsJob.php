<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Balise;
use App\Models\BaliseReading;
use App\Services\Balises\WindyOpenDataProvider;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Polling périodique des lectures Windy.com (Open Data).
 *
 * Schedulé toutes les 30 minutes (cf. routes/console.php) — plus
 * conservateur que PiouPiou car Windy facture par appel via le
 * quota gratuit, et `fetchLatestReadings()` fait N appels (un par
 * balise).
 *
 * Pour chaque balise active de la source 'windy' :
 *  - Insère une nouvelle ligne dans balise_readings si le timestamp
 *    de la dernière mesure est plus récent que celui de la dernière
 *    lecture déjà en base.
 *  - Si pas de clé API configurée, le provider renvoie un tableau
 *    vide et le job se termine sans erreur (warning loggé).
 *
 * Désactivation automatique : même règle que PiouPiou — toute balise
 * windy existante depuis > 7 jours et sans lecture < 7 jours en base
 * passe active=false.
 */
class FetchWindyReadingsJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;
    public int $tries   = 2;

    private const DEAD_AFTER_DAYS = 7;
    private const SOURCE          = 'windy';

    public function handle(WindyOpenDataProvider $provider): void
    {
        $readings = $provider->fetchLatestReadings();
        if (empty($readings)) {
            // Cas légitime : pas de clé API, ou pas encore de balise
            // windy active en base. Le provider a déjà loggé.
            return;
        }

        $balises = Balise::query()
            ->where('source', self::SOURCE)
            ->where('active', true)
            ->get(['id', 'external_id'])
            ->keyBy('external_id');

        $latestPerBalise = BaliseReading::whereIn('balise_id', $balises->pluck('id'))
            ->select('balise_id', DB::raw('MAX(read_at) as latest'))
            ->groupBy('balise_id')
            ->pluck('latest', 'balise_id');

        $inserted = 0;
        foreach ($balises as $extId => $balise) {
            $r = $readings[$extId] ?? null;
            if (! $r || ! ($r['read_at'] ?? null)) continue;

            $previousLatest = $latestPerBalise[$balise->id] ?? null;
            if ($previousLatest !== null) {
                if ($r['read_at']->lessThanOrEqualTo(Carbon::parse($previousLatest))) {
                    continue;
                }
            }

            BaliseReading::create([
                'balise_id'      => $balise->id,
                'read_at'        => $r['read_at'],
                'wind_direction' => $r['wind_direction'],
                'wind_speed_avg' => $r['wind_speed_avg'],
                'wind_speed_min' => $r['wind_speed_min'],
                'wind_speed_max' => $r['wind_speed_max'],
                'temperature'    => $r['temperature'],
                'humidity'       => $r['humidity'],
            ]);
            $inserted++;
        }

        $deadCutoff = Carbon::now()->subDays(self::DEAD_AFTER_DAYS);
        $deactivated = Balise::query()
            ->where('source', self::SOURCE)
            ->where('active', true)
            ->where('created_at', '<', $deadCutoff)
            ->whereDoesntHave('readings', fn ($q) => $q->where('read_at', '>=', $deadCutoff))
            ->update(['active' => false]);

        Log::info('FetchWindyReadingsJob completed', [
            'readings_inserted'   => $inserted,
            'balises_deactivated' => $deactivated,
            'balises_polled'      => $balises->count(),
        ]);
    }
}
