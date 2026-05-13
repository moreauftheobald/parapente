<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_renders(): void
    {
        $this->get('/connexion')->assertOk();
    }

    public function test_user_can_login_and_lands_on_home_by_default(): void
    {
        $user = User::factory()->create(['password' => 'secret-123']);

        $this->post('/connexion', [
            'email'    => $user->email,
            'password' => 'secret-123',
        ])->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_login_redirects_to_referer_when_safe(): void
    {
        $user = User::factory()->create(['password' => 'secret-123']);

        $this->from('/carte')->post('/connexion', [
            'email'    => $user->email,
            'password' => 'secret-123',
        ])->assertRedirect('/carte');
    }

    public function test_login_falls_back_when_referer_is_login_page(): void
    {
        $user = User::factory()->create(['password' => 'secret-123']);

        $this->from('/connexion')->post('/connexion', [
            'email'    => $user->email,
            'password' => 'secret-123',
        ])->assertRedirect(route('home'));
    }

    public function test_login_accepts_remember_checkbox_with_value_on(): void
    {
        $user = User::factory()->create(['password' => 'secret-123']);

        // Valeur "on" envoyée par défaut quand une checkbox HTML sans
        // attribut value est cochée — le controller doit l'accepter.
        $this->post('/connexion', [
            'email'    => $user->email,
            'password' => 'secret-123',
            'remember' => 'on',
        ])->assertSessionHasNoErrors();

        $this->assertAuthenticatedAs($user);
    }

    public function test_admin_can_login_via_front(): void
    {
        $admin = User::factory()->create([
            'role'     => 'admin',
            'password' => 'secret-123',
        ]);

        $this->post('/connexion', [
            'email'    => $admin->email,
            'password' => 'secret-123',
        ])->assertRedirect();

        $this->assertAuthenticatedAs($admin);
    }

    public function test_login_rejects_wrong_password(): void
    {
        $user = User::factory()->create(['password' => 'secret-123']);

        $this->post('/connexion', [
            'email'    => $user->email,
            'password' => 'wrong',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_logout(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/deconnexion')
            ->assertRedirect(route('home'));

        $this->assertGuest();
    }
}
