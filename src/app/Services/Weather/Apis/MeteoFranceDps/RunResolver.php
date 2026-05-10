<?php

declare(strict_types=1);

namespace App\Services\Weather\Apis\MeteoFranceDps;

use Carbon\CarbonImmutable;

/**
 * Détermine les runs MF candidats à partir de l'horloge UTC.
 *
 * MF publie ses runs avec un délai de quelques heures après l'heure de
 * référence. On part du run le plus récent **a priori publié** (now −
 * delay snappé sur le pas du modèle) puis on liste les N précédents en
 * fallback (pour couvrir les retards de publication).
 */
final class RunResolver
{
    /**
     * @return list<CarbonImmutable> candidats, le plus récent d'abord.
     */
    public static function candidates(int $runHours, int $delayHours, int $fallbacks = 2): array
    {
        $nowUtc = CarbonImmutable::now('UTC');
        $effective = $nowUtc->subHours($delayHours);

        $hour = $effective->hour;
        $snappedHour = intdiv($hour, $runHours) * $runHours;

        $latest = $effective
            ->setTime($snappedHour, 0, 0)
            ->setSecond(0)
            ->setMicrosecond(0);

        $candidates = [$latest];
        for ($i = 1; $i <= $fallbacks; $i++) {
            $candidates[] = $latest->subHours($runHours * $i);
        }
        return $candidates;
    }

    /** Format ISO Z attendu par DPS : 2026-05-10T06:00:00Z */
    public static function formatForDps(CarbonImmutable $run): string
    {
        return $run->format('Y-m-d\TH:i:s\Z');
    }

    /** Identifiant court pour le cache fichier. */
    public static function formatForPath(CarbonImmutable $run): string
    {
        return $run->format('Ymd-Hi');
    }
}
