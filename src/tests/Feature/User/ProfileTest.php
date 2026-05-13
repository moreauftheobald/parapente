<?php

declare(strict_types=1);

namespace Tests\Feature\User;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_requires_auth(): void
    {
        $this->get('/profil')->assertRedirect(route('login'));
    }

    public function test_profile_renders_for_authenticated_user(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/profil')->assertOk();
    }

    public function test_user_can_update_profile(): void
    {
        $user = User::factory()->create(['name' => 'Old', 'pseudo' => null]);

        $this->actingAs($user)->patch('/profil', [
            'name'   => 'New Name',
            'pseudo' => 'newpseudo',
            'email'  => $user->email,
            'bio'    => 'Quelques mots.',
        ])->assertRedirect(route('user.profile'));

        $user->refresh();
        $this->assertSame('New Name', $user->name);
        $this->assertSame('newpseudo', $user->pseudo);
        $this->assertSame('Quelques mots.', $user->bio);
    }

    public function test_user_cannot_take_existing_pseudo(): void
    {
        User::factory()->create(['pseudo' => 'taken']);
        $user = User::factory()->create();

        $this->actingAs($user)->patch('/profil', [
            'name'   => $user->name,
            'pseudo' => 'taken',
            'email'  => $user->email,
        ])->assertSessionHasErrors('pseudo');
    }

    public function test_user_can_keep_own_pseudo(): void
    {
        $user = User::factory()->create(['pseudo' => 'mine']);

        $this->actingAs($user)->patch('/profil', [
            'name'   => $user->name,
            'pseudo' => 'mine',
            'email'  => $user->email,
        ])->assertSessionHasNoErrors();
    }

    public function test_user_can_update_password(): void
    {
        $user = User::factory()->create(['password' => 'old-secret-1']);

        $this->actingAs($user)->patch('/profil/mot-de-passe', [
            'current_password'      => 'old-secret-1',
            'password'              => 'new-secret-2',
            'password_confirmation' => 'new-secret-2',
        ])->assertRedirect(route('user.profile'));

        $this->assertTrue(Hash::check('new-secret-2', $user->fresh()->password));
    }

    public function test_password_update_rejects_wrong_current(): void
    {
        $user = User::factory()->create(['password' => 'old-secret-1']);

        $this->actingAs($user)->patch('/profil/mot-de-passe', [
            'current_password'      => 'wrong',
            'password'              => 'new-secret-2',
            'password_confirmation' => 'new-secret-2',
        ])->assertSessionHasErrors([
            'current_password' => null,
        ], 'updatePassword');
    }

    public function test_user_can_delete_account(): void
    {
        $user = User::factory()->create(['password' => 'secret-123']);

        $this->actingAs($user)
            ->delete('/profil', ['password' => 'secret-123'])
            ->assertRedirect(route('home'));

        $this->assertGuest();
        $this->assertNull(User::find($user->id));
    }

    public function test_account_delete_requires_password(): void
    {
        $user = User::factory()->create(['password' => 'secret-123']);

        $this->actingAs($user)
            ->delete('/profil', ['password' => 'wrong'])
            ->assertSessionHasErrors(['password' => null], 'deleteAccount');

        $this->assertNotNull(User::find($user->id));
    }

    public function test_admin_cannot_delete_their_account_via_front(): void
    {
        $admin = User::factory()->create([
            'role'     => 'admin',
            'password' => 'secret-123',
        ]);

        $this->actingAs($admin)
            ->delete('/profil', ['password' => 'secret-123'])
            ->assertForbidden();

        $this->assertNotNull(User::find($admin->id));
    }
}
