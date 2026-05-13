<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Site;
use App\Models\User;
use App\Models\UserSiteCondition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserScoringApiTest extends TestCase
{
    use RefreshDatabase;

    private function makeActiveSite(array $attrs = []): Site
    {
        return Site::create(array_merge([
            'name'       => 'Test Site ' . uniqid(),
            'slug'       => 'test-site-' . uniqid(),
            'latitude'   => 49.0,
            'longitude'  => 6.0,
            'altitude_m' => 500,
            'region'     => 'test',
            'active'     => true,
        ], $attrs));
    }

    private function validPayload(int $siteId): array
    {
        return [
            'site_id'              => $siteId,
            'wind_dir_min'         => 90,
            'wind_dir_max'         => 180,
            'wind_speed_min'       => 5,
            'wind_speed_max'       => 25,
            'wind_speed_ideal'     => 15,
            'wind_gust_orange_kmh' => null,
            'wind_gust_red_kmh'    => null,
            'cloud_base_min_m'     => 1200,
            'cloud_cover_low_max'  => 60,
            'notes'                => 'mes notes',
        ];
    }

    public function test_index_requires_auth(): void
    {
        $this->getJson('/api/users/me/scorings')->assertUnauthorized();
    }

    public function test_index_returns_user_scorings(): void
    {
        $user  = User::factory()->create();
        $site  = $this->makeActiveSite();

        UserSiteCondition::create([
            'user_id'      => $user->id,
            'site_id'      => $site->id,
            'is_active'    => true,
            'activated_at' => now(),
            'wind_dir_min' => 90, 'wind_dir_max' => 180,
            'wind_speed_min' => 5, 'wind_speed_max' => 25, 'wind_speed_ideal' => 15,
        ]);

        $resp = $this->actingAs($user)->getJson('/api/users/me/scorings');
        $resp->assertOk()
            ->assertJsonPath('max_active', UserSiteCondition::MAX_ACTIVE)
            ->assertJsonPath('max_stored', UserSiteCondition::MAX_STORED)
            ->assertJsonPath('active_count', 1)
            ->assertJsonCount(1, 'scorings');
    }

    public function test_store_creates_scoring_inactive_by_default(): void
    {
        $user = User::factory()->create();
        $site = $this->makeActiveSite();

        $resp = $this->actingAs($user)->postJson('/api/users/me/scorings', $this->validPayload($site->id));
        $resp->assertCreated()->assertJsonPath('scoring.is_active', false);

        $this->assertDatabaseHas('user_site_conditions', [
            'user_id' => $user->id, 'site_id' => $site->id, 'is_active' => false,
        ]);
    }

    public function test_store_rejects_inactive_site(): void
    {
        $user = User::factory()->create();
        $site = $this->makeActiveSite(['active' => false]);

        $this->actingAs($user)
            ->postJson('/api/users/me/scorings', $this->validPayload($site->id))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['site_id']);
    }

    public function test_store_rejects_duplicate_site_for_user(): void
    {
        $user = User::factory()->create();
        $site = $this->makeActiveSite();
        UserSiteCondition::create([
            'user_id' => $user->id, 'site_id' => $site->id,
            'is_active' => false,
            'wind_dir_min' => 90, 'wind_dir_max' => 180,
            'wind_speed_min' => 5, 'wind_speed_max' => 25, 'wind_speed_ideal' => 15,
        ]);

        $this->actingAs($user)
            ->postJson('/api/users/me/scorings', $this->validPayload($site->id))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['site_id']);
    }

    public function test_store_validates_speed_coherence(): void
    {
        $user = User::factory()->create();
        $site = $this->makeActiveSite();

        $bad = $this->validPayload($site->id);
        $bad['wind_speed_min']   = 30;
        $bad['wind_speed_max']   = 10;
        $bad['wind_speed_ideal'] = 20;

        $this->actingAs($user)
            ->postJson('/api/users/me/scorings', $bad)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['wind_speed_max']);
    }

    public function test_store_validates_gust_coherence(): void
    {
        $user = User::factory()->create();
        $site = $this->makeActiveSite();

        $bad = $this->validPayload($site->id);
        $bad['wind_gust_orange_kmh'] = 40;
        $bad['wind_gust_red_kmh']    = 30;

        $this->actingAs($user)
            ->postJson('/api/users/me/scorings', $bad)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['wind_gust_red_kmh']);
    }

    public function test_update_modifies_existing(): void
    {
        $user = User::factory()->create();
        $site = $this->makeActiveSite();
        $usc  = UserSiteCondition::create([
            'user_id' => $user->id, 'site_id' => $site->id,
            'wind_dir_min' => 90, 'wind_dir_max' => 180,
            'wind_speed_min' => 5, 'wind_speed_max' => 25, 'wind_speed_ideal' => 15,
        ]);

        $payload = $this->validPayload($site->id);
        $payload['notes'] = 'updated';

        $this->actingAs($user)
            ->patchJson("/api/users/me/scorings/{$usc->id}", $payload)
            ->assertOk()
            ->assertJsonPath('scoring.notes', 'updated');
    }

    public function test_update_forbids_other_user(): void
    {
        $owner    = User::factory()->create();
        $stranger = User::factory()->create();
        $site     = $this->makeActiveSite();
        $usc      = UserSiteCondition::create([
            'user_id' => $owner->id, 'site_id' => $site->id,
            'wind_dir_min' => 90, 'wind_dir_max' => 180,
            'wind_speed_min' => 5, 'wind_speed_max' => 25, 'wind_speed_ideal' => 15,
        ]);

        $this->actingAs($stranger)
            ->patchJson("/api/users/me/scorings/{$usc->id}", $this->validPayload($site->id))
            ->assertForbidden();
    }

    public function test_activate_then_deactivate(): void
    {
        $user = User::factory()->create();
        $site = $this->makeActiveSite();
        $usc  = UserSiteCondition::create([
            'user_id' => $user->id, 'site_id' => $site->id, 'is_active' => false,
            'wind_dir_min' => 90, 'wind_dir_max' => 180,
            'wind_speed_min' => 5, 'wind_speed_max' => 25, 'wind_speed_ideal' => 15,
        ]);

        $this->actingAs($user)
            ->postJson("/api/users/me/scorings/{$usc->id}/activate")
            ->assertOk()
            ->assertJsonPath('activated.is_active', true)
            ->assertJsonPath('demoted', null);

        $this->assertTrue($usc->fresh()->is_active);
        $this->assertNotNull($usc->fresh()->activated_at);

        $this->actingAs($user)
            ->postJson("/api/users/me/scorings/{$usc->id}/deactivate")
            ->assertOk()
            ->assertJsonPath('scoring.is_active', false);

        $this->assertFalse($usc->fresh()->is_active);
    }

    public function test_activate_triggers_lru_when_limit_reached(): void
    {
        $user = User::factory()->create();
        $sites = collect(range(1, UserSiteCondition::MAX_ACTIVE + 1))
            ->map(fn (int $i) => $this->makeActiveSite(['name' => 'Site '.$i, 'slug' => 'site-'.$i]));

        // Active les MAX_ACTIVE premiers, activated_at espacés pour identifier le plus ancien.
        foreach ($sites->take(UserSiteCondition::MAX_ACTIVE) as $i => $site) {
            UserSiteCondition::create([
                'user_id' => $user->id, 'site_id' => $site->id, 'is_active' => true,
                'activated_at' => now()->subMinutes(UserSiteCondition::MAX_ACTIVE - $i),
                'wind_dir_min' => 90, 'wind_dir_max' => 180,
                'wind_speed_min' => 5, 'wind_speed_max' => 25, 'wind_speed_ideal' => 15,
            ]);
        }

        // 11ᵉ scoring inactif, on l'active.
        $newcomer = UserSiteCondition::create([
            'user_id' => $user->id, 'site_id' => $sites->last()->id, 'is_active' => false,
            'wind_dir_min' => 90, 'wind_dir_max' => 180,
            'wind_speed_min' => 5, 'wind_speed_max' => 25, 'wind_speed_ideal' => 15,
        ]);

        $resp = $this->actingAs($user)
            ->postJson("/api/users/me/scorings/{$newcomer->id}/activate")
            ->assertOk()
            ->assertJsonPath('activated.is_active', true);

        // Un scoring désactivé est annoncé.
        $this->assertNotNull($resp->json('demoted'));

        // Toujours exactement MAX_ACTIVE actifs en base.
        $this->assertSame(
            UserSiteCondition::MAX_ACTIVE,
            UserSiteCondition::forUser($user)->active()->count()
        );
    }

    public function test_destroy_removes_scoring(): void
    {
        $user = User::factory()->create();
        $site = $this->makeActiveSite();
        $usc  = UserSiteCondition::create([
            'user_id' => $user->id, 'site_id' => $site->id,
            'wind_dir_min' => 90, 'wind_dir_max' => 180,
            'wind_speed_min' => 5, 'wind_speed_max' => 25, 'wind_speed_ideal' => 15,
        ]);

        $this->actingAs($user)
            ->deleteJson("/api/users/me/scorings/{$usc->id}")
            ->assertOk()
            ->assertJsonPath('deleted', true);

        $this->assertNull(UserSiteCondition::find($usc->id));
    }
}
