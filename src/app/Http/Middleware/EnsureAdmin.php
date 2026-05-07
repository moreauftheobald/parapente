<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Réserve l'accès aux utilisateurs authentifiés ET admin.
 *
 * Usage : ->middleware(['auth', 'admin'])
 *
 * Le check 'auth' (présence d'une session) est délégué au middleware
 * Laravel standard ; ici on ne vérifie que le rôle.
 */
class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || ! $user->isAdmin()) {
            abort(403, 'Accès réservé aux administrateurs.');
        }
        return $next($request);
    }
}
