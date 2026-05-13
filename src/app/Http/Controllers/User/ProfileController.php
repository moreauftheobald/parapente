<?php

declare(strict_types=1);

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\AccountDeleteRequest;
use App\Http\Requests\User\PasswordUpdateRequest;
use App\Http\Requests\User\ProfileUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Gestion du profil utilisateur (compte front).
 *
 * Le scoring perso sera branché plus tard (lot 2) dans la section
 * dédiée de la vue ; côté controller, rien à faire pour l'instant.
 */
class ProfileController extends Controller
{
    public function show(Request $request): View
    {
        return view('user.profile', [
            'user' => $request->user(),
        ]);
    }

    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated())->save();

        return redirect()->route('user.profile')
            ->with('status', 'Profil mis à jour.');
    }

    public function updatePassword(PasswordUpdateRequest $request): RedirectResponse
    {
        $request->user()->update([
            'password' => $request->validated()['password'],
        ]);

        return redirect()->route('user.profile')
            ->with('status', 'Mot de passe mis à jour.');
    }

    public function destroy(AccountDeleteRequest $request): RedirectResponse
    {
        $user = $request->user();

        Auth::logout();
        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home')
            ->with('status', 'Ton compte a été supprimé.');
    }
}
