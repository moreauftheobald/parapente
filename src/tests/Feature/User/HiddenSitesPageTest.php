<?php

declare(strict_types=1);

namespace Tests\Feature\User;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HiddenSitesPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_requires_auth(): void
    {
        $this->get('/profil/sites-masques')->assertRedirect(route('login'));
    }

    public function test_page_renders_for_authenticated_user(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)
            ->get('/profil/sites-masques')
            ->assertOk()
            ->assertSee('Sites masqués');
    }

    public function test_profile_page_links_to_hidden_sites_page(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)
            ->get('/profil')
            ->assertOk()
            ->assertSee(route('user.hidden-sites'), false);
    }
}
