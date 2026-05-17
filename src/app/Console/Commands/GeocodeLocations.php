<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Balise;
use App\Models\Site;
use App\Services\Geocoding\HybridReverseGeocoder;
use App\Services\Geocoding\LocationResult;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Géocode en masse les sites et balises (pays / région / département)
 * via geo.api.gouv.fr (FR par point-in-polygon) + Nominatim en
 * fallback (étranger, 1 req/s).
 *
 * Idempotent : sans `--force`, ne traite que les entrées dont
 * `geocoded_at IS NULL` — relançable à volonté sans casse.
 *
 * Usage :
 *   php artisan geocode:locations                      # sites + balises non géocodés
 *   php artisan geocode:locations --sites              # uniquement sites
 *   php artisan geocode:locations --balises --force    # re-géocode toutes les balises
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
        {--chunk=200 : taille des chunks d\'itération DB}
        {--limit=   : limite le nombre d\'entrées traitées (test)}';

    protected $description = 'Géocode en masse les sites et balises (pays, région, département).';

    public function handle(HybridReverseGeocoder $geocoder): int
    {
        $onlySites   = (bool) $this->option('sites');
        $onlyBalises = (bool) $this->option('balises');
        $targets = match (true) {
            $onlySites && ! $onlyBalises => [Site::class],
            $onlyBalises && ! $onlySites => [Balise::class],
            default                      => [Site::class, Balise::class],
        };

        $force    = (bool) $this->option('force');
        $chunk    = max(1, (int) $this->option('chunk'));
        $limitRaw = $this->option('limit');
        $limit    = $limitRaw !== null ? max(1, (int) $limitRaw) : null;

        $totalGeo = 0;
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

            [$geoHits, $nomHits, $failures] = $this->process(
                $query, $limit, $chunk, $geocoder,
                fn () => $bar->advance(),
            );

            $bar->finish();
            $this->newLine();
            $this->line(sprintf(
                '  ✓ %s : %d via geo.api.gouv, %d via Nominatim, %d échec(s).',
                $label, $geoHits, $nomHits, $failures
            ));

            $totalGeo  += $geoHits;
            $totalNom  += $nomHits;
            $totalFail += $failures;
        }

        $this->newLine();
        $this->info(sprintf(
            'TOTAL : %d géocodés (geo.api.gouv: %d, Nominatim: %d) — %d échec(s).',
            $totalGeo + $totalNom, $totalGeo, $totalNom, $totalFail
        ));

        return self::SUCCESS;
    }

    /**
     * @return array{0:int,1:int,2:int} [geoApiGouvHits, nominatimHits, failures]
     */
    private function process(
        Builder $query,
        ?int $limit,
        int $chunk,
        HybridReverseGeocoder $geocoder,
        \Closure $onProcessed,
    ): array {
        $geoHits = $nomHits = $failures = 0;
        $processed = 0;

        $query->orderBy('id')->chunkById($chunk, function (Collection $batch) use (
            &$geoHits, &$nomHits, &$failures, &$processed,
            $limit, $geocoder, $onProcessed,
        ) {
            if ($limit !== null && $processed + $batch->count() > $limit) {
                $batch = $batch->take($limit - $processed);
            }
            if ($batch->isEmpty()) {
                return false;
            }

            foreach ($batch as $model) {
                $lat = (float) $model->getAttribute('latitude');
                $lng = (float) $model->getAttribute('longitude');

                $result = $geocoder->reverse($lat, $lng);

                if ($result === null) {
                    $failures++;
                } else {
                    $this->persist($model, $result);
                    if ($result->provider === 'geo-api-gouv') $geoHits++;
                    else                                       $nomHits++;
                }

                $processed++;
                $onProcessed();
            }

            if ($limit !== null && $processed >= $limit) {
                return false;
            }
            return true;
        });

        return [$geoHits, $nomHits, $failures];
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
        ])->saveQuietly(); // saveQuietly → ne réveille pas l'observer
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
