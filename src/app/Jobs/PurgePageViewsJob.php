<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\PageView;
use App\Services\Settings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Purge les vieilles entrées de page_views au-delà de la rétention
 * configurée (setting `pageviews.retention_days`, défaut 365 j).
 *
 * Schedulé quotidiennement (cf. routes/console.php), s'exécute en
 * lots de 5 000 lignes pour ne pas bloquer la base.
 */
class PurgePageViewsJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;
    public int $tries   = 1;

    private const CHUNK_SIZE = 5000;

    public function handle(Settings $settings): void
    {
        $retentionDays = max(1, (int) $settings->get('pageviews.retention_days', 365));
        $cutoff = now()->subDays($retentionDays);

        $totalDeleted = 0;
        do {
            $deleted = PageView::where('visited_at', '<', $cutoff)
                ->limit(self::CHUNK_SIZE)
                ->delete();
            $totalDeleted += $deleted;
        } while ($deleted === self::CHUNK_SIZE);

        Log::info('PurgePageViewsJob completed', [
            'retention_days' => $retentionDays,
            'cutoff'         => $cutoff->toDateTimeString(),
            'deleted'        => $totalDeleted,
        ]);
    }
}
