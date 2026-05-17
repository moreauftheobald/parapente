<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Balise;
use App\Models\Site;
use App\Services\Geocoding\BanReverseGeocoder;
use App\Services\Geocoding\LocationResult;
use App\Services\Geocoding\NominatimReverseGeocoder;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Géocode en masse les sites et balises (pays / région / département)
 * via BAN (batch CSV pour la France) + Nominatim en fallback (étranger).
 *
 * Idempotent : sans `--force`, ne traite que les entrées dont
 * `geocoded_at IS NULL` — relançable à volonté sans casse.
 *
 * Usage :
 *   php artisan geocode:locations                      # sites + balises non géocodés
 *   php artisan geocode:locations --sites              # uniquement sites
 *   php artisan geocode:locations --balises --force    # re-géocode toutes les balises
 *   php artisan geocode:locations --chunk=50           # batches BAN plus petits
 *   php artisan geocode:locations --limit=10           # test sur 10 entrées
 *
 * Cf. FF_location_enrichment.md.
 */
class GeocodeLocations extends Command
{
    protected $signature = 'geocode:locations
        {--sites    : ne traite que les sites}
        {--balises  : ne traite que les balises}
        {--force    : re-géocode même les entrées déjà géocodées}
        {--chunk=100 : taille des batches BAN CSV (max 1000)}
        {--no-batch : force le mode point-par-point (debug, BAN down)}
        {--limit=  : limite le nombre d\'entrées traitées (test)}';

    protected $description = 'Géocode en masse les sites et balises (pays, région, département).';

    public function handle(BanReverseGeocoder $ban, NominatimReverseGeocoder $nominatim): int
    {
        $onlySites   = (bool) $this->option('sites');
        $onlyBalises = (bool) $this->option('balises');
        $targets = match (true) {
            $onlySites && ! $onlyBalises => [Site::class],
            $onlyBalises && ! $onlySites => [Balise::class],
            default                      => [Site::class, Balise::class],
        };

        $force    = (bool) $this->option('force');
        $chunk    = max(1, min(1000, (int) $this->option('chunk')));
        $noBatch  = (bool) $this->option('no-batch');
        $limitRaw = $this->option('limit');
        $limit    = $limitRaw !== null ? max(1, (int) $limitRaw) : null;

        $totalBan = 0;
        $totalNom = 0;
        $totalFail = 0;

        foreach ($targets as $class) {
            $label = $class === Site::class ? 'sites' : 'balises';
            $query = $this->buildQuery($class, $force);

            $count = (clone $query)->count();
            if ($limit !== null) {
                $count = min($count, $limit);
            }

            if ($count === 0) {
                $this->info("  ✓ {$label} : rien à géocoder.");
                continue;
            }

            $this->info(sprintf('▶ %s : %d entrée(s) à géocoder.', ucfirst($label), $count));
            $bar = $this->output->createProgressBar($count);
            $bar->start();

            [$banHits, $nomHits, $failures] = $this->process(
                $query, $limit, $chunk, $noBatch, $ban, $nominatim,
                fn () => $bar->advance(),
            );

            $bar->finish();
            $this->newLine();
            $this->line(sprintf(
                '  ✓ %s : %d via BAN, %d via Nominatim, %d échec(s).',
                $label, $banHits, $nomHits, $failures
            ));

            $totalBan  += $banHits;
            $totalNom  += $nomHits;
            $totalFail += $failures;
        }

        $this->newLine();
        $this->info(sprintf(
            'TOTAL : %d géocodés (BAN: %d, Nominatim: %d) — %d échec(s).',
            $totalBan + $totalNom, $totalBan, $totalNom, $totalFail
        ));

        return self::SUCCESS;
    }

    /**
     * @return array{0:int,1:int,2:int} [banHits, nominatimHits, failures]
     */
    private function process(
        Builder $query,
        ?int $limit,
        int $chunk,
        bool $noBatch,
        BanReverseGeocoder $ban,
        NominatimReverseGeocoder $nominatim,
        \Closure $onProcessed,
    ): array {
        $banHits = $nomHits = $failures = 0;
        $processed = 0;

        // On itère par chunks de la query — on ne charge pas tout en mémoire.
        $query->orderBy('id')->chunkById($chunk, function (Collection $batch) use (
            &$banHits, &$nomHits, &$failures, &$processed,
            $limit, $noBatch, $ban, $nominatim, $onProcessed,
        ) {
            // Tronque au limit si besoin
            if ($limit !== null && $processed + $batch->count() > $limit) {
                $batch = $batch->take($limit - $processed);
            }
            if ($batch->isEmpty()) {
                return false;
            }

            $banResults = [];
            if (! $noBatch) {
                $points = $batch->map(fn (Model $m) => [
                    'key' => (string) $m->getKey(),
                    'lat' => (float) $m->getAttribute('latitude'),
                    'lng' => (float) $m->getAttribute('longitude'),
                ])->all();

                try {
                    $banResults = $ban->reverseBatch($points);
                } catch (Throwable $e) {
                    $this->warn('  ! BAN batch a échoué, fallback point-par-point : ' . $e->getMessage());
                    $banResults = [];
                }
            }

            foreach ($batch as $model) {
                $key = (string) $model->getKey();
                $result = $banResults[$key] ?? null;

                if ($result === null) {
                    // Pas dans BAN (étranger ou échec batch) → Nominatim
                    $result = $nominatim->reverse(
                        (float) $model->getAttribute('latitude'),
                        (float) $model->getAttribute('longitude'),
                    );
                }

                if ($result === null) {
                    $failures++;
                } else {
                    $this->persist($model, $result);
                    if ($result->provider === 'ban') $banHits++;
                    else                              $nomHits++;
                }

                $processed++;
                $onProcessed();
            }

            if ($limit !== null && $processed >= $limit) {
                return false; // arrêt anticipé du chunkById
            }
            return true;
        });

        return [$banHits, $nomHits, $failures];
    }

    private function persist(Model $model, LocationResult $r): void
    {
        $model->forceFill([
            'country_code'      => $r->countryCode,
            'country'           => $r->country,
            'admin_region'      => $r->adminRegion,
            'department'        => $r->department,
            'geocoded_provider' => $r->provider,
            'geocoded_at'       => now(),
        ])->saveQuietly(); // saveQuietly → ne réveille pas l'observer (évite boucle infinie)
    }

    /**
     * @param  class-string<Model> $class
     */
    private function buildQuery(string $class, bool $force): Builder
    {
        /** @var Builder $q */
        $q = $class::query()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude');

        if (! $force) {
            $q->whereNull('geocoded_at');
        }

        return $q;
    }
}
