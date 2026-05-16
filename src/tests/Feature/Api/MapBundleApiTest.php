<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Site;
use App\Models\SiteCondition;
use App\Models\SiteScore;
use App\Models\User;
use App\Models\UserSiteCondition;
use App\Services\Map\MapBundleBuilder;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Tests de l'endpoint /api/map-bundle et du builder associé.
 *
 * Cf. FF_map_bundle_cache.md.
 */
class MapBundleApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->make(Settings::class); // valeurs par défaut
        Cache::flush(); // état propre entre tests
    }

    private function makeSite(string $name = 'Volmerange'): Site
    {
        $site = Site::create([
            'name'       => $name,
            'slug'       => strtolower($name) . '-' . uniqid(),
            'latitude'   => 49.4,
            'longitude'  => 6.1,
            'altitude_m' => 420,
            'region'     => 'grand-est',
            'active'     => true,
        ]);
        SiteCondition::create([
            'site_id'         => $site->id,
            'wind_dir_min'    => 75,
            'wind_dir_max'    => 105,
            'wind_speed_min'  => 5,
            'wind_speed_max'  => 25,
            'wind_speed_ideal'=> 15,
            'cloud_base_min_m'=> 800,
        ]);
        return $site;
    }

    private function makeScore(Site $site, \Carbon\Carbon $at, string $status = 'green'): SiteScore
    {
        return SiteScore::create([
            'site_id'              => $site->id,
            'forecast_at'          => $at,
            'computed_at'          => now(),
            'status'               => $status,
            'confidence_pct'       => 80,
            'wind_dir_consensus'   => 90,
            'wind_speed_consensus' => 12,
            'wind_gust_consensus'  => 18,
            'precip_consensus'     => 0,
            'cloud_base_consensus' => 1500,
            'models_count'         => 5,
            'models_converging'    => 4,
        ]);
    }

    public function test_bundle_returns_expected_structure(): void
    {
        $site = $this->makeSite();
        // Plusieurs scores aujourd'hui dans la fenêtre solaire
        $this->makeScore($site, now()->setTime(10, 0));
        $this->makeScore($site, now()->setTime(13, 0));
        $this->makeScore($site, now()->setTime(15, 0));

        $resp = $this->getJson('/api/map-bundle');
        $resp->assertOk()
            ->assertJsonStructure([
                'version',
                'generated_at',
                'next_refresh_at',
                'sites' => [
                    '*' => ['id', 'name', 'lat', 'lng', 'altitude', 'level', 'region',
                            'wind_dir_min', 'wind_dir_max', 'days', 'sun_windows'],
                ],
                'days_summary' => [
                    '*' => ['raw', 'best_status', 'green_slots'],
                ],
            ]);

        $data = $resp->json();
        $this->assertSame(MapBundleBuilder::CACHE_VERSION, $data['version']);
        $this->assertCount(1, $data['sites']);
        $this->assertSame($site->id, $data['sites'][0]['id']);
        $this->assertNotEmpty($data['days_summary']);
    }

    public function test_bundle_is_cached_after_first_call(): void
    {
        $this->makeSite();
        $this->getJson('/api/map-bundle');

        // Le cache doit contenir la clé après le 1er accès (lazy fallback)
        $this->assertNotNull(Cache::get(MapBundleBuilder::cacheKey()));
    }

    public function test_bundle_aggregates_days_summary_across_sites(): void
    {
        $site1 = $this->makeSite('A');
        $site2 = $this->makeSite('B');

        // On utilise DEMAIN pour s'assurer que tous les scores créés sont
        // dans le futur (SiteScore::upcoming filtre forecast_at >= now()).
        $tomorrow = now()->addDay();

        // Pour que la viabilité d'une journée passe le seuil green (35 par
        // défaut), il faut suffisamment d'heures volables continues autour
        // du peak (13h30). On bourre 10h-17h en green sur site1 (≈ 8h de
        // vol consécutives) et un peu sur site2.
        for ($h = 10; $h <= 17; $h++) {
            $this->makeScore($site1, $tomorrow->copy()->setTime($h, 0), 'green');
        }
        $this->makeScore($site2, $tomorrow->copy()->setTime(11, 0), 'orange');
        $this->makeScore($site2, $tomorrow->copy()->setTime(13, 0), 'green');

        $resp = $this->getJson('/api/map-bundle')->json();
        $tomorrowKey = $tomorrow->format('d/m');

        $summary = collect($resp['days_summary'])->firstWhere('raw', $tomorrowKey);
        $this->assertNotNull($summary, 'days_summary doit contenir demain');
        // best_status agrégé = green (la journée de site1 est viable).
        $this->assertSame('green', $summary['best_status']);
        // 8 heures uniques où au moins un site est green (10-17h sur site1,
        // 13h aussi sur site2 mais c'est un doublon — set d'heures).
        $this->assertSame(8, $summary['green_slots']);
    }

    public function test_overrides_endpoint_requires_auth(): void
    {
        $resp = $this->get('/api/me/scoring-overrides');
        // Middleware auth:web redirige vers login (302).
        $resp->assertStatus(302);
    }

    public function test_overrides_endpoint_returns_user_scorings(): void
    {
        $site = $this->makeSite();
        $this->makeScore($site, now()->setTime(10, 0));

        $user = User::factory()->create();
        UserSiteCondition::create([
            'user_id'        => $user->id,
            'site_id'        => $site->id,
            'is_active'      => true,
            'activated_at'   => now(),
            'wind_dir_min'   => 60,
            'wind_dir_max'   => 120,
            'wind_speed_min' => 5,
            'wind_speed_max' => 25,
            'wind_speed_ideal' => 15,
            'cloud_base_min_m' => 800,
        ]);

        $resp = $this->actingAs($user)->getJson('/api/me/scoring-overrides');
        $resp->assertOk()
            ->assertJsonPath('user_id', $user->id)
            ->assertJsonPath("overrides.{$site->id}.user_scoring", 'active');
    }

    public function test_artisan_command_rebuilds_cache(): void
    {
        $this->makeSite();

        Cache::forget(MapBundleBuilder::cacheKey());
        $this->assertNull(Cache::get(MapBundleBuilder::cacheKey()));

        $this->artisan('map:rebuild-bundle')->assertSuccessful();

        $this->assertNotNull(Cache::get(MapBundleBuilder::cacheKey()));
    }
}
