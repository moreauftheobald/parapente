<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Site;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class FetchForecastsJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 60;
    public int $tries   = 3;

    public function handle(): void
    {
        $sites = Site::active()->with('conditions')->get();

        if ($sites->isEmpty()) {
            Log::info('FetchForecastsJob: aucun site actif trouvé.');
            return;
        }

        Log::info("FetchForecastsJob: dispatch pour {$sites->count()} site(s).");

        foreach ($sites as $site) {
            // On ne fetch pas les sites sans profil de conditions
            if (! $site->conditions) {
                Log::warning("FetchForecastsJob: site {$site->slug} sans conditions, ignoré.");
                continue;
            }

            // Un job par site — traitement parallèle par le worker
            FetchSiteForecastsJob::dispatch($site->id)
                ->onQueue('meteo');
        }
    }
}
