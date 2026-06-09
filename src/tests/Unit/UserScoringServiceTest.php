<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Site;
use App\Models\User;
use App\Models\UserSiteCondition;
use App\Services\Weather\UserScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Le scoring perso est déporté au sidecar (`POST /v1/scoring/custom`).
 * On mocke la réponse HTTP du sidecar ; UserScoringService ne calcule plus
 * rien lui-même. Cf. FF_personnal_scoring_sidecar.md.
 */
class UserScoringServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): UserScoringService
    {
        return app(UserScoringService::class);
    }

    private function site(): Site
    {
        return Site::create([
            'name'       => 'Test',
            'slug'       => 'test-' . uniqid(),
            'latitude'   => 45.5,
            'longitude'  => 6.1,
            'altitude_m' => 1200,
            'region'     => 'test',
            'active'     => true,
        ]);
    }

    private function activeScoring(User $user, Site $site): UserSiteCondition
    {
        return UserSiteCondition::create([
            'user_id' => $user->id, 'site_id' => $site->id,
            'is_active' => true, 'activated_at' => now(),
            'wind_dir_min' => 90, 'wind_dir_max' => 180,
            'wind_speed_min' => 5, 'wind_speed_max' => 25, 'wind_speed_ideal' => 15,
        ]);
    }

    /** Réponse sidecar simulée pour un site (ref = site_id). */
    private function fakeSidecar(int $siteId, string $status = 'green'): void
    {
        Http::fake([
            '*/v1/scoring/custom' => Http::response([
                'run_init_unix' => 1781035200,
                'sites' => [[
                    'ref'   => (string) $siteId,
                    'slots' => [[
                        'forecast_at' => now()->addHour()->startOfHour()->format('Y-m-d\TH:i'),
                        'status'      => $status,
                        'confidence_pct' => 80,
                        'colors' => [
                            'wind_dir'   => ['consensus' => 120.0, 'color' => 'green'],
                            'wind_speed' => ['consensus' => 15.0,  'color' => 'green'],
                            'wind_gust'  => ['consensus' => 22.0,  'color' => 'orange'],
                        ],
                    ]],
                ]],
            ], 200),
        ]);
    }

    public function test_returns_null_when_no_active_scoring(): void
    {
        Http::fake(); // ne doit pas être appelé
        $user = User::factory()->create();
        $site = $this->site();

        $this->assertNull($this->service()->rescoreForUserSite($user->id, $site->id));
        Http::assertNothingSent();
    }

    public function test_maps_sidecar_response_by_site_and_slot(): void
    {
        $user = User::factory()->create();
        $site = $this->site();
        $this->activeScoring($user, $site);
        $this->fakeSidecar($site->id, 'green');

        $out = $this->service()->rescoreForUserSite($user->id, $site->id);

        $this->assertIsArray($out);
        $this->assertCount(1, $out);
        $first = array_values($out)[0];
        $this->assertSame('green', $first['status']);
        // colors aplaties : {param => couleur} (et plus {consensus,color}).
        $this->assertSame('green', $first['colors']['wind_dir']);
        $this->assertSame('orange', $first['colors']['wind_gust']);
    }

    public function test_result_is_cached_single_call(): void
    {
        $user = User::factory()->create();
        $site = $this->site();
        $this->activeScoring($user, $site);
        $this->fakeSidecar($site->id);

        $key = $this->service()->cacheKey($user->id);
        $this->assertFalse(Cache::has($key));

        $this->service()->rescoreForUserSite($user->id, $site->id);
        $this->assertTrue(Cache::has($key));

        // 2e accès → cache hit, pas de second appel sidecar.
        $this->service()->rescoreForUserSite($user->id, $site->id);
        Http::assertSentCount(1);
    }

    public function test_sidecar_failure_falls_back_and_is_not_cached(): void
    {
        $user = User::factory()->create();
        $site = $this->site();
        $this->activeScoring($user, $site);

        Http::fake(['*/v1/scoring/custom' => Http::response(null, 503)]);

        $this->assertNull($this->service()->rescoreForUserSite($user->id, $site->id));
        // Échec → on ne cache pas (retry au prochain accès).
        $this->assertFalse(Cache::has($this->service()->cacheKey($user->id)));
    }

    public function test_invalidate_user_purges_cache(): void
    {
        $user = User::factory()->create();
        $site = $this->site();
        $this->activeScoring($user, $site);
        $this->fakeSidecar($site->id);

        $svc = $this->service();
        $svc->rescoreForUserSite($user->id, $site->id);
        $this->assertTrue(Cache::has($svc->cacheKey($user->id)));

        $svc->invalidateUser($user->id);
        $this->assertFalse(Cache::has($svc->cacheKey($user->id)));
    }

    public function test_invalidate_all_purges_active_users(): void
    {
        $users = collect(range(1, 3))->map(fn () => User::factory()->create());
        $site  = $this->site();
        foreach ($users as $u) {
            $this->activeScoring($u, $site);
        }
        $this->fakeSidecar($site->id);

        foreach ($users as $u) {
            $this->service()->rescoreForUserSite($u->id, $site->id);
            $this->assertTrue(Cache::has($this->service()->cacheKey($u->id)));
        }

        $this->service()->invalidateAll();

        foreach ($users as $u) {
            $this->assertFalse(Cache::has($this->service()->cacheKey($u->id)));
        }
    }

    public function test_activate_applies_lru_demotion(): void
    {
        Http::fake();
        $user  = User::factory()->create();
        $sites = collect(range(1, UserSiteCondition::MAX_ACTIVE + 1))
            ->map(fn (int $i) => Site::create([
                'name' => 'Site '.$i, 'slug' => 'site-'.$i.'-'.uniqid(),
                'latitude' => 45.0, 'longitude' => 6.0,
                'altitude_m' => 500, 'region' => 'test', 'active' => true,
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
