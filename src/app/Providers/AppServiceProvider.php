<?php

namespace App\Providers;

use App\Models\Balise;
use App\Models\Site;
use App\Observers\GeocodableObserver;
use App\Services\Geocoding\GeoApiGouvReverseGeocoder;
use App\Services\Geocoding\HybridReverseGeocoder;
use App\Services\Geocoding\NominatimReverseGeocoder;
use App\Services\Geocoding\ReverseGeocoderInterface;
use App\Services\Settings;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Paramètres globaux : un seul Service par requête (cache mémoire
        // implicite côté service via Cache::remember()).
        $this->app->singleton(Settings::class);

        // ── Reverse geocoding (geo.api.gouv + Nominatim) ───────────
        $this->app->singleton(GeoApiGouvReverseGeocoder::class, function ($app) {
            $cfg = $app['config']->get('services.geocoding.geo_api_gouv');
            return new GeoApiGouvReverseGeocoder(
                $app->make(HttpFactory::class),
                rtrim((string) $cfg['base_url'], '/'),
                (int) $cfg['timeout'],
            );
        });

        $this->app->singleton(NominatimReverseGeocoder::class, function ($app) {
            $cfg = $app['config']->get('services.geocoding.nominatim');
            return new NominatimReverseGeocoder(
                $app->make(HttpFactory::class),
                $app->make(CacheRepository::class),
                rtrim((string) $cfg['base_url'], '/'),
                (string) $cfg['user_agent'],
                (string) $cfg['contact_email'],
                (int) $cfg['timeout'],
                (int) $cfg['rate_limit_seconds'],
            );
        });

        $this->app->singleton(HybridReverseGeocoder::class);
        $this->app->bind(ReverseGeocoderInterface::class, HybridReverseGeocoder::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Géocodage automatique des sites/balises à la création ou à
        // la mise à jour des coordonnées (cf. FF_location_enrichment.md).
        Site::observe(GeocodableObserver::class);
        Balise::observe(GeocodableObserver::class);
    }
}
