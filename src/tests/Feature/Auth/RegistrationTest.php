<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_renders(): void
    {
        $this->get('/inscription')->assertOk();
    }

    public function test_user_can_register(): void
    {
        $response = $this->post('/inscription', [
            'name'                  => 'Test Pilot',
            'pseudo'                => 'pilot42',
            'email'                 => 'pilot@example.com',
            'password'              => 'secret-123',
            'password_confirmation' => 'secret-123',
        ]);

        $response->assertRedirect(route('user.profile'));
        $this->assertAuthenticated();

        $user = User::where('email', 'pilot@example.com')->firstOrFail();
        $this->assertSame('user', $user->role);
        $this->assertSame('pilot42', $user->pseudo);
    }

    public function test_registration_rejects_duplicate_email(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->post('/inscription', [
            'name'                  => 'X',
            'email'                 => 'taken@example.com',
            'password'              => 'secret-123',
            'password_confirmation' => 'secret-123',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_registration_rejects_duplicate_pseudo(): void
    {
        User::factory()->create(['pseudo' => 'taken']);

        $this->post('/inscription', [
            'name'                  => 'X',
            'pseudo'                => 'taken',
            'email'                 => 'fresh@example.com',
            'password'              => 'secret-123',
            'password_confirmation' => 'secret-123',
        ])->assertSessionHasErrors('pseudo');
    }

    public function test_registration_rejects_weak_password(): void
    {
        $this->post('/inscription', [
            'name'                  => 'X',
            'email'                 => 'weak@example.com',
            'password'              => 'short',
            'password_confirmation' => 'short',
        ])->assertSessionHasErrors('password');
    }

    public function test_authenticated_user_cannot_see_registration(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/inscription')
            ->assertRedirect();
    }
}
