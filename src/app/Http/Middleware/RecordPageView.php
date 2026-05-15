<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\PageView;
use App\Services\Analytics\UserAgentParser;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Journalise chaque page Blade vue pour les dashboards de
 * fréquentation (/admin/traffic).
 *
 * Branché sur le groupe `web` (cf. bootstrap/app.php) — donc :
 *  - aucun appel /api/* n'est compté (JSON internes)
 *  - aucun asset (servi par Nginx, pas par PHP)
 *
 * Le middleware s'exécute APRÈS la réponse pour pouvoir tenir
 * compte du status_code (on ne compte pas les 4xx/5xx comme
 * « visite »).
 *
 * Conformité RGPD :
 *  - aucun cookie posé par ce middleware
 *  - aucune donnée personnelle stockée brute
 *  - le visitor_hash mélange ip + ua + un sel quotidien
 *    `date('Y-m-d') . APP_KEY` → le hash change à minuit, on ne
 *    peut donc pas suivre un visiteur d'un jour à l'autre
 */
class RecordPageView
{
    public function __construct(private readonly UserAgentParser $uaParser) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        try {
            $this->recordIfApplicable($request, $response);
        } catch (\Throwable $e) {
            // On ne casse JAMAIS la requête à cause de l'analytics.
            Log::warning('RecordPageView failed', ['err' => $e->getMessage()]);
        }

        return $response;
    }

    private function recordIfApplicable(Request $request, Response $response): void
    {
        // 1. Méthodes navigationnelles uniquement
        if (! in_array($request->method(), ['GET', 'HEAD'], true)) return;

        // 2. Code statut "visite réussie"
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 400) return;

        // 3. Skip endpoints inutiles aux stats
        $path = '/' . ltrim($request->path(), '/');
        if ($this->shouldSkipPath($path)) return;

        // 4. Skip health check / robots.txt / favicon
        if (in_array($path, ['/up', '/robots.txt', '/favicon.ico', '/favicon.svg'], true)) return;

        // 5. Skip si AJAX/XHR (popups, fragments) — on veut des pages
        if ($request->ajax() || $request->wantsJson()) return;

        $ua     = (string) $request->userAgent();
        $parsed = $this->uaParser->parse($ua);

        PageView::create([
            'visited_at'   => now(),
            'visitor_hash' => $this->visitorHash($request, $ua),
            'user_id'      => $request->user()?->id,
            'path'         => substr($path, 0, 255),
            'method'       => $request->method(),
            'status_code'  => $status,
            'device_type'  => $parsed['device_type'],
            'os'           => $parsed['os'],
            'browser'      => $parsed['browser'],
            'referer_host' => $this->refererHost($request),
        ]);
    }

    /**
     * Hash visiteur à sel quotidien : SHA-256 ( ip | UA | YYYY-MM-DD | APP_KEY ).
     * Donne la même valeur pour un même visiteur sur la même journée
     * (→ unique visitors/jour calculable), mais bascule à minuit
     * (→ pas de suivi inter-jour, pas de PII persistante).
     */
    private function visitorHash(Request $request, string $ua): string
    {
        $ip   = (string) $request->ip();
        $day  = now()->format('Y-m-d');
        $salt = (string) config('app.key');
        return hash('sha256', $ip . '|' . $ua . '|' . $day . '|' . $salt);
    }

    private function refererHost(Request $request): ?string
    {
        $ref = $request->headers->get('referer');
        if (! $ref) return null;
        $host = parse_url($ref, PHP_URL_HOST);
        if (! $host) return null;
        // Skip self-referers (navigation interne au site)
        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        if ($appHost && strcasecmp($host, $appHost) === 0) return null;
        return substr($host, 0, 191);
    }

    private function shouldSkipPath(string $path): bool
    {
        // Préfixes à ignorer
        foreach (['/livewire', '/_debugbar', '/_ignition', '/telescope', '/horizon', '/storage/'] as $prefix) {
            if (str_starts_with($path, $prefix)) return true;
        }
        // L'admin lui-même ne nous intéresse pas pour la fréquentation
        // publique. Ça reste utile pour mesurer l'activité admin —
        // mais on l'écarte par défaut pour ne pas fausser les KPI.
        if (str_starts_with($path, '/admin')) return true;
        return false;
    }
}
