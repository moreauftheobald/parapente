<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Authentification du BackOffice.
 *
 * Le rôle admin est imposé pour accéder à /admin/* (cf. middleware
 * EnsureAdmin), mais on contrôle aussi à la connexion : si un user
 * non-admin tente de se loguer ici, on l'éconduit.
 *
 * Pas de page d'inscription publique pour l'instant — les comptes
 * admin sont créés via `php artisan admin:create`.
 */
class AuthController extends Controller
{
    public function showLogin(): View
    {
        return view('admin.auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials, (bool) $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => __('Email ou mot de passe incorrect.'),
            ]);
        }

        // Rejette les non-admin (sécurité défense en profondeur,
        // EnsureAdmin protège déjà les routes mais autant ne pas
        // créer une session pour un user lambda).
        if (! Auth::user()?->isAdmin()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw ValidationException::withMessages([
                'email' => __('Accès réservé aux administrateurs.'),
            ]);
        }

        $request->session()->regenerate();
        return redirect()->intended(route('admin.dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('admin.login');
    }
}
