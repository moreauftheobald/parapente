<?php

declare(strict_types=1);

namespace App\Services\Map;

use App\Services\Settings;

/**
 * Calcule la qualité d'une journée (viabilité 0-100 + statut dérivé)
 * à partir des statuts horaires dans la fenêtre solaire.
 *
 * Formule : viabilité = Σ(poids horaire × valeur du statut × facteur
 * de continuité) / Σ(poids horaire). Poids horaire en cloche centrée
 * sur peak_hour ; facteur de continuité pénalise les créneaux isolés.
 *
 * Tous les seuils (peak_hour, sigma, val_green, val_orange, run_base,
 * run_step, green_threshold, orange_threshold) sont éditables depuis
 * /admin/settings (clés `viability.*`).
 *
 * Utilisé par SiteController (endpoint /scores) et MapBundleBuilder
 * (cache pré-calculé). Logique extraite de SiteController::computeDayQuality
 * lors de l'introduction du cache map-bundle.
 */
final class DayQualityCalculator
{
    public function __construct(
        private readonly Settings $settings,
    ) {}

    /**
     * @param  array<int,string> $byHour heure (0-23) => 'green'|'orange'|'red'
     * @param  array{start_hour:int,end_hour:int} $window fenêtre solaire
     * @return array{viability:int,status:string,green_hours:int}
     */
    public function compute(array $byHour, array $window): array
    {
        $start = (int) $window['start_hour'];
        $end   = (int) $window['end_hour'];
        if ($end < $start) {
            return ['viability' => 0, 'status' => 'unknown', 'green_hours' => 0];
        }

        $peakHour  = (float) $this->settings->get('viability.peak_hour');
        $sigma     = (float) $this->settings->get('viability.sigma');
        $valGreen  = (float) $this->settings->get('viability.val_green');
        $valOrange = (float) $this->settings->get('viability.val_orange');
        $runBase   = (float) $this->settings->get('viability.run_base');
        $runStep   = (float) $this->settings->get('viability.run_step');
        $thrGreen  = (int)   $this->settings->get('viability.green_threshold');
        $thrOrange = (int)   $this->settings->get('viability.orange_threshold');

        $timeWeight = fn (int $h): float => exp(-(($h - $peakHour) ** 2) / (2 * $sigma ** 2));
        $statusVal  = fn (?string $s): float => match ($s) {
            'green'  => $valGreen,
            'orange' => $valOrange,
            default  => 0.0,
        };

        // Longueur du run de créneaux "volables" (green|orange) auquel
        // appartient chaque heure — propagé en avant puis en arrière.
        $runLen = [];
        for ($h = $start; $h <= $end; $h++) {
            $flyable = in_array($byHour[$h] ?? null, ['green', 'orange'], true);
            $runLen[$h] = $flyable ? (($runLen[$h - 1] ?? 0) + 1) : 0;
        }
        for ($h = $end - 1; $h >= $start; $h--) {
            if ($runLen[$h] > 0 && ($runLen[$h + 1] ?? 0) > $runLen[$h]) {
                $runLen[$h] = $runLen[$h + 1];
            }
        }
        $runFactor = fn (int $len): float => $len <= 0 ? 0.0 : min(1.0, $runBase + $runStep * ($len - 1));

        $num = 0.0;
        $den = 0.0;
        $greenHours = 0;
        $hasData = false;
        for ($h = $start; $h <= $end; $h++) {
            $w = $timeWeight($h);
            $den += $w;
            $st = $byHour[$h] ?? null;
            if ($st !== null) {
                $hasData = true;
            }
            if ($st === 'green') {
                $greenHours++;
            }
            $num += $w * $statusVal($st) * $runFactor($runLen[$h]);
        }

        $viability = $den > 0.0 ? (int) round(100 * $num / $den) : 0;
        $status = ! $hasData
            ? 'unknown'
            : ($viability >= $thrGreen
                ? 'green'
                : ($viability >= $thrOrange ? 'orange' : 'red'));

        return ['viability' => $viability, 'status' => $status, 'green_hours' => $greenHours];
    }
}
