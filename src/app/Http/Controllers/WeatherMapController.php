<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Carte météo — vue Leaflet avec overlays issus du sidecar `consensus-grid`.
 *
 * Le sidecar (FastAPI) calcule un consensus multi-modèles pré-cuit toutes
 * les heures et expose :
 *   - GET /v1/overlay                       → index JSON (run_init, bbox, steps, variables + palettes)
 *   - GET /v1/overlay/{variable}            → manifest JSON d'une variable
 *   - GET /v1/overlay/{variable}/{step}.png → PNG RGBA (step = entier d'heures depuis run_init)
 *   - GET /v1/forecast                      → équivalent Open-Meteo
 *   - GET /health, /progress
 *
 * Le front fait un unique appel /carte-meteo/index puis tape les PNG via
 * /carte-meteo/overlay/{variable}/{step}.png. Tout passe par Laravel —
 * ça permet de cacher, contrôler les accès, et évite d'exposer le port
 * 8082 publiquement.
 */
class WeatherMapController extends Controller
{
    /**
     * Validation des noms de variables (whitelist regex plutôt que liste
     * en dur — comme ça les nouvelles variables ajoutées au sidecar
     * apparaissent automatiquement dans l'UI sans déploiement Laravel).
     */
    private const VARIABLE_PATTERN = '/^[a-z][a-z0-9_]{1,63}$/';

    public function index()
    {
        return view('weather-map.index');
    }

    /**
     * Proxy de `/v1/overlay` — manifest global du sidecar.
     *
     * Cache Redis 10 min : le run du sidecar n'est mis à jour qu'une fois
     * par heure, donc 10 min absorbe les rafales sans hammeriser.
     */
    public function manifestIndex(): JsonResponse
    {
        $payload = Cache::remember('weather-map.index.v1', now()->addMinutes(10), function (): array {
            $base    = rtrim(config('services.consensus_grid.base_url'), '/');
            $timeout = (int) config('services.consensus_grid.timeout', 10);

            try {
                $resp = Http::timeout($timeout)->acceptJson()->get("{$base}/v1/overlay");
            } catch (\Throwable $e) {
                Log::warning('consensus-grid index unreachable', ['error' => $e->getMessage()]);
                return ['_error' => 'sidecar_unreachable'];
            }

            if (! $resp->ok()) {
                return ['_error' => 'sidecar_http_'.$resp->status()];
            }

            return $resp->json() ?? ['_error' => 'sidecar_invalid_json'];
        });

        if (isset($payload['_error'])) {
            return response()->json(['error' => $payload['_error']], 502);
        }

        return response()->json($payload);
    }

    /**
     * Proxy de `/v1/overlay/{variable}/{step}.png`.
     *
     * Step est un entier d'heures depuis `run_init` (0..120 typiquement).
     * Le PNG d'un run donné ne change plus jamais — on laisse le navigateur
     * cacher agressivement.
     */
    public function overlay(string $variable, string $step): Response
    {
        if (! preg_match(self::VARIABLE_PATTERN, $variable)) {
            return response('invalid variable', 400);
        }
        if (! ctype_digit($step) || strlen($step) > 4) {
            return response('invalid step', 400);
        }

        $base    = rtrim(config('services.consensus_grid.base_url'), '/');
        $timeout = (int) config('services.consensus_grid.timeout', 10);

        try {
            $resp = Http::timeout($timeout)->get("{$base}/v1/overlay/{$variable}/{$step}.png");
        } catch (\Throwable $e) {
            Log::warning('consensus-grid overlay unreachable', [
                'variable' => $variable,
                'step'     => $step,
                'error'    => $e->getMessage(),
            ]);
            return response('sidecar unreachable', 502);
        }

        if (! $resp->ok()) {
            return response('sidecar HTTP '.$resp->status(), 502);
        }

        return response($resp->body(), 200, [
            'Content-Type'  => 'image/png',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    public function health(): JsonResponse
    {
        $base = rtrim(config('services.consensus_grid.base_url'), '/');

        try {
            $resp = Http::timeout(3)->acceptJson()->get("{$base}/health");
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'reason' => 'unreachable'], 200);
        }

        return response()->json([
            'ok'      => $resp->ok(),
            'status'  => $resp->status(),
            'payload' => $resp->ok() ? $resp->json() : null,
        ]);
    }
}
