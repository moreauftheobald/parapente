<?php

declare(strict_types=1);

namespace App\Services\Map;

/**
 * Calcule la fenêtre de vol solaire pour une coordonnée et un jour.
 *
 * - Début : lever du soleil − 30 min, floor à l'heure (06:40 → 6)
 * - Fin   : coucher du soleil + 30 min, ceil à l'heure (18:50 → 19)
 *
 * Utilisé par :
 *  - SiteController (endpoints /scores et /multimodel)
 *  - MapBundleBuilder (cache pré-calculé des markers carte)
 *
 * Logique extraite de SiteController::getSunWindow() lors de l'introduction
 * du cache map-bundle. Stateless, donc statique pour rester DI-free.
 */
final class SunWindowCalculator
{
    /**
     * @param  string $day  format `d/m`
     * @return array{sunrise_display:string,sunset_display:string,start_hour:int,end_hour:int}
     */
    public static function compute(float $lat, float $lng, string $day): array
    {
        $tz      = new \DateTimeZone('Europe/Paris');
        [$d, $m] = explode('/', $day);
        $ts      = \Carbon\Carbon::createFromDate((int) date('Y'), (int) $m, (int) $d, $tz)->setTime(12, 0)->getTimestamp();

        $sunriseTs = date_sunrise($ts, SUNFUNCS_RET_TIMESTAMP, $lat, $lng, 90.833);
        $sunsetTs  = date_sunset($ts,  SUNFUNCS_RET_TIMESTAMP, $lat, $lng, 90.833);

        $startDt   = (new \DateTime('@' . ($sunriseTs - 1800)))->setTimezone($tz);
        $startHour = (int) $startDt->format('G');

        $endDt   = (new \DateTime('@' . ($sunsetTs + 1800)))->setTimezone($tz);
        $endHour = (int) $endDt->format('G');
        if ((int) $endDt->format('i') > 0) $endHour = min(23, $endHour + 1);

        return [
            'sunrise_display' => (new \DateTime('@' . $sunriseTs))->setTimezone($tz)->format('H:i'),
            'sunset_display'  => (new \DateTime('@' . $sunsetTs))->setTimezone($tz)->format('H:i'),
            'start_hour'      => $startHour,
            'end_hour'        => $endHour,
        ];
    }
}
