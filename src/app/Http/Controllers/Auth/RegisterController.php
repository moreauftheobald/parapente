<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Inscription publique (rôle « user »).
 *
 * Le rôle « admin » n'est jamais attribué ici — les comptes admin
 * passent par `php artisan admin:create` ou le BackOffice.
 */
class RegisterController extends Controller
{
    public function showForm(): View
    {
        return view('auth.register');
    }

    public function store(RegisterRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $user = User::create([
            'name'     => $data['name'],
            'pseudo'   => $data['pseudo'] ?? null,
            'email'    => $data['email'],
            'password' => $data['password'],
            'role'     => 'user',
        ]);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('user.profile')
            ->with('status', 'Bienvenue ! Ton compte a bien été créé.');
    }
}
