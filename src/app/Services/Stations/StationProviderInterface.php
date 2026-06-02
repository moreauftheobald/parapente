<?php

declare(strict_types=1);

namespace App\Services\Stations;

/**
 * Contrat commun pour les fournisseurs de stations météo (Météo-France,
 * METAR, Infoclimat). Chaque réseau implémente cette interface.
 *
 * Convention de retour :
 * - discoverStations() : liste de DTO normalisés
 * - fetchLatestReadings() : map external_id => observation
 *
 * Toutes les valeurs météo doivent être normalisées :
 * - direction du vent : convention FROM (météo standard, 0° = Nord)
 * - vitesse : km/h
 * - température : °C
 * - humidité : %
 * - pression : hPa
 * - précipitations : mm
 * - visibilité : m
 */
interface StationProviderInterface
{
    public function network(): string;

    /**
     * @return array<int, array{
     *     external_id: string,
     *     name: string,
     *     latitude: float,
     *     longitude: float,
     *     altitude_m: ?int,
     * }>
     */
    public function discoverStations(
        float $latMin,
        float $latMax,
        float $lngMin,
        float $lngMax
    ): array;

    /**
     * @return array<string, array{
     *     observed_at: \Carbon\Carbon,
     *     wind_direction: ?int,
     *     wind_speed_avg: ?float,
     *     wind_speed_max: ?float,
     *     temperature: ?float,
     *     humidity: ?int,
     *     pressure_hpa: ?float,
     *     precipitation_mm: ?float,
     *     cloud_cover_pct: ?int,
     *     visibility_m: ?int,
     *     dew_point: ?float,
     *     raw_data: ?array,
     * }>
     */
    public function fetchLatestReadings(): array;
}
