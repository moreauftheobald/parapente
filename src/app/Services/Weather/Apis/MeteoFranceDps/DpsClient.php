<?php

declare(strict_types=1);

namespace App\Services\Weather\Apis\MeteoFranceDps;

use App\Models\WeatherApi;
use App\Models\WeatherModel;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Client HTTP pour l'API DPS Paquets de Météo-France.
 *
 * Flux 2-étapes :
 *  1. fetchPackageManifest : GET .../grids/{grid}/packages/{pkg}?referencetime=
 *     → JSON avec la liste des échéances dispos sur ce run et l'URL
 *     productARO/productARP de chaque téléchargement.
 *  2. ensureGribFromHref : GET sur l'URL productAR* (avec ?time=EEHFFH&
 *     format=grib2), sauvegardé en cache local.
 *
 * Cache : un GRIB par (model, run, grid, package, echeance) — partagé
 * entre tous les sites du Grand Est via GribCache.
 */
final class DpsClient
{
    private const HTTP_TIMEOUT = 120;

    private ?string $accessToken = null;

    public function __construct(
        private readonly GribCache $cache,
    ) {}

    public function setAccessToken(string $token): void
    {
        $this->accessToken = $token;
    }

    /**
     * Récupère la liste des échéances disponibles sur un run pour un
     * paquet donné. Renvoie un tableau de descripteurs :
     *   [['time' => '00H06H', 'href' => 'https://.../productARO?...'], ...]
     *
     * @return list<array{time:string, href:string}>
     */
    public function fetchPackageManifest(
        WeatherModel $model,
        WeatherApi $apiConfig,
        CarbonImmutable $run,
        string $grid,
        string $package,
    ): array {
        if ($this->accessToken === null) {
            throw new RuntimeException('DpsClient: access token absent (oublié setAccessToken).');
        }

        $url = $this->buildManifestUrl($model, $run, $grid, $package);

        try {
            $response = Http::timeout(30)
                ->withToken($this->accessToken)
                ->acceptJson()
                ->get($url);
        } catch (RequestException $e) {
            throw new RuntimeException('MF DPS manifest failed: ' . $e->getMessage(), previous: $e);
        }
        $apiConfig->incrementRequestsToday();

        if (! $response->successful()) {
            throw new RuntimeException(sprintf(
                'MF DPS manifest HTTP %d (grid=%s, package=%s, run=%s). URL=%s. Body=%s',
                $response->status(),
                $grid,
                $package,
                $run->format('Y-m-d H:i'),
                $url,
                mb_substr((string) $response->body(), 0, 400),
            ));
        }

        $payload = $response->json();
        if (! is_array($payload) || ! isset($payload['links']) || ! is_array($payload['links'])) {
            throw new RuntimeException('MF DPS manifest: payload sans `links`.');
        }

        $entries = [];
        foreach ($payload['links'] as $link) {
            if (! is_array($link)) {
                continue;
            }
            $rel = $link['rel'] ?? '';
            if ($rel === 'self') {
                continue;
            }
            $href = $link['href'] ?? null;
            $time = $link['time'] ?? null;
            if (! is_string($href) || ! is_string($time) || $time === '') {
                continue;
            }
            $entries[] = ['time' => $time, 'href' => $href];
        }

        if (empty($entries)) {
            throw new RuntimeException(sprintf(
                'MF DPS manifest: aucune échéance trouvée (grid=%s, package=%s, run=%s).',
                $grid,
                $package,
                $run->format('Y-m-d H:i'),
            ));
        }

        return $entries;
    }

    /**
     * Garantit le GRIB local pour (run, grid, package, echeance). Utilise
     * directement l'URL fournie par le manifest (qui inclut tous les
     * query params nécessaires : referencetime, time, format).
     */
    public function ensureGribFromHref(
        WeatherModel $model,
        WeatherApi $apiConfig,
        CarbonImmutable $run,
        string $grid,
        string $package,
        string $echeance,
        string $href,
    ): string {
        $runKey = RunResolver::formatForPath($run);

        return $this->cache->ensure(
            $model->code,
            [$runKey, $grid, $package, $echeance],
            function (string $tmpPath) use ($apiConfig, $href, $grid, $package, $echeance): void {
                $this->downloadTo($href, $tmpPath, $apiConfig, $grid, $package, $echeance);
            },
        );
    }

    public function buildManifestUrl(
        WeatherModel $model,
        CarbonImmutable $run,
        string $grid,
        string $package,
    ): string {
        $base = $this->normalizeBase($model->endpoint_url);
        if ($base === null) {
            throw new RuntimeException(
                "Endpoint URL invalide pour {$model->code} (attendu .../models/AROME ou .../models/ARPEGE) : {$model->endpoint_url}"
            );
        }
        return sprintf(
            '%s/grids/%s/packages/%s?referencetime=%s',
            $base,
            rawurlencode($grid),
            rawurlencode($package),
            rawurlencode(RunResolver::formatForDps($run)),
        );
    }

    private function downloadTo(
        string $url,
        string $tmpPath,
        WeatherApi $apiConfig,
        string $grid,
        string $package,
        string $echeance,
    ): void {
        if ($this->accessToken === null) {
            throw new RuntimeException('DpsClient: access token absent (oublié setAccessToken).');
        }

        try {
            $response = Http::timeout(self::HTTP_TIMEOUT)
                ->withToken($this->accessToken)
                ->withOptions(['sink' => $tmpPath])
                ->get($url);

            $apiConfig->incrementRequestsToday();

            if (! $response->successful()) {
                $body = '';
                if (is_file($tmpPath)) {
                    $body = (string) @file_get_contents($tmpPath, false, null, 0, 600);
                    @unlink($tmpPath);
                }
                throw new RuntimeException(sprintf(
                    'MF DPS productAR* HTTP %d (grid=%s, package=%s, time=%s). URL=%s. Body=%s',
                    $response->status(),
                    $grid,
                    $package,
                    $echeance,
                    $url,
                    mb_substr($body, 0, 400),
                ));
            }

            $head = (string) @file_get_contents($tmpPath, false, null, 0, 4);
            if ($head !== 'GRIB') {
                $body = (string) @file_get_contents($tmpPath, false, null, 0, 200);
                @unlink($tmpPath);
                throw new RuntimeException(sprintf(
                    'MF DPS: réponse non-GRIB (grid=%s, package=%s, time=%s). Head: %s. Excerpt: %s',
                    $grid,
                    $package,
                    $echeance,
                    bin2hex(substr($head, 0, 16)),
                    mb_substr($body, 0, 300),
                ));
            }

            Log::info('MeteoFranceDps: GRIB downloaded', [
                'grid'     => $grid,
                'package'  => $package,
                'echeance' => $echeance,
                'bytes'    => filesize($tmpPath),
            ]);
        } catch (RequestException $e) {
            @unlink($tmpPath);
            throw new RuntimeException('MF DPS download failed: ' . $e->getMessage(), previous: $e);
        }
    }

    /**
     * Normalise l'endpoint admin. La base attendue se termine par
     * `/models/AROME` ou `/models/ARPEGE`. Tolère des slashs finaux
     * ou un suffixe /grids ou /grids/X laissé par copier-coller.
     */
    private function normalizeBase(?string $endpoint): ?string
    {
        if ($endpoint === null || trim($endpoint) === '') {
            return null;
        }
        $base = strtok($endpoint, '?');
        $base = rtrim((string) $base, '/');

        $base = preg_replace('#/grids(/.*)?$#', '', $base) ?? $base;

        if (! preg_match('#/models/[A-Z]+$#', $base)) {
            return null;
        }
        return $base;
    }
}
