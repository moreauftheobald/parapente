<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Carte météo — vue Leaflet avec overlays issus du sidecar `consensus-grid`.
 *
 * Le sidecar calcule un consensus multi-modèles pré-cuit toutes les heures
 * et expose :
 *   - GET /v1/overlay/{variable}            → manifest JSON (steps + bounds)
 *   - GET /v1/overlay/{variable}/{step}.png → tuile PNG d'un pas de temps
 *   - GET /v1/forecast                      → équivalent Open-Meteo
 *   - GET /health, /progress
 *
 * Laravel ne contacte JAMAIS le sidecar depuis le navigateur :
 *   - manifest : proxifié (JSON court, mis en cache Redis 10 min)
 *   - PNG     : proxifié en streaming (cache HTTP côté navigateur)
 */
class WeatherMapController extends Controller
{
    /**
     * Liste des variables exposées dans la toolbar du front.
     *
     * - `kind=info`   : couche d'information (PNG colorisée — vent moyen, etc.)
     * - `kind=arrows` : surcouche de flèches de vent
     *
     * À ajuster quand le sidecar exposera de nouvelles variables.
     */
    private const VARIABLES = [
        'wind_speed_avg' => ['label' => 'Vent moyen 10 m',  'kind' => 'info',   'unit' => 'km/h'],
        'wind_gust'      => ['label' => 'Rafales 10 m',     'kind' => 'info',   'unit' => 'km/h'],
        'precipitation'  => ['label' => 'Précipitations',   'kind' => 'info',   'unit' => 'mm/h'],
        'cloud_cover'    => ['label' => 'Couverture nuageuse', 'kind' => 'info', 'unit' => '%'],
        'wind_arrows'    => ['label' => 'Flèches de vent',  'kind' => 'arrows', 'unit' => null],
    ];

    public function index()
    {
        return view('weather-map.index', [
            'variables' => self::VARIABLES,
        ]);
    }

    /**
     * Proxy du manifest `/v1/overlay/{variable}` du sidecar.
     *
     * Le manifest est court et change au plus une fois par heure (run du
     * sidecar) → cache Redis 10 min pour absorber les rafales sans
     * hammeriser le conteneur.
     */
    public function manifest(string $variable): JsonResponse
    {
        if (! isset(self::VARIABLES[$variable])) {
            return response()->json(['error' => 'unknown variable'], 404);
        }

        $key = "weather-map.manifest.v1.{$variable}";

        $payload = Cache::remember($key, now()->addMinutes(10), function () use ($variable): array {
            $base    = rtrim(config('services.consensus_grid.base_url'), '/');
            $timeout = (int) config('services.consensus_grid.timeout', 10);

            try {
                $resp = Http::timeout($timeout)
                    ->acceptJson()
                    ->get("{$base}/v1/overlay/{$variable}");
            } catch (\Throwable $e) {
                Log::warning('consensus-grid manifest unreachable', [
                    'variable' => $variable,
                    'error'    => $e->getMessage(),
                ]);
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
     * Proxy streamé de `/v1/overlay/{variable}/{step}.png`.
     *
     * Pas de cache Laravel ici : les PNG peuvent être lourds et le navigateur
     * mémorise déjà via Cache-Control. Le navigateur appelle un step précis,
     * qui change rarement (par run du sidecar) — donc cache long côté client.
     */
    public function overlay(string $variable, string $step): Response|StreamedResponse
    {
        if (! isset(self::VARIABLES[$variable])) {
            return response('unknown variable', 404);
        }
        // Le step est passé tel quel au sidecar — on contraint juste à
        // un format raisonnable pour éviter les abus (chemins, etc.).
        if (! preg_match('/^[A-Za-z0-9_\-:.]+$/', $step)) {
            return response('invalid step', 400);
        }

        $base    = rtrim(config('services.consensus_grid.base_url'), '/');
        $timeout = (int) config('services.consensus_grid.timeout', 10);

        try {
            $resp = Http::timeout($timeout)
                ->withOptions(['stream' => true])
                ->get("{$base}/v1/overlay/{$variable}/{$step}.png");
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

        $body = $resp->body();

        return response($body, 200, [
            'Content-Type'  => 'image/png',
            'Cache-Control' => 'public, max-age=600',
        ]);
    }

    /**
     * Health-check léger pour le badge d'état dans la toolbar.
     */
    public function health(): JsonResponse
    {
        $base    = rtrim(config('services.consensus_grid.base_url'), '/');
        $timeout = 3;

        try {
            $resp = Http::timeout($timeout)->acceptJson()->get("{$base}/health");
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
