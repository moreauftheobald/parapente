<?php

declare(strict_types=1);

namespace App\Services\Weather;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Client HTTP du endpoint de scoring perso du sidecar consensus-grid-v2
 * (`POST /v1/scoring/custom`).
 *
 * Le sidecar reçoit les sites (coords + conditions custom) + les seuils
 * globaux, lit son propre forecast en interne (même extraction que le
 * scoring prod) et renvoie le statut + couleurs par créneau. Cf.
 * FF_personnal_scoring_sidecar.md.
 *
 * Tolérant aux pannes : toute erreur (réseau, timeout, 4xx/5xx, JSON
 * invalide) renvoie `null` → l'appelant retombe sur le scoring global.
 */
class CustomScoringClient
{
    private string $baseUrl;
    private int $timeout;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.consensus_grid.base_url'), '/');
        $this->timeout = (int) config('services.consensus_grid.timeout', 10);
    }

    /**
     * @param  array<string,mixed> $payload  corps de requête complet
     * @return array<string,mixed>|null      réponse décodée, ou null sur échec
     */
    public function score(array $payload): ?array
    {
        try {
            $response = Http::timeout($this->timeout)
                ->acceptJson()
                ->asJson()
                ->post($this->baseUrl . '/v1/scoring/custom', $payload);
        } catch (\Throwable $e) {
            Log::warning('CustomScoringClient: appel échoué', ['error' => $e->getMessage()]);
            return null;
        }

        if (! $response->successful()) {
            Log::warning('CustomScoringClient: réponse non-2xx', [
                'status' => $response->status(),
                'body'   => mb_substr($response->body(), 0, 500),
            ]);
            return null;
        }

        $json = $response->json();

        return is_array($json) ? $json : null;
    }
}
