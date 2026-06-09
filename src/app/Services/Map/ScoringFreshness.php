<?php

declare(strict_types=1);

namespace App\Services\Map;

use App\Services\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Log;

/**
 * Watchdog de fraîcheur du scoring.
 *
 * Le scoring est produit par le sidecar `consensus-grid-v2`, qui flippe le
 * buffer `scoring_table` à chaque run. Si le worker du sidecar se fige
 * (hang), aucun nouveau run n'arrive et les scores se figent **en
 * silence**. Ce service détecte cette péremption à partir de l'horloge de
 * Laravel — on mémorise l'instant du dernier flip observé
 * (`WatchScoringTableJob`) et on compare à `now()` (les deux dans le même
 * référentiel → aucune ambiguïté de timezone, contrairement à un
 * `computed_at` lu depuis le sidecar).
 *
 * Au passage frais → périmé : `Log::error` (one-shot). Le statut est
 * exposé au front (bandeau carte) via `MapBundleController`.
 */
final class ScoringFreshness
{
    /** Unix timestamp du dernier run détecté (= dernier flip du buffer). */
    public const LAST_RUN_KEY = 'scoring.last_run_at';

    /** Statut de fraîcheur calculé (array primitif, lu par le front/admin). */
    public const STATUS_KEY = 'scoring.freshness';

    public const DEFAULT_THRESHOLD_MIN = 75;

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly Settings $settings,
    ) {}

    /**
     * À appeler au flip du buffer (un flip = un nouveau run sidecar).
     */
    public function recordRun(): void
    {
        $this->cache->forever(self::LAST_RUN_KEY, now()->getTimestamp());
    }

    /**
     * Évalue la fraîcheur (appelé chaque minute par WatchScoringTableJob),
     * met à jour le flag de statut et logue les transitions.
     *
     * @return array<string,mixed>
     */
    public function evaluate(): array
    {
        $lastRun = $this->cache->get(self::LAST_RUN_KEY);
        if ($lastRun === null) {
            // Bootstrap (1er tick après déploiement) : on amorce l'horloge,
            // pas d'alerte immédiate. Le 1er vrai flip la recalera.
            $this->recordRun();
            $lastRun = now()->getTimestamp();
        }

        $threshold = (int) $this->settings->get('scoring.stale_after_minutes', self::DEFAULT_THRESHOLD_MIN);
        $ageMin    = (int) floor((now()->getTimestamp() - (int) $lastRun) / 60);
        $isStale   = $ageMin >= $threshold;

        $prev     = $this->cache->get(self::STATUS_KEY);
        $wasStale = is_array($prev) && ($prev['stale'] ?? false);

        $status = [
            'stale'             => $isStale,
            'age_minutes'       => $ageMin,
            'threshold_minutes' => $threshold,
            'last_run_at'       => CarbonImmutable::createFromTimestamp((int) $lastRun)->toIso8601String(),
            'checked_at'        => now()->toIso8601String(),
            'stale_since'       => null,
        ];

        if ($isStale) {
            $status['stale_since'] = $wasStale
                ? ($prev['stale_since'] ?? now()->toIso8601String())
                : now()->toIso8601String();

            if (! $wasStale) {
                Log::error(
                    "ScoringFreshness: SCORING PÉRIMÉ — aucun run sidecar depuis {$ageMin} min "
                    . "(seuil {$threshold} min). Le worker consensus-grid-v2 a probablement cessé de produire des runs."
                );
            }
        } elseif ($wasStale) {
            Log::info("ScoringFreshness: scoring de nouveau frais (âge {$ageMin} min).");
        }

        $this->cache->forever(self::STATUS_KEY, $status);

        return $status;
    }

    /**
     * Lecture du statut courant (cheap, sans calcul) pour le servir au
     * front. Renvoie un statut « frais » par défaut si rien n'a encore été
     * évalué.
     *
     * @return array<string,mixed>
     */
    public function status(): array
    {
        $s = $this->cache->get(self::STATUS_KEY);

        return is_array($s) ? $s : ['stale' => false, 'age_minutes' => null];
    }
}
