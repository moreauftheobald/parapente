<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Balise;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Backfill du `balise_readings_hourly` sur N jours, en ré-agrégeant
 * directement depuis `balise_readings`.
 *
 * Utile au déploiement initial pour ne pas attendre que la fenêtre
 * glissante de `AggregateBaliseReadingsHourlyJob` (3 h par run) ait
 * naturellement rempli les 7 jours.
 *
 * Idempotent (upsert sur clé unique `(balise_id, hour_at)`).
 */
class ReadingsBackfillHourly extends Command
{
    protected $signature = 'readings:backfill-hourly
        {--days=7 : Nombre de jours en arrière à reconstruire (max 30)}
        {--balise= : ID d\'une balise à traiter seule (sinon : toutes les actives)}';

    protected $description = 'Reconstruit balise_readings_hourly à partir de balise_readings sur N jours';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        if ($days < 1 || $days > 30) {
            $this->error('--days doit être entre 1 et 30.');
            return self::INVALID;
        }

        $baliseQ = Balise::active();
        if ($baliseId = $this->option('balise')) {
            $baliseQ = $baliseQ->where('id', (int) $baliseId);
        }
        $baliseIds = $baliseQ->pluck('id');

        if ($baliseIds->isEmpty()) {
            $this->warn('Aucune balise active à traiter.');
            return self::SUCCESS;
        }

        $end   = (clone Carbon::now())->startOfHour();
        $start = (clone $end)->subDays($days)->startOfDay();

        $totalHours = (int) $start->diffInHours($end);
        $this->info("Backfill : {$baliseIds->count()} balise(s) × {$totalHours} h ({$start->toDateTimeString()} → {$end->toDateTimeString()}).");

        $bar = $this->output->createProgressBar($totalHours);
        $bar->start();

        $totalUpserted = 0;
        $createdAt     = Carbon::now()->format('Y-m-d H:i:s');

        for ($hour = clone $start; $hour < $end; $hour = (clone $hour)->addHour()) {
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
                    DB::raw('SUM(SIN(RADIANS(wind_direction))) as sin_sum'),
                    DB::raw('SUM(COS(RADIANS(wind_direction))) as cos_sum'),
                    DB::raw('SUM(CASE WHEN wind_direction IS NOT NULL THEN 1 ELSE 0 END) as dir_count'),
                )
                ->groupBy('balise_id')
                ->get();

            $rows = [];
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

            if (! empty($rows)) {
                DB::table('balise_readings_hourly')->upsert(
                    $rows,
                    ['balise_id', 'hour_at'],
                    ['wind_direction', 'wind_speed_avg', 'wind_speed_max', 'temperature', 'readings_count', 'updated_at'],
                );
                $totalUpserted += count($rows);
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Terminé : {$totalUpserted} ligne(s) upsertée(s) dans balise_readings_hourly.");

        return self::SUCCESS;
    }

    private function circularMeanDeg(float $sinSum, float $cosSum, int $count): ?int
    {
        if ($count <= 0) {
            return null;
        }
        $deg = fmod(rad2deg(atan2($sinSum, $cosSum)) + 360.0, 360.0);
        return (int) round($deg) % 360;
    }
}
