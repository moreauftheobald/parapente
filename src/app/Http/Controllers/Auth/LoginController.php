<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Authentification front (utilisateurs « user » et « admin »).
 *
 * Distinct de Admin\AuthController qui reste réservé au flux /admin/login
 * (login restreint aux admins). Ici on accepte tout rôle : un admin qui
 * passe par /connexion est connecté normalement et redirigé vers le profil.
 */
class LoginController extends Controller
{
    /**
     * Chemins d'auth/admin à ne jamais utiliser comme cible de redirection
     * après login (sinon on renvoie l'utilisateur sur la page de connexion).
     */
    private const AUTH_PATHS = ['/connexion', '/inscription', '/deconnexion'];

    public function showForm(Request $request): View
    {
        // Mémorise la page d'où vient l'utilisateur pour y revenir
        // après la connexion (en complément d'`intended()` posé par le
        // middleware `auth` quand il intercepte un accès protégé).
        $this->rememberPreviousUrl($request);

        return view('auth.login');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $credentials = $request->only(['email', 'password']);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => __('Email ou mot de passe incorrect.'),
            ]);
        }

        $request->session()->regenerate();

        return redirect()->intended($this->fallbackDestination($request));
    }

    public function destroy(Request $request): RedirectResponse
    {
        $referer = $this->safeReferer($request);

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->to($referer ?? route('home'));
    }

    /**
     * Stocke `url.intended` à partir du Referer (formulaire inline dans la
     * navbar) ou de la page précédente (accès direct à /connexion).
     */
    private function rememberPreviousUrl(Request $request): void
    {
        if ($request->session()->has('url.intended')) {
            return;
        }

        $candidate = $this->safeReferer($request) ?? $this->safeUrl(url()->previous());
        if ($candidate !== null) {
            $request->session()->put('url.intended', $candidate);
        }
    }

    /**
     * Cible de fallback quand aucune `url.intended` n'est définie :
     * Referer si sain, sinon l'accueil.
     */
    private function fallbackDestination(Request $request): string
    {
        return $this->safeReferer($request) ?? route('home');
    }

    private function safeReferer(Request $request): ?string
    {
        return $this->safeUrl($request->headers->get('referer'));
    }

    /**
     * Retourne l'URL si elle pointe vers la même origine et n'est pas
     * une URL d'auth, sinon null.
     */
    private function safeUrl(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        $parsed   = parse_url($url);
        $host     = $parsed['host'] ?? null;
        $appHost  = parse_url((string) config('app.url'), PHP_URL_HOST);

        if ($host !== null && $appHost !== null && $host !== $appHost) {
            return null;
        }

        $path = $parsed['path'] ?? '/';
        if (in_array($path, self::AUTH_PATHS, true)) {
            return null;
        }

        return $url;
    }
}
