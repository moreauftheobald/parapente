<?php

declare(strict_types=1);

namespace App\Services\Balises;

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fournisseur de balises PiouPiou (réseau crowd-sourced FFVL).
 *
 * API doc : https://developers.pioupiou.fr/api/live/
 *
 * Endpoints :
 * - GET /v1/live/all              : toutes stations, mesures uniquement
 * - GET /v1/live-with-meta/all    : toutes stations + métadonnées
 *                                   (nom, description, photo, rating)
 *
 * Variables fournies : direction (FROM), vitesse min/avg/max sur 4 min
 * glissantes. Ni température ni humidité ni pression.
 *
 * Rate limit imposé par la doc : ne pas appeler 'all' plus d'une fois
 * par minute. Le polling à 10 min reste largement sous le seuil.
 */
class PiouPiouProvider implements BaliseProviderInterface
{
    private const BASE_URL  = 'https://api.pioupiou.fr/v1';
    private const TIMEOUT_S = 30;

    /** Au-delà, on considère que la station n'a pas émis récemment et on ne la retient pas à la découverte */
    private const DISCOVERY_FRESHNESS_HOURS = 24;

    public function source(): string
    {
        return 'pioupiou';
    }

    public function discoverStations(
        float $latMin,
        float $latMax,
        float $lngMin,
        float $lngMax
    ): array {
        $resp = Http::timeout(self::TIMEOUT_S)
            ->get(self::BASE_URL . '/live-with-meta/all');

        if (! $resp->ok()) {
            Log::warning('PiouPiou discovery HTTP error', [
                'status' => $resp->status(),
            ]);
            return [];
        }

        $stations = $resp->json('data', []);
        $result   = [];
        $cutoff   = now()->subHours(self::DISCOVERY_FRESHNESS_HOURS);

        foreach ($stations as $s) {
            // Position valide ?
            $loc = $s['location'] ?? null;
            if (! $loc || ! ($loc['success'] ?? false)) {
                continue;
            }
            $lat = (float) ($loc['latitude']  ?? 0);
            $lng = (float) ($loc['longitude'] ?? 0);

            // Coupure stricte sur la bbox
            if ($lat < $latMin || $lat > $latMax) continue;
            if ($lng < $lngMin || $lng > $lngMax) continue;

            // Station allumée ?
            if (($s['status']['state'] ?? null) !== 'on') {
                continue;
            }

            // A émis dans les dernières 24h ?
            $measDate = $s['measurements']['date'] ?? null;
            if (! $measDate) continue;
            try {
                $measDt = Carbon::parse($measDate);
            } catch (\Throwable) {
                continue;
            }
            if ($measDt->lt($cutoff)) continue;

            $result[] = [
                'external_id' => (string) $s['id'],
                'name'        => $s['meta']['name'] ?? "Pioupiou {$s['id']}",
                'latitude'    => $lat,
                'longitude'   => $lng,
                // PiouPiou ne fournit pas l'altitude des stations
                'altitude_m'  => null,
            ];
        }

        return $result;
    }

    public function fetchLatestReadings(): array
    {
        $resp = Http::timeout(self::TIMEOUT_S)
            ->get(self::BASE_URL . '/live/all');

        if (! $resp->ok()) {
            Log::warning('PiouPiou fetch HTTP error', [
                'status' => $resp->status(),
            ]);
            return [];
        }

        $stations = $resp->json('data', []);
        $result   = [];

        foreach ($stations as $s) {
            $m = $s['measurements'] ?? null;
            if (! $m || empty($m['date'])) {
                continue;
            }

            try {
                $readAt = Carbon::parse($m['date']);
            } catch (\Throwable) {
                continue;
            }

            $extId = (string) ($s['id'] ?? '');
            if ($extId === '') continue;

            $result[$extId] = [
                'read_at'        => $readAt,
                'wind_direction' => isset($m['wind_heading']) ? (int) round((float) $m['wind_heading']) : null,
                'wind_speed_avg' => isset($m['wind_speed_avg']) ? (float) $m['wind_speed_avg'] : null,
                'wind_speed_min' => isset($m['wind_speed_min']) ? (float) $m['wind_speed_min'] : null,
                'wind_speed_max' => isset($m['wind_speed_max']) ? (float) $m['wind_speed_max'] : null,
                'temperature'    => null,
                'humidity'       => null,
            ];
        }

        return $result;
    }
}
