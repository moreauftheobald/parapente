<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\TracksExecution;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Agrège les observations `weather_station_observations` en buckets
 * horaires alignés sur l'heure pile, dans
 * `weather_station_observations_hourly` — pendant stations de
 * AggregateBaliseReadingsHourlyJob.
 *
 * - direction : moyenne circulaire via SUM(SIN)/SUM(COS) puis atan2
 * - vitesse moyenne / température / dew point / humidité / pression /
 *   couverture nuageuse : AVG
 * - rafale : MAX des `wind_speed_max` de l'heure
 * - précipitations : SUM (les réseaux infrahoraires publient des
 *   tranches — 6 min MF — dont la somme donne le cumul horaire)
 *
 * Fenêtre glissante de WINDOW_HOURS heures (re-agrège les dernières
 * heures pour capter les observations arrivées en retard). Idempotent
 * via upsert sur (weather_station_id, hour_at).
 *
 * Schedulé toutes les heures à :07 (cf. routes/console.php), après
 * l'agrégation balises à :05.
 */
class AggregateStationObservationsHourlyJob implements ShouldQueue
{
    use Queueable;
    use TracksExecution;

    public int $timeout = 300;
    public int $tries   = 2;

    /** Nombre d'heures pleines en arrière à re-agréger à chaque run. */
    private const WINDOW_HOURS = 3;

    protected function monitorGroup(): string
    {
        return 'stations';
    }

    public function handle(): void
    {
        $this->trackStart();
        $endHour   = Carbon::now()->startOfHour();   // heure courante exclue (incomplète)
        $startHour = (clone $endHour)->subHours(self::WINDOW_HOURS);

        $upserted = self::aggregateRange($startHour, $endHour);

        $this->trackSuccess("{$upserted} lignes agrégées", ['rows_upserted' => $upserted]);

        Log::info('AggregateStationObservationsHourlyJob completed', [
            'window_start'  => $startHour->toDateTimeString(),
            'window_end'    => $endHour->toDateTimeString(),
            'rows_upserted' => $upserted,
        ]);
    }

    public function failed(\Throwable $e): void
    {
        $this->trackFailure($e);
    }

    /**
     * Agrège [startHour, endHour) heure par heure et upsert dans
     * weather_station_observations_hourly. Statique pour être
     * réutilisable par la commande de backfill
     * (`stations:backfill-hourly`). Retourne le nombre de lignes
     * upsertées.
     */
    public static function aggregateRange(Carbon $startHour, Carbon $endHour): int
    {
        $createdAt = Carbon::now()->format('Y-m-d H:i:s');
        $upserted  = 0;

        for ($hour = (clone $startHour); $hour < $endHour; $hour = (clone $hour)->addHour()) {
            $hourEnd = (clone $hour)->addHour();

            $aggregates = DB::table('weather_station_observations')
                ->where('observed_at', '>=', $hour->format('Y-m-d H:i:s'))
                ->where('observed_at', '<',  $hourEnd->format('Y-m-d H:i:s'))
                ->select(
                    'weather_station_id',
                    DB::raw('COUNT(*) as obs_count'),
                    DB::raw('AVG(wind_speed_avg)  as wind_speed_avg'),
                    DB::raw('MAX(wind_speed_max)  as wind_speed_max'),
                    DB::raw('AVG(temperature)     as temperature'),
                    DB::raw('AVG(dew_point)       as dew_point'),
                    DB::raw('AVG(humidity)        as humidity'),
                    DB::raw('AVG(pressure_hpa)    as pressure_hpa'),
                    DB::raw('SUM(precipitation_mm) as precipitation_mm'),
                    DB::raw('AVG(cloud_cover_pct) as cloud_cover_pct'),
                    // moyenne circulaire de la direction : on accumule sin/cos
                    DB::raw('SUM(SIN(RADIANS(wind_direction))) as sin_sum'),
                    DB::raw('SUM(COS(RADIANS(wind_direction))) as cos_sum'),
                    DB::raw('SUM(CASE WHEN wind_direction IS NOT NULL THEN 1 ELSE 0 END) as dir_count'),
                )
                ->groupBy('weather_station_id')
                ->get();

            $rows = [];
            foreach ($aggregates as $agg) {
                $rows[] = [
                    'weather_station_id' => (int) $agg->weather_station_id,
                    'hour_at'            => $hour->format('Y-m-d H:i:s'),
                    'wind_direction'     => self::circularMeanDeg(
                        (float) $agg->sin_sum,
                        (float) $agg->cos_sum,
                        (int)   $agg->dir_count,
                    ),
                    'wind_speed_avg'     => $agg->wind_speed_avg   !== null ? round((float) $agg->wind_speed_avg, 1)   : null,
                    'wind_speed_max'     => $agg->wind_speed_max   !== null ? round((float) $agg->wind_speed_max, 1)   : null,
                    'temperature'        => $agg->temperature      !== null ? round((float) $agg->temperature, 1)      : null,
                    'dew_point'          => $agg->dew_point        !== null ? round((float) $agg->dew_point, 1)        : null,
                    'humidity'           => $agg->humidity         !== null ? (int) round((float) $agg->humidity)      : null,
                    'pressure_hpa'       => $agg->pressure_hpa     !== null ? round((float) $agg->pressure_hpa, 1)     : null,
                    'precipitation_mm'   => $agg->precipitation_mm !== null ? round((float) $agg->precipitation_mm, 2) : null,
                    'cloud_cover_pct'    => $agg->cloud_cover_pct  !== null ? (int) round((float) $agg->cloud_cover_pct) : null,
                    'obs_count'          => (int) $agg->obs_count,
                    'created_at'         => $createdAt,
                    'updated_at'         => $createdAt,
                ];
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('weather_station_observations_hourly')->upsert(
                    $chunk,
                    ['weather_station_id', 'hour_at'],
                    [
                        'wind_direction', 'wind_speed_avg', 'wind_speed_max',
                        'temperature', 'dew_point', 'humidity', 'pressure_hpa',
                        'precipitation_mm', 'cloud_cover_pct', 'obs_count',
                        'updated_at',
                    ],
                );
                $upserted += count($chunk);
            }
        }

        return $upserted;
    }

    /**
     * Moyenne circulaire en degrés à partir des sommes sin/cos issues
     * de l'agrégation SQL. Retourne null si aucune direction valide.
     */
    private static function circularMeanDeg(float $sinSum, float $cosSum, int $count): ?int
    {
        if ($count <= 0) {
            return null;
        }
        $deg = fmod(rad2deg(atan2($sinSum, $cosSum)) + 360.0, 360.0);
        return (int) round($deg) % 360;
    }
}
