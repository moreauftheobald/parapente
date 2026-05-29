<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Site;
use App\Models\User;
use App\Models\UserHiddenSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserHiddenSiteApiTest extends TestCase
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

    public function test_index_requires_auth(): void
    {
        $this->getJson('/api/users/me/hidden-sites')->assertUnauthorized();
    }

    public function test_overlay_requires_auth(): void
    {
        $this->getJson('/api/me/hidden-sites')->assertUnauthorized();
    }

    public function test_index_returns_hidden_ids(): void
    {
        $user = User::factory()->create();
        $a    = $this->makeActiveSite();
        $b    = $this->makeActiveSite();

        UserHiddenSite::create(['user_id' => $user->id, 'site_id' => $a->id]);

        $this->actingAs($user)->getJson('/api/users/me/hidden-sites')
            ->assertOk()
            ->assertJsonPath('hidden_site_ids', [$a->id])
            ->assertJsonCount(1, 'sites')
            ->assertJsonPath('sites.0.id', $a->id);
    }

    public function test_hide_creates_row_and_is_idempotent(): void
    {
        $user = User::factory()->create();
        $site = $this->makeActiveSite();

        $this->actingAs($user)->putJson("/api/users/me/hidden-sites/{$site->id}")
            ->assertOk()
            ->assertJsonPath('hidden', true);

        // Rejouer ne crée pas de doublon.
        $this->actingAs($user)->putJson("/api/users/me/hidden-sites/{$site->id}")
            ->assertOk();

        $this->assertSame(1, UserHiddenSite::where('user_id', $user->id)->where('site_id', $site->id)->count());
    }

    public function test_unhide_removes_row_and_is_idempotent(): void
    {
        $user = User::factory()->create();
        $site = $this->makeActiveSite();
        UserHiddenSite::create(['user_id' => $user->id, 'site_id' => $site->id]);

        $this->actingAs($user)->deleteJson("/api/users/me/hidden-sites/{$site->id}")
            ->assertOk()
            ->assertJsonPath('hidden', false);

        $this->assertDatabaseMissing('user_hidden_sites', [
            'user_id' => $user->id, 'site_id' => $site->id,
        ]);

        // Rejouer reste OK (idempotent).
        $this->actingAs($user)->deleteJson("/api/users/me/hidden-sites/{$site->id}")
            ->assertOk();
    }

    public function test_hidden_sites_are_scoped_to_user(): void
    {
        $owner    = User::factory()->create();
        $stranger = User::factory()->create();
        $site     = $this->makeActiveSite();
        UserHiddenSite::create(['user_id' => $owner->id, 'site_id' => $site->id]);

        // Le tiers ne voit pas le masquage de l'owner.
        $this->actingAs($stranger)->getJson('/api/users/me/hidden-sites')
            ->assertOk()
            ->assertJsonPath('hidden_site_ids', []);

        // Et son « unhide » ne touche pas la ligne de l'owner.
        $this->actingAs($stranger)->deleteJson("/api/users/me/hidden-sites/{$site->id}")
            ->assertOk();
        $this->assertDatabaseHas('user_hidden_sites', [
            'user_id' => $owner->id, 'site_id' => $site->id,
        ]);
    }

    public function test_overlay_returns_null_summary_without_hidden_sites(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/me/hidden-sites')
            ->assertOk()
            ->assertJsonPath('hidden_site_ids', [])
            ->assertJsonPath('days_summary', null);
    }

    public function test_overlay_lists_hidden_ids(): void
    {
        $user = User::factory()->create();
        $site = $this->makeActiveSite();
        UserHiddenSite::create(['user_id' => $user->id, 'site_id' => $site->id]);

        $this->actingAs($user)->getJson('/api/me/hidden-sites')
            ->assertOk()
            ->assertJsonPath('hidden_site_ids', [$site->id]);
    }
}
