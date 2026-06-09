<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Forecast;
use App\Models\Site;
use App\Models\SiteCondition;
use App\Models\SiteScore;
use App\Models\User;
use App\Models\UserSiteCondition;
use App\Models\WeatherApi;
use App\Models\WeatherModel;
use App\Services\Map\SiteDetailCache;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Tests du cache transparent des endpoints détail d'un site
 * (/scores, /chart, /multimodel) — phase 2 du FF_map_bundle_cache.md.
 */
class SiteDetailCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->make(Settings::class);
        Cache::flush();
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
            'site_id'          => $site->id,
            'wind_dir_min'     => 75,
            'wind_dir_max'     => 105,
            'wind_speed_min'   => 5,
            'wind_speed_max'   => 25,
            'wind_speed_ideal' => 15,
            'cloud_base_min_m' => 800,
        ]);
        return $site;
    }

    private function makeScore(Site $site, \Carbon\Carbon $at, string $status = 'green'): SiteScore
    {
        return SiteScore::onActiveBuffer()->create([
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

    public function test_scores_endpoint_caches_global_payload(): void
    {
        $site = $this->makeSite();
        $this->makeScore($site, now()->addDay()->setTime(13, 0));

        $this->assertNull(Cache::get(SiteDetailCache::scoresKey($site->id)));

        $this->getJson("/api/sites/{$site->id}/scores")->assertOk();

        // Après le 1er appel, le cache est rempli avec la version global.
        $cached = Cache::get(SiteDetailCache::scoresKey($site->id));
        $this->assertIsArray($cached);
        $this->assertSame('global', $cached['scoring_source']);
    }

    public function test_chart_endpoint_caches_payload(): void
    {
        $site = $this->makeSite();
        $this->makeScore($site, now()->addDay()->setTime(13, 0));

        $this->assertNull(Cache::get(SiteDetailCache::chartKey($site->id)));
        $this->getJson("/api/sites/{$site->id}/chart")->assertOk();
        $this->assertNotNull(Cache::get(SiteDetailCache::chartKey($site->id)));
    }

    public function test_multimodel_endpoint_caches_per_day_and_period(): void
    {
        $site = $this->makeSite();
        $day  = now()->addDay()->format('Y-m-d');

        $this->getJson("/api/sites/{$site->id}/multimodel?day={$day}&period=daylight")->assertOk();

        // Clé spécifique (day, period) cachée. La clé du même jour avec
        // période différente reste vide.
        $this->assertNotNull(Cache::get(SiteDetailCache::multimodelKey($site->id, $day, 'daylight')));
        $this->assertNull(Cache::get(SiteDetailCache::multimodelKey($site->id, $day, '24h')));
    }

    public function test_score_site_job_invalidates_cache(): void
    {
        $site = $this->makeSite();
        $this->makeScore($site, now()->addDay()->setTime(13, 0));

        // Remplir les caches
        $this->getJson("/api/sites/{$site->id}/scores")->assertOk();
        $this->getJson("/api/sites/{$site->id}/chart")->assertOk();
        $day = now()->addDay()->format('Y-m-d');
        $this->getJson("/api/sites/{$site->id}/multimodel?day={$day}&period=daylight")->assertOk();

        $this->assertNotNull(Cache::get(SiteDetailCache::scoresKey($site->id)));
        $this->assertNotNull(Cache::get(SiteDetailCache::chartKey($site->id)));
        $this->assertNotNull(Cache::get(SiteDetailCache::multimodelKey($site->id, $day, 'daylight')));

        // Le ScoreSiteJob doit invalider toutes ces clés
        \App\Jobs\ScoreSiteJob::dispatchSync($site->id);

        $this->assertNull(Cache::get(SiteDetailCache::scoresKey($site->id)));
        $this->assertNull(Cache::get(SiteDetailCache::chartKey($site->id)));
        $this->assertNull(Cache::get(SiteDetailCache::multimodelKey($site->id, $day, 'daylight')));
    }

    public function test_user_with_perso_scoring_gets_overlaid_response(): void
    {
        $site = $this->makeSite();
        $this->makeScore($site, now()->addDay()->setTime(13, 0), 'green');

        // 1er appel anonyme : remplit le cache version global
        $this->getJson("/api/sites/{$site->id}/scores")
            ->assertOk()
            ->assertJsonPath('scoring_source', 'global');

        // 2e appel avec user qui a un scoring perso actif
        $user = User::factory()->create();
        UserSiteCondition::create([
            'user_id'          => $user->id,
            'site_id'          => $site->id,
            'is_active'        => true,
            'activated_at'     => now(),
            // Plage de direction IMPOSSIBLE pour le site (vent à 90° en DB,
            // user veut 200-260°) → re-scoring perso doit donner red.
            'wind_dir_min'     => 200,
            'wind_dir_max'     => 260,
            'wind_speed_min'   => 5,
            'wind_speed_max'   => 25,
            'wind_speed_ideal' => 15,
            'cloud_base_min_m' => 800,
        ]);

        $resp = $this->actingAs($user)->getJson("/api/sites/{$site->id}/scores");
        $resp->assertOk()
            ->assertJsonPath('scoring_source', 'user');

        $data = $resp->json();
        // Status user (red, car la direction est hors plage perso)
        $this->assertSame('red', $data['scores'][0]['status']);
        // status_global préservé
        $this->assertSame('green', $data['scores'][0]['status_global']);
    }
}
