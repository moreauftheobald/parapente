<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Services\Settings;

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
     * Cache Redis 10 min des résultats SUCCÈS uniquement. Une réponse en
     * erreur ne doit jamais être cachée : sinon une hoquette ponctuelle
     * du sidecar bloque la carte pendant 10 min même quand il va déjà mieux.
     */
    public function manifestIndex(Settings $settings): JsonResponse
    {
        $cacheKey = 'weather-map.index.v2';
        $payload  = Cache::get($cacheKey);

        if ($payload === null) {
            $base    = rtrim(config('services.consensus_grid.base_url'), '/');
            $timeout = (int) config('services.consensus_grid.timeout', 10);

            try {
                $resp = Http::timeout($timeout)->acceptJson()->get("{$base}/v1/overlay");
            } catch (\Throwable $e) {
                Log::warning('consensus-grid index unreachable', ['error' => $e->getMessage()]);
                return response()->json(['error' => 'sidecar_unreachable'], 502);
            }

            if (! $resp->ok()) {
                return response()->json(['error' => 'sidecar_http_'.$resp->status()], 502);
            }

            $payload = $resp->json();
            if (! is_array($payload)) {
                return response()->json(['error' => 'sidecar_invalid_json'], 502);
            }

            $allowed = $this->tileEnabledVariables($settings);
            if (! empty($allowed) && isset($payload['variables'])) {
                $payload['variables'] = array_values(array_filter(
                    $payload['variables'],
                    fn (array $v) => in_array($v['name'] ?? '', $allowed, true),
                ));
            }

            Cache::put($cacheKey, $payload, now()->addMinutes(10));
        }

        return response()->json($payload);
    }

    private function tileEnabledVariables(Settings $settings): array
    {
        $all     = $settings->all();
        $allowed = [];

        foreach ($all as $key => $value) {
            if (! str_starts_with($key, 'consensus.config.')) {
                continue;
            }
            if (is_array($value) && ! empty($value['render_tiles'])) {
                $allowed[] = str_replace('consensus.config.', '', $key);
            }
        }

        return $allowed;
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

    /**
     * Proxy de `/progress` — état du run consensus en cours.
     *
     * Aucun cache : le payload change en continu pendant un run actif
     * (chunks, variable courante, elapsed_s). Le front poll toutes les
     * 30 s pour afficher un badge "run en cours".
     */
    public function progress(): JsonResponse
    {
        $base = rtrim(config('services.consensus_grid.base_url'), '/');

        try {
            $resp = Http::timeout(3)->acceptJson()->get("{$base}/progress");
        } catch (\Throwable $e) {
            return response()->json(['status' => 'unreachable'], 200);
        }

        if (! $resp->ok()) {
            return response()->json(['status' => 'http_'.$resp->status()], 200);
        }

        return response()->json($resp->json() ?? ['status' => 'idle']);
    }
}
