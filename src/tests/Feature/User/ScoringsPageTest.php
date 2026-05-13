<?php

declare(strict_types=1);

namespace Tests\Feature\User;

use App\Models\Site;
use App\Models\User;
use App\Models\UserSiteCondition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScoringsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_requires_auth(): void
    {
        $this->get('/profil/scorings')->assertRedirect(route('login'));
    }

    public function test_page_renders_for_authenticated_user(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)
            ->get('/profil/scorings')
            ->assertOk()
            ->assertSee('Mes scorings perso');
    }

    public function test_page_exposes_initial_counts(): void
    {
        $user = User::factory()->create();
        $site = Site::create([
            'name' => 'Test', 'slug' => 'test-' . uniqid(),
            'latitude' => 49.0, 'longitude' => 6.0,
            'altitude_m' => 500, 'region' => 'test', 'active' => true,
        ]);
        UserSiteCondition::create([
            'user_id' => $user->id, 'site_id' => $site->id,
            'is_active' => true, 'activated_at' => now(),
            'wind_dir_min' => 90, 'wind_dir_max' => 180,
            'wind_speed_min' => 5, 'wind_speed_max' => 25, 'wind_speed_ideal' => 15,
        ]);

        $resp = $this->actingAs($user)->get('/profil/scorings');
        $resp->assertOk();

        // initialActiveCount injecté en JS via {{ $initialActiveCount }}
        $resp->assertSee('activeCount: 1', false);
    }

    public function test_profile_page_links_to_scorings_page(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)
            ->get('/profil')
            ->assertOk()
            ->assertSee(route('user.scorings'), false);
    }
}
