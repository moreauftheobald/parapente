<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\AggregateStationObservationsHourlyJob;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Backfill de `weather_station_observations_hourly` sur N jours, en
 * ré-agrégeant directement depuis `weather_station_observations`.
 *
 * À lancer une fois au déploiement de la table horaire, AVANT que la
 * purge quotidienne ne ramène les observations brutes à 7 jours —
 * sinon l'historique au-delà de 7 j est perdu pour la fiabilité.
 *
 * Idempotent (upsert sur weather_station_id + hour_at).
 */
class StationObservationsBackfillHourly extends Command
{
    protected $signature = 'stations:backfill-hourly
        {--days=30 : Nombre de jours en arrière à reconstruire (max 30)}';

    protected $description = 'Reconstruit weather_station_observations_hourly à partir des observations brutes sur N jours';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        if ($days < 1 || $days > 30) {
            $this->error('--days doit être entre 1 et 30.');
            return self::FAILURE;
        }

        $end   = Carbon::now()->startOfHour();
        $start = (clone $end)->subDays($days)->startOfDay();

        $this->info("Backfill horaire stations : {$start->toDateTimeString()} → {$end->toDateTimeString()}");

        $total = 0;
        // Jour par jour pour borner la mémoire et donner du feedback.
        for ($dayStart = (clone $start); $dayStart < $end; $dayStart = (clone $dayStart)->addDay()) {
            $dayEnd = min((clone $dayStart)->addDay(), $end);
            $upserted = AggregateStationObservationsHourlyJob::aggregateRange($dayStart, $dayEnd);
            $total += $upserted;
            $this->line("  {$dayStart->format('Y-m-d')} : {$upserted} ligne(s)");
        }

        $this->info("Terminé : {$total} ligne(s) upsertée(s) dans weather_station_observations_hourly.");

        return self::SUCCESS;
    }
}
