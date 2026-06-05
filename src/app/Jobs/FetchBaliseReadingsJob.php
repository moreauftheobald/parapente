<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Balise;
use App\Models\BaliseReading;
use App\Services\Balises\BaliseConstants;
use App\Services\Balises\BaliseProviderInterface;
use App\Jobs\Concerns\TracksExecution;
use App\Services\Map\BalisesBundleCache;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Base abstraite pour les jobs de polling des balises (PiouPiou,
 * Windy…).
 *
 * Chaque source partage la même logique :
 *  1. Appeler le provider pour récupérer la dernière lecture de chaque
 *     balise connue (map external_id → reading).
 *  2. Pour chaque balise active de la source en base, insérer la lecture
 *     si son timestamp est strictement plus récent que le `MAX(read_at)`
 *     déjà connu.
 *  3. Désactiver les balises mortes (existantes depuis > N jours et sans
 *     lecture récente sur la même période).
 *  4. Invalider le bundle balises servi par /api/balises.
 *
 * Le timeout et la stratégie de log sur batch vide sont réglés par les
 * sous-classes.
 */
abstract class FetchBaliseReadingsJob implements ShouldQueue
{
    use Queueable;
    use TracksExecution;

    public int $tries = 2;

    /**
     * TTL du cache `last_reading_by_balise:{source}` (en secondes).
     * On évite ainsi un `GROUP BY MAX(read_at)` SQL à chaque tick — le
     * cache est de toute façon réécrit par ce même job à la fin du run.
     */
    private const LAST_READING_CACHE_TTL = 3600;

    /**
     * Code source (`pioupiou`, `metar`, `windy`…). Stocké dans
     * `balises.source` et utilisé pour scoper les requêtes.
     */
    abstract protected function source(): string;

    /**
     * Instance du provider associé à la source. Résolue paresseusement
     * via le conteneur pour ne pas obliger les sous-classes à connaître
     * Laravel.
     */
    abstract protected function provider(): BaliseProviderInterface;

    /**
     * Indique si un batch vide doit générer un warning de log. Windy
     * surcharge à `false` (cas légitime : pas de clé API configurée).
     */
    protected function warnOnEmptyBatch(): bool
    {
        return true;
    }

    protected function monitorGroup(): string
    {
        return 'balises';
    }

    public function handle(BalisesBundleCache $cache): void
    {
        $this->trackStart();
        $readings = $this->provider()->fetchLatestReadings();
        if (empty($readings)) {
            if ($this->warnOnEmptyBatch()) {
                Log::warning(static::class . ': empty readings batch');
            }
            $this->trackSuccess('Aucune lecture');
            return;
        }

        $balises = Balise::query()
            ->where('source', $this->source())
            ->where('active', true)
            ->get(['id', 'external_id'])
            ->keyBy('external_id');

        // Dernière read_at par balise pour éviter de re-insérer des doublons.
        // Cache Redis (clé par source) : à chaque cycle, on hydrate depuis
        // le cache si possible et on réécrit le map mis à jour. Évite un
        // `GROUP BY MAX(read_at)` SQL par tick.
        $cacheKey         = $this->lastReadingCacheKey();
        $latestPerBalise  = Cache::get($cacheKey);
        if (! is_array($latestPerBalise)) {
            $latestPerBalise = BaliseReading::whereIn('balise_id', $balises->pluck('id'))
                ->select('balise_id', DB::raw('MAX(read_at) as latest'))
                ->groupBy('balise_id')
                ->pluck('latest', 'balise_id')
                ->all();
        }

        $inserted = 0;
        foreach ($balises as $extId => $balise) {
            $r = $readings[$extId] ?? null;
            if (! $r || ! ($r['read_at'] ?? null)) {
                continue;
            }

            $previousLatest = $latestPerBalise[$balise->id] ?? null;
            if ($previousLatest !== null
                && $r['read_at']->lessThanOrEqualTo(Carbon::parse($previousLatest))) {
                continue; // déjà en base
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
            $latestPerBalise[$balise->id] = $r['read_at']->toDateTimeString();
        }

        // On réécrit le cache même si $inserted=0 (toutes les balises connues
        // sont incluses, ce qui hydrate la clé pour le prochain tick).
        Cache::put($cacheKey, $latestPerBalise, self::LAST_READING_CACHE_TTL);

        // Désactivation des balises mortes : actives depuis > N jours
        // et sans aucune lecture récente sur la même période.
        $deadCutoff  = Carbon::now()->subDays(BaliseConstants::DEAD_AFTER_DAYS);
        $deactivated = Balise::query()
            ->where('source', $this->source())
            ->where('active', true)
            ->where('created_at', '<', $deadCutoff)
            ->whereDoesntHave('readings', fn ($q) => $q->where('read_at', '>=', $deadCutoff))
            ->update(['active' => false]);

        // Le bundle servi par /api/balises est désormais obsolète : purge.
        $cache->forgetBundle();

        $this->trackSuccess("{$inserted} lectures insérées, {$deactivated} désactivées", ['inserted' => $inserted, 'deactivated' => $deactivated, 'polled' => $balises->count()]);

        Log::info(static::class . ' completed', [
            'readings_inserted'   => $inserted,
            'balises_deactivated' => $deactivated,
            'balises_polled'      => $balises->count(),
        ]);
    }

    public function failed(\Throwable $e): void
    {
        $this->trackFailure($e);
    }

    private function lastReadingCacheKey(): string
    {
        return 'balises.last_reading_by_balise:' . $this->source();
    }
}
