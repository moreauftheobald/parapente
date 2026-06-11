<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\TracksExecution;
use App\Services\DataCoverage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Préchauffe le cache de l'écran « Data / couverture » : recalcule en
 * arrière-plan les sections lourdes (grilles + barres de rétention)
 * pour que l'affichage ne paie jamais l'agrégation des grosses tables
 * (forecast_archive_* ≈ millions de lignes).
 *
 * flush() d'abord, puis rebuild : le cache est ainsi toujours chaud ET
 * jamais plus vieux qu'un run. Schedulé toutes les heures à :24, après
 * les fetches de :00/:15 (cf. routes/console.php).
 */
class WarmDataCoverageJob implements ShouldQueue
{
    use Queueable;
    use TracksExecution;

    public int $timeout = 600;
    public int $tries   = 1;

    protected function monitorGroup(): string
    {
        return 'systeme';
    }

    public function handle(DataCoverage $coverage): void
    {
        $this->trackStart();
        $start = microtime(true);

        $coverage->flush();

        // Chaque appel reconstruit sa section et la met en cache.
        $coverage->modelFreshness();
        $coverage->siteForecastCoverage();
        $coverage->baliseForecastCoverage();
        $coverage->baliseReadingsCoverage();
        $coverage->stationForecastCoverage();
        $coverage->stationReadingsCoverage();
        $coverage->baliseRetentionBars();
        $coverage->stationRetentionBars();

        $elapsed = round(microtime(true) - $start, 1);
        $this->trackSuccess("Cache couverture reconstruit en {$elapsed}s", ['elapsed_s' => $elapsed]);

        Log::info('WarmDataCoverageJob completed', ['elapsed_s' => $elapsed]);
    }

    public function failed(\Throwable $e): void
    {
        $this->trackFailure($e);
    }
}
