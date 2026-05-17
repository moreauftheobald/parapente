<?php

declare(strict_types=1);

namespace App\Services\Geocoding;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reverse geocoding via Nominatim (OpenStreetMap public).
 *
 * Couvre tous les pays. Acceptable Use Policy stricte :
 *  - User-Agent identifié obligatoire
 *  - 1 requête par seconde maximum
 *  - Pas de "heavy bulk usage" (on respecte le rate limit, on n'enchaîne
 *    pas des centaines d'appels sans pause)
 *
 * Le rate limit est appliqué via `Cache::lock()` : un lock atomique de
 * `rate_limit_seconds` secondes garantit qu'aucun autre processus (web,
 * worker, console) ne lance un appel concurrent. Multi-worker safe.
 */
class NominatimReverseGeocoder implements ReverseGeocoderInterface
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly CacheRepository $cache,
        private readonly string $baseUrl,
        private readonly string $userAgent,
        private readonly string $contactEmail,
        private readonly int $timeout,
        private readonly int $rateLimitSeconds,
    ) {
    }

    public function reverse(float $lat, float $lng): ?LocationResult
    {
        $this->waitForRateLimit();

        try {
            $response = $this->http
                ->timeout($this->timeout)
                ->withHeaders([
                    'User-Agent' => $this->buildUserAgent(),
                    'Accept-Language' => 'fr',
                ])
                ->get($this->baseUrl . '/reverse', [
                    'lat' => $lat,
                    'lon' => $lng,
                    'format' => 'jsonv2',
                    'accept-language' => 'fr',
                    'zoom' => 10, // niveau département/county, suffisant
                ]);

            if (! $response->successful()) {
                Log::warning('Nominatim reverse HTTP error', [
                    'status' => $response->status(), 'lat' => $lat, 'lng' => $lng,
                ]);
                return null;
            }

            return $this->buildFromJson($response->json() ?? []);
        } catch (Throwable $e) {
            Log::warning('Nominatim reverse failed', [
                'lat' => $lat, 'lng' => $lng, 'err' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Bloque jusqu'à ce que le quota global (1 req/s par défaut) soit
     * respecté. Utilise un lock Redis/Cache atomique pour la
     * coordination multi-worker.
     */
    private function waitForRateLimit(): void
    {
        // Lock TTL = rate_limit_seconds : tant qu'un appel a été fait dans
        // cette fenêtre, on attend que le lock expire.
        $lock = $this->cache->lock('geocoding.nominatim.rate', $this->rateLimitSeconds);
        $lock->block(60); // max 60 s d'attente — au-delà, on lève (anomalie)
        // Note : on ne relâche PAS le lock — il expire naturellement après
        // `rate_limit_seconds` secondes, ce qui crée la pause inter-appels.
    }

    private function buildUserAgent(): string
    {
        $ua = $this->userAgent;
        if ($this->contactEmail !== '') {
            $ua .= ' (' . $this->contactEmail . ')';
        }
        return $ua;
    }

    /**
     * Construit un LocationResult depuis la réponse JSON Nominatim
     * (format jsonv2). Retourne null si le pays n'est pas identifié.
     */
    private function buildFromJson(array $json): ?LocationResult
    {
        $address = $json['address'] ?? [];
        $countryCode = strtoupper((string) ($address['country_code'] ?? ''));
        if ($countryCode === '') {
            return null;
        }

        $country = $this->trimOrNull($address['country'] ?? null);
        $adminRegion = $this->normalizeAdminRegion($address);
        $department = $this->extractDepartment($address);

        $importance = isset($json['importance']) ? (float) $json['importance'] : null;

        return new LocationResult(
            countryCode: $countryCode,
            country: $country,
            adminRegion: $adminRegion,
            department: $department,
            provider: 'nominatim',
            score: $importance,
        );
    }

    private function normalizeAdminRegion(array $address): ?string
    {
        // Pour la France via Nominatim FR, c'est généralement `state`.
        // Belgique : `state` = "Région wallonne" / "Région flamande" / "Bruxelles-Capitale".
        // Allemagne : `state` = Bundesland (Sarre, Rhénanie-Palatinat).
        // Luxembourg : `state` = canton (parfois absent).
        $val = $this->trimOrNull($address['state'] ?? null)
            ?? $this->trimOrNull($address['region'] ?? null);
        if ($val === null) return null;

        // Retire les préfixes redondants pour normaliser avec BAN ("Grand Est"
        // côté BAN vs "Région Grand Est" parfois côté Nominatim).
        return preg_replace('/^(Région|Region)\s+/u', '', $val) ?: $val;
    }

    private function extractDepartment(array $address): ?string
    {
        // Ordre de priorité : county (FR département) → state_district →
        // (rien pour LU/DE le plus souvent).
        return $this->trimOrNull($address['county'] ?? null)
            ?? $this->trimOrNull($address['state_district'] ?? null);
    }

    private function trimOrNull(mixed $v): ?string
    {
        if (! is_string($v)) return null;
        $t = trim($v);
        return $t === '' ? null : $t;
    }
}
