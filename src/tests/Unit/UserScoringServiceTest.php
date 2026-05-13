<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Site;
use App\Models\SiteScore;
use App\Models\User;
use App\Models\UserSiteCondition;
use App\Services\Weather\UserScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class UserScoringServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): UserScoringService
    {
        return app(UserScoringService::class);
    }

    private function siteWithScore(): Site
    {
        $site = Site::create([
            'name'       => 'Test',
            'slug'       => 'test-' . uniqid(),
            'latitude'   => 49.0,
            'longitude'  => 6.0,
            'altitude_m' => 500,
            'region'     => 'test',
            'active'     => true,
        ]);

        SiteScore::create([
            'site_id'              => $site->id,
            'forecast_at'          => now()->addHour()->startOfHour(),
            'computed_at'          => now(),
            'status'               => 'green',
            'confidence_pct'       => 75,
            'wind_dir_consensus'   => 120,
            'wind_speed_consensus' => 15.0,
            'wind_gust_consensus'  => 22.0,
            'precip_consensus'     => 0.0,
            'cloud_base_consensus' => 1500,
            'models_count'         => 5,
            'models_converging'    => 5,
            'detail'               => json_encode([]),
        ]);

        return $site;
    }

    public function test_rescore_returns_null_when_no_active_scoring(): void
    {
        $user = User::factory()->create();
        $site = $this->siteWithScore();

        $this->assertNull(
            $this->service()->rescoreForUserSite($user->id, $site->id)
        );
    }

    public function test_rescore_returns_null_when_scoring_inactive(): void
    {
        $user = User::factory()->create();
        $site = $this->siteWithScore();
        UserSiteCondition::create([
            'user_id' => $user->id, 'site_id' => $site->id, 'is_active' => false,
            'wind_dir_min' => 90, 'wind_dir_max' => 180,
            'wind_speed_min' => 5, 'wind_speed_max' => 25, 'wind_speed_ideal' => 15,
        ]);

        $this->assertNull(
            $this->service()->rescoreForUserSite($user->id, $site->id)
        );
    }

    public function test_rescore_returns_green_when_consensus_matches_user_conditions(): void
    {
        $user = User::factory()->create();
        $site = $this->siteWithScore(); // dir=120, speed=15, gust=22, no precip

        UserSiteCondition::create([
            'user_id' => $user->id, 'site_id' => $site->id, 'is_active' => true,
            'activated_at' => now(),
            'wind_dir_min' => 90, 'wind_dir_max' => 180,
            'wind_speed_min' => 5, 'wind_speed_max' => 25, 'wind_speed_ideal' => 15,
        ]);

        $out = $this->service()->rescoreForUserSite($user->id, $site->id);
        $this->assertIsArray($out);
        $this->assertCount(1, $out);

        $first = array_values($out)[0];
        $this->assertSame('green', $first['status']);
        $this->assertSame('green', $first['colors']['wind_dir']);
        $this->assertSame('green', $first['colors']['wind_speed']);
    }

    public function test_rescore_returns_red_when_direction_out_of_range(): void
    {
        $user = User::factory()->create();
        $site = $this->siteWithScore(); // dir=120

        UserSiteCondition::create([
            'user_id' => $user->id, 'site_id' => $site->id, 'is_active' => true,
            'activated_at' => now(),
            'wind_dir_min' => 200, 'wind_dir_max' => 250, // 120 hors plage
            'wind_speed_min' => 5, 'wind_speed_max' => 25, 'wind_speed_ideal' => 15,
        ]);

        $out = $this->service()->rescoreForUserSite($user->id, $site->id);
        $first = array_values($out)[0];

        $this->assertSame('red', $first['status']);
        $this->assertSame('red', $first['colors']['wind_dir']);
    }

    public function test_rescore_uses_cache(): void
    {
        $user = User::factory()->create();
        $site = $this->siteWithScore();
        $usc  = UserSiteCondition::create([
            'user_id' => $user->id, 'site_id' => $site->id, 'is_active' => true,
            'activated_at' => now(),
            'wind_dir_min' => 90, 'wind_dir_max' => 180,
            'wind_speed_min' => 5, 'wind_speed_max' => 25, 'wind_speed_ideal' => 15,
        ]);

        $svc = $this->service();
        $key = $svc->cacheKey($user->id, $site->id);

        $this->assertFalse(Cache::has($key));
        $svc->rescoreForUserSite($user->id, $site->id);
        $this->assertTrue(Cache::has($key));

        $svc->invalidate($user->id, $site->id);
        $this->assertFalse(Cache::has($key));
    }

    public function test_invalidate_site_purges_all_users(): void
    {
        $users = collect(range(1, 3))->map(fn () => User::factory()->create());
        $site  = $this->siteWithScore();

        foreach ($users as $u) {
            UserSiteCondition::create([
                'user_id' => $u->id, 'site_id' => $site->id, 'is_active' => true,
                'activated_at' => now(),
                'wind_dir_min' => 90, 'wind_dir_max' => 180,
                'wind_speed_min' => 5, 'wind_speed_max' => 25, 'wind_speed_ideal' => 15,
            ]);
            $this->service()->rescoreForUserSite($u->id, $site->id);
        }

        foreach ($users as $u) {
            $this->assertTrue(Cache::has($this->service()->cacheKey($u->id, $site->id)));
        }

        $this->service()->invalidateSite($site->id);

        foreach ($users as $u) {
            $this->assertFalse(Cache::has($this->service()->cacheKey($u->id, $site->id)));
        }
    }

    public function test_activate_applies_lru_demotion(): void
    {
        $user  = User::factory()->create();
        $sites = collect(range(1, UserSiteCondition::MAX_ACTIVE + 1))
            ->map(fn (int $i) => Site::create([
                'name'       => 'Site '.$i, 'slug' => 'site-'.$i,
                'latitude'   => 49.0, 'longitude' => 6.0,
                'altitude_m' => 500, 'region' => 'test',
                'active'     => true,
            ]));

        foreach ($sites->take(UserSiteCondition::MAX_ACTIVE) as $i => $site) {
            UserSiteCondition::create([
                'user_id' => $user->id, 'site_id' => $site->id, 'is_active' => true,
                'activated_at' => now()->subMinutes(UserSiteCondition::MAX_ACTIVE - $i),
                'wind_dir_min' => 90, 'wind_dir_max' => 180,
                'wind_speed_min' => 5, 'wind_speed_max' => 25, 'wind_speed_ideal' => 15,
            ]);
        }

        $newcomer = UserSiteCondition::create([
            'user_id' => $user->id, 'site_id' => $sites->last()->id, 'is_active' => false,
            'wind_dir_min' => 90, 'wind_dir_max' => 180,
            'wind_speed_min' => 5, 'wind_speed_max' => 25, 'wind_speed_ideal' => 15,
        ]);

        $result = $this->service()->activate($newcomer);

        $this->assertTrue($result['activated']->is_active);
        $this->assertNotNull($result['demoted']);
        $this->assertFalse($result['demoted']->is_active);
        $this->assertSame(
            UserSiteCondition::MAX_ACTIVE,
            UserSiteCondition::forUser($user)->active()->count()
        );
    }
}
