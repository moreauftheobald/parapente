<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Alias pour les routes : ->middleware(['auth', 'admin'])
        $middleware->alias([
            'admin' => \App\Http\Middleware\EnsureAdmin::class,
        ]);

        // Journalisation des visites Blade pour /admin/traffic (privacy-first :
        // pas de cookie, hash visiteur à sel quotidien). N'agit que sur le
        // groupe `web` ; les /api/* et /admin/* sont écartés dans le
        // middleware lui-même.
        $middleware->web(append: [
            \App\Http\Middleware\RecordPageView::class,
        ]);

        // Les routes /api reposent sur le même cookie de session que le
        // front Blade (pas de Sanctum). On ajoute donc les middlewares
        // session + cookies + CSRF au groupe `api` pour que `auth:web`,
        // `auth()->user()` et le token CSRF méta fonctionnent depuis le
        // JS de la SPA Alpine. Les GET publics (/api/sites, /api/balises)
        // restent fonctionnels — CSRF n'agit que sur POST/PATCH/DELETE.
        $middleware->api(prepend: [
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class,
        ]);

        // Redirection des invités sur le login front. Les routes /admin
        // restent atteignables directement via /admin/login ; EnsureAdmin
        // se charge d'éconduire les non-admins une fois authentifiés.
        $middleware->redirectGuestsTo(fn () => route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
