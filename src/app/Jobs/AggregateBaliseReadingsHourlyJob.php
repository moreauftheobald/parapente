<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\DataCoverage;
use App\Jobs\Concerns\TracksExecution;
use App\Models\Balise;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Agrège les lectures `balise_readings` en buckets horaires alignés sur
 * l'heure pile, pour permettre une comparaison directe avec les prévisions
 * archivées dans `forecast_archive_balises`.
 *
 * - direction : moyenne circulaire via SUM(SIN)/SUM(COS) puis atan2
 * - vitesse moyenne / température : AVG
 * - rafale : MAX des `wind_speed_max` de l'heure
 *
 * Fenêtre glissante de WINDOW_HOURS heures (re-agrège les dernières heures
 * pour capter les lectures arrivées en retard depuis le dernier run).
 * Idempotent via upsert sur (balise_id, hour_at).
 *
 * Schedulé toutes les heures à :05 (cf. routes/console.php), pour laisser
 * le polling PiouPiou/METAR finir son tour à :00 / :30.
 */
class AggregateBaliseReadingsHourlyJob implements ShouldQueue
{
    use Queueable;
    use TracksExecution;

    public int $timeout = 120;
    public int $tries   = 2;

    /** Nombre d'heures pleines en arrière à re-agréger à chaque run. */
    private const WINDOW_HOURS = 3;

    protected function monitorGroup(): string
    {
        return 'balises';
    }

    public function handle(): void
    {
        $this->trackStart();
        $now      = Carbon::now();
        $endHour  = (clone $now)->startOfHour();                       // heure courante exclue (incomplète)
        $startHour = (clone $endHour)->subHours(self::WINDOW_HOURS);

        $baliseIds = Balise::active()->pluck('id');
        if ($baliseIds->isEmpty()) {
            Log::info('AggregateBaliseReadingsHourlyJob: no active balise');
            $this->trackSuccess('Aucune balise active');
            return;
        }

        $rows      = [];
        $createdAt = $now->format('Y-m-d H:i:s');

        for ($hour = clone $startHour; $hour < $endHour; $hour = (clone $hour)->addHour()) {
            $hourEnd = (clone $hour)->addHour();

            $aggregates = DB::table('balise_readings')
                ->whereIn('balise_id', $baliseIds)
                ->where('read_at', '>=', $hour->format('Y-m-d H:i:s'))
                ->where('read_at', '<',  $hourEnd->format('Y-m-d H:i:s'))
                ->select(
                    'balise_id',
                    DB::raw('COUNT(*) as readings_count'),
                    DB::raw('AVG(wind_speed_avg) as wind_speed_avg'),
                    DB::raw('MAX(wind_speed_max) as wind_speed_max'),
                    DB::raw('AVG(temperature)    as temperature'),
                    // moyenne circulaire de la direction : on accumule sin/cos
                    DB::raw('SUM(SIN(RADIANS(wind_direction))) as sin_sum'),
                    DB::raw('SUM(COS(RADIANS(wind_direction))) as cos_sum'),
                    DB::raw('SUM(CASE WHEN wind_direction IS NOT NULL THEN 1 ELSE 0 END) as dir_count'),
                )
                ->groupBy('balise_id')
                ->get();

            foreach ($aggregates as $agg) {
                $rows[] = [
                    'balise_id'      => (int) $agg->balise_id,
                    'hour_at'        => $hour->format('Y-m-d H:i:s'),
                    'wind_direction' => $this->circularMeanDeg(
                        (float) $agg->sin_sum,
                        (float) $agg->cos_sum,
                        (int)   $agg->dir_count,
                    ),
                    'wind_speed_avg' => $agg->wind_speed_avg !== null ? round((float) $agg->wind_speed_avg, 1) : null,
                    'wind_speed_max' => $agg->wind_speed_max !== null ? round((float) $agg->wind_speed_max, 1) : null,
                    'temperature'    => $agg->temperature    !== null ? round((float) $agg->temperature,    1) : null,
                    'readings_count' => (int) $agg->readings_count,
                    'created_at'     => $createdAt,
                    'updated_at'     => $createdAt,
                ];
            }
        }

        if (empty($rows)) {
            Log::info('AggregateBaliseReadingsHourlyJob: no readings in window', [
                'window_start' => $startHour->toDateTimeString(),
                'window_end'   => $endHour->toDateTimeString(),
            ]);
            $this->trackSuccess('Aucune lecture dans la fenêtre');
            return;
        }

        $upserted = 0;
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('balise_readings_hourly')->upsert(
                $chunk,
                ['balise_id', 'hour_at'],
                ['wind_direction', 'wind_speed_avg', 'wind_speed_max', 'temperature', 'readings_count', 'updated_at'],
            );
            $upserted += count($chunk);
        }

        DataCoverage::forgetBars('balises');
        $this->trackSuccess("{$upserted} lignes agrégées sur {$baliseIds->count()} balises", ['rows_upserted' => $upserted, 'balises' => $baliseIds->count()]);

        Log::info('AggregateBaliseReadingsHourlyJob completed', [
            'window_start'   => $startHour->toDateTimeString(),
            'window_end'     => $endHour->toDateTimeString(),
            'balises_count'  => $baliseIds->count(),
            'rows_upserted'  => $upserted,
        ]);
    }

    public function failed(\Throwable $e): void
    {
        $this->trackFailure($e);
    }

    /**
     * Moyenne circulaire en degrés à partir des sommes sin/cos issues
     * de l'agrégation SQL. Retourne null si aucune direction valide.
     */
    private function circularMeanDeg(float $sinSum, float $cosSum, int $count): ?int
    {
        if ($count <= 0) {
            return null;
        }
        $rad = atan2($sinSum, $cosSum);
        $deg = rad2deg($rad);
        // ramène dans [0, 360[
        $deg = fmod($deg + 360.0, 360.0);
        return (int) round($deg) % 360;
    }
}
