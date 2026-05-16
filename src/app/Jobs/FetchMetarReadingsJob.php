<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Balise;
use App\Models\BaliseReading;
use App\Services\Balises\BaliseConstants;
use App\Services\Balises\MetarProvider;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Polling périodique des METAR (NOAA Aviation Weather Center).
 *
 * Schedulé toutes les 30 minutes (cf. routes/console.php).
 *
 * Pour chaque balise active de la source 'metar' :
 *  - Insère une nouvelle ligne dans balise_readings si le timestamp
 *    de l'obs renvoyée est plus récent que celui de la dernière
 *    lecture déjà en base.
 *
 * Désactivation automatique :
 *  - Toute balise metar existante depuis plus de 7 jours et sans
 *    lecture < 7 jours en base passe active=false.
 *
 * Cette logique est volontairement très proche de
 * FetchPiouPiouReadingsJob — on reste KISS, on factorisera quand on
 * aura 3+ sources.
 */
class FetchMetarReadingsJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 90;
    public int $tries   = 2;

    private const SOURCE = 'metar';

    public function handle(
        MetarProvider $provider,
        \App\Services\Map\BalisesBundleCache $cache,
    ): void {
        $readings = $provider->fetchLatestReadings();
        if (empty($readings)) {
            Log::warning('FetchMetarReadingsJob: empty readings batch');
            return;
        }

        $balises = Balise::source(self::SOURCE)
            ->active()
            ->get()
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
                $previousLatestDt = Carbon::parse($previousLatest);
                if ($r['read_at']->lessThanOrEqualTo($previousLatestDt)) {
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

        // Désactivation des balises mortes (cf. FetchPiouPiouReadingsJob)
        $deadCutoff = Carbon::now()->subDays(BaliseConstants::DEAD_AFTER_DAYS);
        $deactivated = Balise::source(self::SOURCE)
            ->where('active', true)
            ->where('created_at', '<', $deadCutoff)
            ->whereDoesntHave('readings', fn ($q) => $q->where('read_at', '>=', $deadCutoff))
            ->update(['active' => false]);

        // Le bundle balises est obsolète : purge la clé Redis.
        $cache->forgetBundle();

        Log::info('FetchMetarReadingsJob completed', [
            'readings_inserted'   => $inserted,
            'balises_deactivated' => $deactivated,
            'balises_polled'      => $balises->count(),
        ]);
    }
}
