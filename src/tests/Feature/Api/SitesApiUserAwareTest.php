<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Forecast;
use App\Models\Site;
use App\Models\SiteCondition;
use App\Models\SiteScore;
use App\Models\User;
use App\Models\UserSiteCondition;
use App\Models\WeatherModel;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Vérifie que /api/sites et /api/sites/{id}/scores deviennent
 * user-aware quand l'utilisateur est connecté avec un scoring perso
 * (lot 3 — alimentation de la carte).
 */
class SitesApiUserAwareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Settings DB-backed avec valeurs par défaut → injecté via DI.
        $this->app->make(Settings::class);
    }

    private function makeSiteWithGreenScore(): Site
    {
        $site = Site::create([
            'name' => 'Volmerange', 'slug' => 'volmerange-' . uniqid(),
            'latitude' => 49.4, 'longitude' => 6.1,
            'altitude_m' => 420, 'region' => 'grand-est', 'active' => true,
        ]);
        SiteCondition::create([
            'site_id' => $site->id,
            // Direction strictement E (la perso prendra une autre plage)
            'wind_dir_min' => 75, 'wind_dir_max' => 105,
            'wind_speed_min' => 5, 'wind_speed_max' => 25, 'wind_speed_ideal' => 15,
            'cloud_base_min_m' => 800,
        ]);
        SiteScore::onActiveBuffer()->create([
            'site_id'              => $site->id,
            'forecast_at'          => now()->addHour()->startOfHour(),
            'computed_at'          => now(),
            'status'               => 'green',
            'confidence_pct'       => 85,
            'wind_dir_consensus'   => 90,    // dans la plage globale
            'wind_speed_consensus' => 15.0,
            'wind_gust_consensus'  => 22.0,
            'precip_consensus'     => 0.0,
            'cloud_base_consensus' => 1500,
            'models_count'         => 5,
            'models_converging'    => 5,
            'detail'               => [
                'wind_dir'   => ['consensus' => 90,   'convergence' => 0.95, 'color' => 'green'],
                'wind_speed' => ['consensus' => 15.0, 'convergence' => 0.9,  'color' => 'green'],
                'wind_gust'  => ['consensus' => 22.0, 'color' => 'green'],
                'precip'     => ['consensus' => 0.0,  'convergence' => 1.0,  'color' => 'green'],
                'cloud_base' => ['consensus' => 1500, 'color' => 'green'],
            ],
        ]);
        return $site;
    }

    public function test_sites_exposes_user_scoring_state_when_authenticated(): void
    {
        $user = User::factory()->create();
        $a    = $this->makeSiteWithGreenScore();
        $b    = $this->makeSiteWithGreenScore();
        $c    = $this->makeSiteWithGreenScore();

        UserSiteCondition::create([
            'user_id' => $user->id, 'site_id' => $a->id, 'is_active' => true,
            'activated_at' => now(),
            'wind_dir_min' => 90, 'wind_dir_max' => 180,
            'wind_speed_min' => 5, 'wind_speed_max' => 25, 'wind_speed_ideal' => 15,
        ]);
        UserSiteCondition::create([
            'user_id' => $user->id, 'site_id' => $b->id, 'is_active' => false,
            'wind_dir_min' => 90, 'wind_dir_max' => 180,
            'wind_speed_min' => 5, 'wind_speed_max' => 25, 'wind_speed_ideal' => 15,
        ]);

        $resp = $this->actingAs($user)->getJson('/api/sites');
        $resp->assertOk();
        $byId = collect($resp->json())->keyBy('id');

        $this->assertSame('active',   $byId[$a->id]['user_scoring']);
        $this->assertSame('inactive', $byId[$b->id]['user_scoring']);
        $this->assertNull($byId[$c->id]['user_scoring']);
    }

    public function test_sites_user_scoring_is_null_for_guests(): void
    {
        $a = $this->makeSiteWithGreenScore();
        UserSiteCondition::create([
            'user_id' => User::factory()->create()->id,
            'site_id' => $a->id, 'is_active' => true, 'activated_at' => now(),
            'wind_dir_min' => 90, 'wind_dir_max' => 180,
            'wind_speed_min' => 5, 'wind_speed_max' => 25, 'wind_speed_ideal' => 15,
        ]);

        $resp = $this->getJson('/api/sites');
        $resp->assertOk()->assertJsonPath('0.user_scoring', null);
    }

    public function test_scores_returns_scoring_source_global_by_default(): void
    {
        $site = $this->makeSiteWithGreenScore();
        $this->getJson("/api/sites/{$site->id}/scores")
            ->assertOk()
            ->assertJsonPath('scoring_source', 'global')
            ->assertJsonMissingPath('scores.0.detail.wind_dir.color_global')
            ->assertJsonMissingPath('scores.0.status_global');
    }

    public function test_scores_returns_scoring_source_user_when_active(): void
    {
        $user = User::factory()->create();
        $site = $this->makeSiteWithGreenScore();

        // Scoring perso plus restrictif sur la direction : axe S (180-220).
        // Le consensus est à 90° → hors plage → status perso devient red.
        UserSiteCondition::create([
            'user_id' => $user->id, 'site_id' => $site->id, 'is_active' => true,
            'activated_at' => now(),
            'wind_dir_min' => 180, 'wind_dir_max' => 220,
            'wind_speed_min' => 5, 'wind_speed_max' => 25, 'wind_speed_ideal' => 15,
        ]);

        $resp = $this->actingAs($user)->getJson("/api/sites/{$site->id}/scores");
        $resp->assertOk()
            ->assertJsonPath('scoring_source', 'user')
            // Le status devient red (direction hors plage perso).
            ->assertJsonPath('scores.0.status', 'red')
            // Le status global (standard du site) reste exposé pour la
            // comparaison sur la ligne « Statut global » de l'onglet voting.
            ->assertJsonPath('scores.0.status_global', 'green')
            // Le detail expose color (perso) ET color_global (standard) en parallèle.
            ->assertJsonPath('scores.0.detail.wind_dir.color', 'red')
            ->assertJsonPath('scores.0.detail.wind_dir.color_global', 'green');
    }

    public function test_scores_does_not_expose_color_global_when_user_has_no_active_scoring(): void
    {
        $user = User::factory()->create();
        $site = $this->makeSiteWithGreenScore();

        // Scoring perso présent mais inactif.
        UserSiteCondition::create([
            'user_id' => $user->id, 'site_id' => $site->id, 'is_active' => false,
            'wind_dir_min' => 180, 'wind_dir_max' => 220,
            'wind_speed_min' => 5, 'wind_speed_max' => 25, 'wind_speed_ideal' => 15,
        ]);

        $resp = $this->actingAs($user)->getJson("/api/sites/{$site->id}/scores");
        $resp->assertOk()
            ->assertJsonPath('scoring_source', 'global')
            ->assertJsonMissingPath('scores.0.detail.wind_dir.color_global');
    }
}
