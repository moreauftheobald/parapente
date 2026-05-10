<?php

declare(strict_types=1);

namespace App\Services\Weather\Apis;

use App\Models\Site;
use App\Models\WeatherApi;
use App\Models\WeatherModel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Météo-France API — AROME 1.3km/2.5km, ARPEGE 0.1°/0.25°.
 *
 * Authentification : OAuth2 client_credentials.
 *   - POST /token avec HTTP Basic auth (base64(client_id:client_secret))
 *   - Body form: grant_type=client_credentials
 *   - Réponse JSON: access_token (JWT), expires_in (3600s), token_type
 *
 * Spécificité MF : les credentials sont **par souscription d'API**,
 * donc stockés sur weather_models (pas sur weather_apis).
 *
 * Données : WCS / OGC Coverage retournant du GRIB2.
 *   - PR2 (cette implémentation) : OAuth + probe GetCapabilities pour
 *     valider credentials/endpoint. Retourne [] (parsing GRIB en PR3).
 *   - PR3 : GetCoverage par variable + extraction point via eccodes.
 */
class MeteoFranceApi implements WeatherApiInterface
{
    private const TOKEN_URL = 'https://portail-api.meteofrance.fr/token';
    private const TOKEN_REFRESH_MARGIN_S = 300;

    private ?WeatherApi $config = null;

    public function code(): string
    {
        return 'meteofrance';
    }

    public function setConfig(WeatherApi $config): void
    {
        $this->config = $config;
    }

    public function supportedModelCodes(): array
    {
        return [
            'meteofrance_arome_france',
            'meteofrance_arpege_europe',
        ];
    }

    public function fetchForSiteAndModel(Site $site, WeatherModel $model): array
    {
        if (empty($model->oauth_client_id) || empty($model->oauth_client_secret)) {
            $msg = "Credentials OAuth2 manquants pour {$model->code} (configurer client_id/secret dans l'admin du modèle).";
            Log::warning($msg);
            $this->config?->recordError($msg);
            return [];
        }
        if (empty($model->endpoint_url)) {
            $msg = "Endpoint URL manquant pour {$model->code} (renseigner l'URL de souscription dans l'admin).";
            Log::warning($msg);
            $this->config?->recordError($msg);
            return [];
        }

        $token = $this->getAccessToken($model);
        if ($token === null) {
            return [];
        }

        try {
            $response = Http::timeout(20)
                ->withToken($token)
                ->get($model->endpoint_url, [
                    'service' => 'WCS',
                    'version' => '2.0.1',
                    'request' => 'GetCapabilities',
                ]);

            $this->config?->incrementRequestsToday();

            if (! $response->successful()) {
                $msg = "MF endpoint HTTP {$response->status()} (auth OK, endpoint problème). Body: "
                    . mb_substr((string) $response->body(), 0, 200);
                Log::warning($msg);
                $this->config?->recordError($msg);
                return [];
            }

            $bodySize = strlen((string) $response->body());
            $msg = "Auth OK + endpoint joignable ({$bodySize} octets reçus sur GetCapabilities). "
                . "Parsing GRIB sera implémenté en PR3.";
            Log::info('MeteoFranceApi: probe success', [
                'site'  => $site->slug,
                'model' => $model->code,
                'bytes' => $bodySize,
            ]);
            $this->config?->recordSuccess();
            $this->config?->recordError($msg);
            return [];
        } catch (\Exception $e) {
            $msg = 'MF endpoint exception: ' . $e->getMessage();
            Log::error($msg);
            $this->config?->recordError($msg);
            return [];
        }
    }

    /**
     * Récupère un access_token valide. Renouvelle automatiquement si
     * absent ou proche de l'expiration. Le token est chiffré sur
     * weather_models (cf. cast 'encrypted').
     */
    private function getAccessToken(WeatherModel $model): ?string
    {
        if (! empty($model->oauth_token)
            && $model->oauth_expires_at
            && $model->oauth_expires_at->gt(now()->addSeconds(self::TOKEN_REFRESH_MARGIN_S))
        ) {
            return $model->oauth_token;
        }

        try {
            $response = Http::timeout(15)
                ->withBasicAuth($model->oauth_client_id, $model->oauth_client_secret)
                ->asForm()
                ->post(self::TOKEN_URL, [
                    'grant_type' => 'client_credentials',
                ]);

            $this->config?->incrementRequestsToday();

            if (! $response->successful()) {
                $msg = "OAuth2 token MF: HTTP {$response->status()}. "
                    . "Vérifie client_id/secret. Body: " . mb_substr((string) $response->body(), 0, 200);
                Log::warning($msg);
                $this->config?->recordError($msg);
                return null;
            }

            $payload = $response->json();
            $token   = $payload['access_token'] ?? null;
            $ttl     = (int) ($payload['expires_in'] ?? 3600);

            if (! $token) {
                $msg = 'OAuth2 token MF: réponse sans access_token: ' . json_encode($payload);
                Log::warning($msg);
                $this->config?->recordError($msg);
                return null;
            }

            $model->oauth_token      = $token;
            $model->oauth_expires_at = now()->addSeconds($ttl);
            $model->save();

            return $token;
        } catch (\Exception $e) {
            $msg = 'OAuth2 token MF exception: ' . $e->getMessage();
            Log::error($msg);
            $this->config?->recordError($msg);
            return null;
        }
    }
}
