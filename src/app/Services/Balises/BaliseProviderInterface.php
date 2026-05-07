<?php

declare(strict_types=1);

namespace App\Services\Balises;

/**
 * Contrat commun pour les fournisseurs de balises (PiouPiou, FFVL,
 * Holfuy, METAR…). Chaque source implémente cette interface.
 *
 * Convention de retour :
 * - discoverStations() : liste de DTO normalisés [external_id, name,
 *                       latitude, longitude, altitude_m]
 * - fetchLatestReadings() : map external_id => [read_at, wind_direction,
 *                          wind_speed_avg, wind_speed_min, wind_speed_max,
 *                          temperature, humidity]
 *
 * Toutes les valeurs météo doivent être normalisées en SI Parapente :
 * - direction du vent : convention FROM (météo standard, 0° = vent du
 *   Nord)
 * - vitesse : km/h
 * - température : °C
 * - humidité : %
 */
interface BaliseProviderInterface
{
    /**
     * Identifiant de la source ('pioupiou', 'holfuy', 'metar'…).
     * Cette valeur est stockée dans balises.source.
     */
    public function source(): string;

    /**
     * Découvre les stations actives dans une bbox géographique.
     *
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
     * Récupère les dernières lectures de toutes les stations connues
     * du fournisseur.
     *
     * @return array<string, array{
     *     read_at: \Carbon\Carbon,
     *     wind_direction: ?int,
     *     wind_speed_avg: ?float,
     *     wind_speed_min: ?float,
     *     wind_speed_max: ?float,
     *     temperature: ?float,
     *     humidity: ?int,
     * }>
     */
    public function fetchLatestReadings(): array;
}
