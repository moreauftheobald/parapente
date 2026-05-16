<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Balise;
use App\Models\BaliseReading;
use App\Services\Balises\PiouPiouProvider;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Polling périodique des lectures PiouPiou.
 *
 * Schedulé toutes les 10 minutes (cf. routes/console.php).
 *
 * Pour chaque balise active de la source 'pioupiou' :
 *  - Insère une nouvelle ligne dans balise_readings si le timestamp
 *    de la dernière mesure renvoyée par PiouPiou est plus récent que
 *    celui de la dernière lecture déjà en base.
 *  - Si le timestamp est identique (déjà connu), on skip.
 *
 * Désactivation automatique :
 *  - Toute balise pioupiou existante depuis plus de 7 jours et sans
 *    lecture < 7 jours en base passe active=false.
 *  - Les balises tout juste découvertes (créées il y a < 7j) ne sont
 *    pas désactivables même si pas encore de lecture insérée.
 */
class FetchPiouPiouReadingsJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 60;
    public int $tries   = 2;

    private const DEAD_AFTER_DAYS = 7;
    private const SOURCE          = 'pioupiou';

    public function handle(
        PiouPiouProvider $provider,
        \App\Services\Map\BalisesBundleCache $cache,
    ): void {
        $readings = $provider->fetchLatestReadings();
        if (empty($readings)) {
            Log::warning('FetchPiouPiouReadingsJob: empty readings batch');
            return;
        }

        $balises = Balise::source(self::SOURCE)
            ->active()
            ->get()
            ->keyBy('external_id');

        // Dernière read_at par balise pour éviter de re-insérer des doublons
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
                    continue; // déjà en base
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

        // Désactivation des balises mortes (> 7 jours sans lecture
        // ET découvertes depuis > 7 jours pour éviter de désactiver
        // une balise tout juste ajoutée).
        $deadCutoff = Carbon::now()->subDays(self::DEAD_AFTER_DAYS);
        $deactivated = Balise::source(self::SOURCE)
            ->where('active', true)
            ->where('created_at', '<', $deadCutoff)
            ->whereDoesntHave('readings', fn ($q) => $q->where('read_at', '>=', $deadCutoff))
            ->update(['active' => false]);

        // Le bundle balises servi par /api/balises est désormais obsolète :
        // on purge la clé Redis. Au prochain accès, le BaliseController
        // reconstruira avec les nouveaux readings.
        $cache->forgetBundle();

        Log::info('FetchPiouPiouReadingsJob completed', [
            'readings_inserted'   => $inserted,
            'balises_deactivated' => $deactivated,
            'balises_polled'      => $balises->count(),
        ]);
    }
}
