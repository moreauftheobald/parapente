<?php

declare(strict_types=1);

namespace App\Services\Geocoding;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reverse geocoding via la Base Adresse Nationale (api-adresse.data.gouv.fr).
 *
 * Couvre uniquement la France (métropole + DOM). Hors France, BAN renvoie
 * généralement aucun résultat ou un score < seuil — c'est ce qui permet à
 * l'orchestrateur Hybrid de basculer vers Nominatim de manière fiable
 * sans heuristique géométrique.
 *
 * Endpoints utilisés :
 *  - GET  /reverse/?lat=&lon=     → single point, GeoJSON FeatureCollection
 *  - POST /reverse/csv/           → batch multipart CSV, sans rate limit notable
 */
class BanReverseGeocoder implements ReverseGeocoderInterface
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $baseUrl,
        private readonly float $minScore,
        private readonly int $timeout,
    ) {
    }

    public function reverse(float $lat, float $lng): ?LocationResult
    {
        try {
            $response = $this->http
                ->timeout($this->timeout)
                ->get($this->baseUrl . '/reverse/', [
                    'lat' => $lat,
                    'lon' => $lng,
                ]);

            if (! $response->successful()) {
                return null;
            }

            $features = $response->json('features') ?? [];
            if ($features === []) {
                return null;
            }

            return $this->buildFromFeature($features[0]);
        } catch (Throwable $e) {
            Log::warning('BAN reverse single-point failed', [
                'lat' => $lat, 'lng' => $lng, 'err' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Reverse geocoding en lot via le endpoint CSV.
     *
     * @param  array<int,array{lat:float,lng:float,key:int|string}> $points
     * @return array<int|string,LocationResult> indexé par la `key` fournie pour chaque point matché
     */
    public function reverseBatch(array $points): array
    {
        if ($points === []) {
            return [];
        }

        // Construit le CSV en mémoire (colonnes : key,latitude,longitude)
        $csv = "key,latitude,longitude\n";
        foreach ($points as $p) {
            $csv .= sprintf("%s,%.7f,%.7f\n", (string) $p['key'], $p['lat'], $p['lng']);
        }

        try {
            $response = $this->http
                ->timeout($this->timeout * 2)
                ->asMultipart()
                ->attach('data', $csv, 'reverse.csv', ['Content-Type' => 'text/csv'])
                ->post($this->baseUrl . '/reverse/csv/', [
                    ['name' => 'columns', 'contents' => 'latitude'],
                    ['name' => 'columns', 'contents' => 'longitude'],
                ]);

            if (! $response->successful()) {
                Log::warning('BAN reverse batch HTTP error', [
                    'status' => $response->status(),
                    'body' => substr((string) $response->body(), 0, 200),
                ]);
                return [];
            }

            return $this->parseBatchCsv($response->body());
        } catch (Throwable $e) {
            Log::warning('BAN reverse batch failed', ['err' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Parse la réponse CSV de l'API BAN reverse batch.
     *
     * @return array<int|string,LocationResult>
     */
    private function parseBatchCsv(string $csv): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($csv));
        if ($lines === false || count($lines) < 2) {
            return [];
        }

        $header = str_getcsv((string) array_shift($lines));
        $idx = array_flip($header);

        $required = ['key', 'result_score', 'result_context'];
        foreach ($required as $col) {
            if (! isset($idx[$col])) {
                Log::warning('BAN CSV missing column', ['column' => $col, 'header' => $header]);
                return [];
            }
        }

        $out = [];
        foreach ($lines as $line) {
            if (trim($line) === '') continue;
            $row = str_getcsv($line);
            if (count($row) < count($header)) continue;

            $key   = $row[$idx['key']];
            $score = (float) ($row[$idx['result_score']] ?? 0);
            $ctx   = (string) ($row[$idx['result_context']] ?? '');

            if ($score < $this->minScore || $ctx === '') {
                continue;
            }

            [$adminRegion, $department] = $this->splitContext($ctx);

            $out[$key] = new LocationResult(
                countryCode: 'FR',
                country: 'France',
                adminRegion: $adminRegion,
                department: $department,
                provider: 'ban',
                score: $score,
            );
        }

        return $out;
    }

    /**
     * Découpe un `result_context` BAN ("57, Moselle, Grand Est") en
     * (admin_region, department). Le code département est ignoré (on
     * garde le nom complet).
     *
     * @return array{0:?string,1:?string} [adminRegion, department]
     */
    private function splitContext(string $context): array
    {
        $parts = array_values(array_filter(array_map('trim', explode(',', $context)), fn ($p) => $p !== ''));
        // Format BAN standard : [code_dept, nom_dept, nom_region]
        // Ex: ["57", "Moselle", "Grand Est"] ; pour Paris : ["75", "Paris", "Île-de-France"]
        $department  = $parts[1] ?? null;
        $adminRegion = $parts[2] ?? null;
        return [$adminRegion, $department];
    }

    private function buildFromFeature(array $feature): ?LocationResult
    {
        $props = $feature['properties'] ?? [];
        $score = (float) ($props['score'] ?? 0);
        $ctx   = (string) ($props['context'] ?? '');

        if ($score < $this->minScore || $ctx === '') {
            return null;
        }

        [$adminRegion, $department] = $this->splitContext($ctx);

        return new LocationResult(
            countryCode: 'FR',
            country: 'France',
            adminRegion: $adminRegion,
            department: $department,
            provider: 'ban',
            score: $score,
        );
    }
}
